<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Config;
use App\Core\Db;
use App\Core\Settings;
use App\Domain\ScheduleClash;
use App\Repository\MailQueueRepository;

/**
 * The day-before reminder: what you booked, where, and when.
 *
 * One message per person, not per booking. Visitors here tour several
 * companies in a day, so a reminder sent per booking would arrive three times
 * and still not answer the question the reader actually has, which is what
 * their day looks like. Listing the day in time order answers it once, and
 * lets them see for themselves that two of the slots are five minutes apart.
 *
 * The facts in the body are generated and cannot be edited. Only the office's
 * own note can be. A screen that asked somebody to retype the date and the
 * venue every day would eventually send last month's date to everybody, and
 * the whole point of this feature is that it runs without anyone having to be
 * careful.
 *
 * There is no cancellation link. The raw token exists in the confirmation mail
 * and nowhere else (TokenService), so the reminder points at that mail, which
 * is what the promotion notice already does.
 */
final class ReminderService
{
    public function __construct(
        private readonly MailQueueRepository $mailQueue = new MailQueueRepository(),
        private readonly ApplicantScheduleService $schedule = new ApplicantScheduleService(),
    ) {
    }

    /**
     * Everyone who should hear from us about $date, one entry per person,
     * each carrying that person's bookings for the day in time order.
     *
     * Already-reminded people are left out, which is what makes running this
     * twice harmless.
     *
     * @param string $date Y-m-d, JST.
     * @return array<int, array{email: string, name: string, bookings: array<int, array<string, mixed>>}>
     */
    public function recipients(string $date, bool $includeWaitlisted, bool $skipAlreadySent = true): array
    {
        $statuses = $includeWaitlisted ? "('confirmed','waitlisted')" : "('confirmed')";

        $skipped = Settings::reminderSkippedSessions();
        $skipClause = '';
        $params = [$date];
        if ($skipped !== []) {
            $skipClause = 'AND b.session_id NOT IN (' . implode(',', array_fill(0, count($skipped), '?')) . ')';
            $params = array_merge($params, $skipped);
        }

        $rows = Db::select(
            "SELECT b.id, b.applicant_id, b.email, b.contact_name, b.name,
                    b.party_size, b.guardian_count, b.status, b.waitlist_seq,
                    s.id AS session_id, s.starts_at, s.ends_at,
                    e.title AS event_title, e.venue,
                    c.id AS company_id, c.name AS company_name
               FROM bookings b
               JOIN event_sessions s ON s.id = b.session_id
               JOIN events e         ON e.id = s.event_id
               JOIN companies c      ON c.id = e.company_id
              WHERE DATE(s.starts_at) = ?
                AND b.status IN {$statuses}
                {$skipClause}
              ORDER BY b.email, s.starts_at, b.id",
            $params
        );

        if ($rows === []) {
            return [];
        }

        $done = $skipAlreadySent ? $this->alreadyRemindedBookings($rows) : [];

        /** @var array<string, array{email: string, name: string, bookings: array<int, array<string, mixed>>}> $byPerson */
        $byPerson = [];
        foreach ($rows as $row) {
            $email = (string) $row['email'];
            if (!isset($byPerson[$email])) {
                $byPerson[$email] = [
                    'email' => $email,
                    // The contact, not the participant: it is their inbox.
                    'name' => (string) ($row['contact_name'] ?? $row['name']),
                    'bookings' => [],
                ];
            }
            $byPerson[$email]['bookings'][] = $row;
        }

        // Drop anyone who already has a reminder for one of the day's bookings.
        $out = [];
        foreach ($byPerson as $person) {
            $reminded = false;
            foreach ($person['bookings'] as $booking) {
                if (isset($done[(int) $booking['id']])) {
                    $reminded = true;
                    break;
                }
            }
            if (!$reminded) {
                $out[] = $person;
            }
        }

        return $out;
    }

