<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Db;
use App\Repository\MailQueueRepository;

/**
 * Announcements to everyone who applied, or to everyone on one event or one
 * session. Written for the case it is actually used for: telling people a
 * typhoon has cancelled tomorrow.
 *
 * Recipients are resolved from `bookings`, not `applicants`: a person is on
 * this list because they hold a booking, and the booking is what carries the
 * name to address them by and the event to name in the message.
 *
 * Deduplicated by e-mail address. Someone holding three bookings on an event
 * is one person and gets one message - three copies of a cancellation notice
 * is how an announcement turns into a complaint.
 *
 * Cancelled bookings are never included. They already decided not to come,
 * and telling them an event they are not attending has moved is noise at
 * best. Waitlisted bookings are included only when the operator asks: they
 * are affected by a cancellation but not by everything.
 */
final class BulkMailService
{
    public const SCOPE_ALL     = 'all';
    public const SCOPE_EVENT   = 'event';
    public const SCOPE_SESSION = 'session';

    public function __construct(
        private readonly MailQueueRepository $mailQueue = new MailQueueRepository(),
    ) {
    }

    /**
     * Who would receive this, one row per person.
     *
     * @param self::SCOPE_* $scope
     * @param int|null $companyId Restrict to one company's events. Passed for a
     *        company account so its announcement cannot reach another
     *        company's applicants; null for the office.
     * @return array<int, array{email: string, name: string}>
     */
    public function recipients(
        string $scope,
        int $targetId,
        bool $includeWaitlisted,
        ?int $companyId = null,
    ): array {
        $statuses = $includeWaitlisted ? "('confirmed','waitlisted')" : "('confirmed')";

        $where = [];
        $params = [];

        if ($scope === self::SCOPE_EVENT) {
            $where[] = 's.event_id = ?';
            $params[] = $targetId;
        } elseif ($scope === self::SCOPE_SESSION) {
            $where[] = 'b.session_id = ?';
            $params[] = $targetId;
        }

        if ($companyId !== null) {
            $where[] = 'e.company_id = ?';
            $params[] = $companyId;
        }

        $filter = $where !== [] ? 'AND ' . implode(' AND ', $where) : '';

        /*
         * One row per address. MAX(b.id) picks the person's most recent
         * booking to take the name from - an address that booked twice under
         * two spellings gets the newer one, which is the one they last told
         * us. The join back is what turns that id into the name.
         */
        return array_map(
            static fn (array $row): array => [
                'email' => (string) $row['email'],
                'name'  => (string) ($row['contact_name'] ?? ''),
            ],
            Db::select(
                "SELECT b2.email, b2.contact_name
                   FROM (
                         SELECT b.email AS addr, MAX(b.id) AS latest
                           FROM bookings b
                           JOIN event_sessions s ON s.id = b.session_id
                           JOIN events e         ON e.id = s.event_id
                          WHERE b.status IN {$statuses} {$filter}
                          GROUP BY b.email
                        ) pick
                   JOIN bookings b2 ON b2.id = pick.latest
                  ORDER BY b2.email",
                $params
            )
        );
    }

    /**
     * Context for the message header, so a recipient knows which booking the
     * announcement is about. Null for a whole-site announcement, which is
     * about no one event.
     *
     * @return array{title: string, when: string}|null
     */
    public function context(string $scope, int $targetId): ?array
    {
        if ($scope === self::SCOPE_EVENT) {
            $row = Db::selectOne(
                'SELECT e.title, c.name AS company_name,
                        MIN(s.starts_at) AS first_starts_at
                   FROM events e
                   JOIN companies c ON c.id = e.company_id
                   LEFT JOIN event_sessions s ON s.event_id = e.id
                  WHERE e.id = ?
                  GROUP BY e.title, c.name',
                [$targetId]
            );
            if ($row === null) {
                return null;
            }
            return [
                'title' => sprintf('%s（%s）', (string) $row['title'], (string) $row['company_name']),
                'when'  => $row['first_starts_at'] !== null
                    ? jp_date((string) $row['first_starts_at']) . ' 開催'
                    : '',
            ];
        }

        if ($scope === self::SCOPE_SESSION) {
            $row = Db::selectOne(
                'SELECT e.title, c.name AS company_name, s.starts_at, s.ends_at
                   FROM event_sessions s
                   JOIN events e    ON e.id = s.event_id
                   JOIN companies c ON c.id = e.company_id
                  WHERE s.id = ?',
                [$targetId]
            );
            if ($row === null) {
                return null;
            }
            return [
                'title' => sprintf('%s（%s）', (string) $row['title'], (string) $row['company_name']),
                'when'  => jp_datetime((string) $row['starts_at']) . '〜' . jp_time((string) $row['ends_at']),
            ];
        }

        return null;
    }

    /**
     * The message as one recipient will read it. Built here rather than in the
     * template so the test send and the real send cannot differ - a test that
     * proves a different message proves nothing.
     *
     * @param array{title: string, when: string}|null $context
     */
    public function compose(string $body, ?array $context): string
    {
        if ($context === null) {
            return trim($body);
        }

        $when = $context['when'] !== '' ? "日時　　　: {$context['when']}\n" : '';

        return "── 対象の体験プログラム ──────────\n"
            . "体験内容　: {$context['title']}\n"
            . $when
            . "────────────────────\n\n"
            . trim($body);
    }

    /**
     * Queue one message per recipient.
     *
     * One transaction: a campaign that enqueued half its recipients and then
     * failed would leave the operator unable to tell who had been told, and
     * re-running it would double up on the half that went.
     *
     * booking_id stays null. These messages are about a booking but not FROM
     * one, and hanging them off whichever booking the address was found
     * through would put a campaign in that booking's history.
     *
     * @param array<int, array{email: string, name: string}> $recipients
     * @return int How many were queued.
     */
    public function enqueue(array $recipients, string $subject, string $body): int
    {
        if ($recipients === []) {
            return 0;
        }

        return Db::transaction(function () use ($recipients, $subject, $body): int {
            $queued = 0;
            foreach ($recipients as $recipient) {
                $this->mailQueue->enqueue(
                    $recipient['email'],
                    $recipient['name'] !== '' ? $recipient['name'] : null,
                    $subject,
                    $body,
                    null,
                    MailQueueRepository::BULK,
                );
                $queued++;
            }
            return $queued;
        });
    }
}
