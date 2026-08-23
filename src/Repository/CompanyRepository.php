<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Db;

final class CompanyRepository
{
    /** @return array<int, array<string, mixed>> */
    public function all(bool $publishedOnly = false): array
    {
        $where = $publishedOnly ? 'WHERE is_published = 1' : '';
        return Db::select(
            "SELECT id, name, name_kana, sort_order, is_published, created_at, updated_at
             FROM companies {$where}
             ORDER BY sort_order, id"
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return Db::selectOne('SELECT * FROM companies WHERE id = ?', [$id]);
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

    public function create(string $name, ?string $kana, int $sortOrder, bool $published): int
    {
        Db::execute(
            'INSERT INTO companies (name, name_kana, sort_order, is_published) VALUES (?, ?, ?, ?)',
            [$name, $kana, $sortOrder, $published ? 1 : 0]
        );
        return Db::lastInsertId();
    }

    public function update(int $id, string $name, ?string $kana, int $sortOrder, bool $published): void
    {
        Db::execute(
            'UPDATE companies SET name = ?, name_kana = ?, sort_order = ?, is_published = ? WHERE id = ?',
            [$name, $kana, $sortOrder, $published ? 1 : 0, $id]
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