    /**
     * Bookings that a reminder has already been queued for.
     *
     * Looked up by booking_id, which mail_queue indexes, so this is one
     * indexed read rather than a scan - and it is why the queued row is hung
     * off a booking at all (see enqueue()).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, true>
     */
    private function alreadyRemindedBookings(array $rows): array
    {
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        if ($ids === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $found = Db::select(
            "SELECT booking_id FROM mail_queue
              WHERE category = ? AND booking_id IN ({$in})",
            array_merge([MailQueueRepository::REMINDER], $ids)
        );

        $out = [];
        foreach ($found as $row) {
            $out[(int) $row['booking_id']] = true;
        }
        return $out;
    }

    public function subject(string $date): string
    {
        return '【' . Config::mailPrefix() . '】明日のご予約のご案内（' . jp_date($date) . '）';
    }

    /**
     * The message one person will read.
     *
     * @param array{email: string, name: string, bookings: array<int, array<string, mixed>>} $person
     */
    public function compose(array $person, string $date, string $notice): string
    {
        $count = count($person['bookings']);
        $lines = [];

        foreach ($person['bookings'] as $index => $booking) {
            $number = $index + 1;
            $block = sprintf(
                "【%d】%s〜%s\n  体験内容: %s\n  開催企業: %s\n",
                $number,
                jp_time((string) $booking['starts_at']),
                jp_time((string) $booking['ends_at']),
                (string) $booking['event_title'],
                (string) $booking['company_name'],
            );
            if (($booking['venue'] ?? '') !== '') {
                $block .= "  会場　　: {$booking['venue']}\n";
            }
            $block .= sprintf("  ご人数　: %d 名\n", (int) $booking['party_size']);
            if ((int) $booking['guardian_count'] > 0) {
                $block .= sprintf("  付き添い: %d 名（体験されない方）\n", (int) $booking['guardian_count']);
            }
            if ((string) $booking['status'] === 'waitlisted') {
                $block .= "  ※ この回は現在キャンセル待ち（" . (int) $booking['waitlist_seq']
                    . " 番）です。お席をご用意できた場合のみ、改めてご連絡します。\n";
            }
            $lines[] = $block;
        }

        $body = sprintf("%s 様\n\n", $person['name'])
            . sprintf("明日 %s の %s のご予約は %d 件です。\n", jp_date($date), Config::siteName(), $count)
            . "お気をつけてお越しください。\n\n"
            . "── ご予約の内容 ──────────────\n"
            . implode("\n", $lines)
            . "────────────────────────\n";

        $tight = $this->tightConnections($person['bookings']);
        if ($tight !== []) {
            $body .= "\n" . implode("\n", $tight) . "\n";
        }

        $notice = trim($notice);
        if ($notice !== '') {
            $body .= "\n── 事務局からのお知らせ ──────────\n"
                . $notice . "\n"
                . "────────────────────────\n";
        }

        $body .= "\nご予約の確認・キャンセルは、ご予約時にお送りしたメールに記載の URL から\n"
            . "行えます。ご都合が悪くなった場合は、お早めにご連絡ください。\n";

        return $body;
    }

    /**
     * A line per pair of bookings the reader may not make in time.
     *
     * The same rule the admin list colours amber, said to the person who
     * actually has to walk it.
     *
     * @param array<int, array<string, mixed>> $bookings
     * @return array<int, string>
     */
    private function tightConnections(array $bookings): array
    {
        $out = [];
        foreach ($bookings as $index => $booking) {
            $entry = $this->schedule->forRows([$booking])[(int) $booking['id']] ?? null;
            if ($entry === null) {
                continue;
            }
            foreach ($entry['others'] as $other) {
                if ($other['clash'] !== ScheduleClash::Tight) {
                    continue;
                }
                // Only forwards, so a pair is mentioned once rather than twice.
                if ((string) $other['starts_at'] <= (string) $booking['starts_at']) {
                    continue;
                }
                $position = $this->positionOf($bookings, (int) $other['id']);
                if ($position === null) {
                    continue;
                }
                $gap = (int) ((strtotime((string) $other['starts_at'])
                    - strtotime((string) $booking['ends_at'])) / 60);
                $out[] = sprintf(
                    '※【%d】と【%d】の間隔は %d 分です。移動時間にご注意ください。',
                    $index + 1,
                    $position + 1,
                    max(0, $gap)
                );
            }
        }
        return $out;
    }

    /** @param array<int, array<string, mixed>> $bookings */
    private function positionOf(array $bookings, int $bookingId): ?int
    {
        foreach ($bookings as $index => $booking) {
            if ((int) $booking['id'] === $bookingId) {
                return $index;
            }
        }
        return null;
    }

    /**
     * Queue one message per person.
     *
     * booking_id is the person's first booking of the day. Unlike the bulk
     * announcement, which hangs off nothing, a reminder needs somewhere to
     * record that it went - and that column is the one mail_queue indexes, so
     * recipients() can exclude everyone already reminded in a single read.
     *
     * @param array<int, array{email: string, name: string, bookings: array<int, array<string, mixed>>}> $recipients
     */
    public function enqueue(array $recipients, string $date, string $notice): int
    {
        if ($recipients === []) {
            return 0;
        }

        return Db::transaction(function () use ($recipients, $date, $notice): int {
            $subject = $this->subject($date);
            $queued = 0;
            foreach ($recipients as $person) {
                $this->mailQueue->enqueue(
                    $person['email'],
                    $person['name'] !== '' ? $person['name'] : null,
                    $subject,
                    $this->compose($person, $date, $notice),
                    (int) $person['bookings'][0]['id'],
                    MailQueueRepository::REMINDER,
                );
                $queued++;
            }
            return $queued;
        });
    }

    /**
     * The sessions running on $date, for the screen that lets the office take
     * a cancelled one out.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sessionsOn(string $date): array
    {
        return Db::select(
            "SELECT s.id, s.starts_at, s.ends_at, s.status,
                    e.title AS event_title, c.name AS company_name,
                    -- Seats for confirmed, rows for waiting: the same two units
                    -- the session list elsewhere uses (確定 N 名 / 待ち N 件),
                    -- because a party of three is three seats but one queue entry.
                    COALESCE(SUM(CASE WHEN b.status = 'confirmed' THEN b.party_size ELSE 0 END), 0) AS confirmed,
                    COALESCE(SUM(b.status = 'waitlisted'), 0) AS waitlisted
               FROM event_sessions s
               JOIN events e    ON e.id = s.event_id
               JOIN companies c ON c.id = e.company_id
               LEFT JOIN bookings b ON b.session_id = s.id
                    AND b.status IN ('confirmed','waitlisted')
              WHERE DATE(s.starts_at) = ?
              GROUP BY s.id, s.starts_at, s.ends_at, s.status, e.title, c.name
              ORDER BY s.starts_at, c.name, e.title",
            [$date]
        );
    }

    /** Tomorrow, in the timezone the whole application runs in. */
    public static function defaultDate(): string
    {
        return (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
    }
}
