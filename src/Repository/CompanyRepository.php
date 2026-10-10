<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Db;
use App\Domain\Area;

final class CompanyRepository
{
    /** @return array<int, array<string, mixed>> */
    public function all(bool $publishedOnly = false): array
    {
        $where = $publishedOnly ? 'WHERE is_published = 1' : '';
        return Db::select(
            "SELECT id, name, name_kana, area, sort_order, is_published, created_at, updated_at
             FROM companies {$where}
             ORDER BY sort_order, id"
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return Db::selectOne('SELECT * FROM companies WHERE id = ?', [$id]);
    }

    /**
     * Areas that actually have a published company in them, as value => label.
     *
     * The enum and the ENUM column both carry every area the site may ever
     * use, including ones held open for companies that have not joined yet.
     * Offering one of those as a filter is offering a visitor a button whose
     * only possible answer is "nothing here" - so what goes on screen comes
     * from the companies, while the definitions stay where they are. An area
     * gets its first company and its button appears; nobody has to remember.
     *
     * Not for the company form: that is where an area gets its first company,
     * so it has to go on offering the empty ones.
     *
     * @return array<string, string>
     */
    public function areasInUse(bool $publishedOnly = true): array
    {
        $where = $publishedOnly ? 'AND is_published = 1' : '';
        $used = array_column(
            Db::select("SELECT DISTINCT area FROM companies WHERE area IS NOT NULL {$where}"),
            'area'
        );

        // Kept in Area::options() order rather than the database's: that is
        // the order the site names its areas in everywhere else.
        return array_filter(
            Area::options(),
            static fn (string $value): bool => in_array($value, $used, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /** id => name, for populating select boxes. @return array<int, string> */
    public function options(): array
    {
        $options = [];
        foreach ($this->all() as $company) {
            $options[(int) $company['id']] = (string) $company['name'];
        }
        return $options;
    }

    public function create(string $name, ?string $kana, int $sortOrder, bool $published, ?string $area = null): int
    {
        Db::execute(
            'INSERT INTO companies (name, name_kana, area, sort_order, is_published) VALUES (?, ?, ?, ?, ?)',
            [$name, $kana, $area, $sortOrder, $published ? 1 : 0]
        );
        return Db::lastInsertId();
    }

    /** $area is required rather than defaulted, so an update cannot silently clear it. */
    public function update(int $id, string $name, ?string $kana, int $sortOrder, bool $published, ?string $area): void
    {
        Db::execute(
            'UPDATE companies SET name = ?, name_kana = ?, area = ?, sort_order = ?, is_published = ? WHERE id = ?',
            [$name, $kana, $area, $sortOrder, $published ? 1 : 0, $id]
        );
    }

    public function delete(int $id): void
    {
        Db::execute('DELETE FROM companies WHERE id = ?', [$id]);
    }

    public function eventCount(int $companyId): int
    {
        return (int) Db::scalar('SELECT COUNT(*) FROM events WHERE company_id = ?', [$companyId]);
    }

    /** Company name occupies a UNIQUE index; check before insert for a decent message. */
    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        if ($exceptId === null) {
            return (int) Db::scalar('SELECT COUNT(*) FROM companies WHERE name = ?', [$name]) > 0;
        }
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM companies WHERE name = ? AND id <> ?',
            [$name, $exceptId]
        ) > 0;
    }
}
