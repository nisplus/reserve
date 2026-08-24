<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Mirrors the ENUM on admin_users.role.
 *
 * Superadmin is the event office: every company, plus the things that are not
 * any one company's business (company records, the mail queue, accounts).
 * Company is a host organisation: its own events, sessions and applicants,
 * and nothing else - enforced by Authz, not by hiding the links.
 */
enum AdminRole: string
{
    case Superadmin = 'superadmin';
    case Company    = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Superadmin => '事務局',
            self::Company    => '会社担当者',
        };
    }

    /** Whether this role is bound to a single company_id. */
    public function isScopedToCompany(): bool
    {
        return $this === self::Company;
    }
}
