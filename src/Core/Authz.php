<?php

declare(strict_types=1);

namespace App\Core;

use App\Exception\NotFoundException;

/**
 * Authorisation checks for the back office.
 *
 * Two rules, applied everywhere:
 *
 *   1. Some screens are the office's alone (company records, the mail queue,
 *      accounts). Those routes carry 'superadmin' => true.
 *   2. Everything else is company-scoped: a company account may only touch
 *      rows belonging to its own company_id.
 *
 * Rule 2 cannot be delivered by hiding links. A company account can type
 * /admin/events/57/edit for someone else's event, so the check lives in the
 * controllers' load* methods, next to the fetch, where it cannot be forgotten
 * by a later screen that reuses the repository.
 *
 * Denials raise NotFoundException, not a 403: telling one company that
 * another company's event id exists is itself a leak. To an outsider the row
 * simply is not there.
 */
final class Authz
{
    /** @throws NotFoundException */
    public static function requireSuperadmin(): void
    {
        if (!Auth::isSuperadmin()) {
            throw new NotFoundException('ページが見つかりません。');
        }
    }

    /**
     * Confine a company account to its own company. Superadmins pass through.
     *
     * @throws NotFoundException when the row belongs to someone else
     */
    public static function assertCompany(?int $companyId): void
    {
        $own = Auth::companyId();
        if ($own === null) {
            return; // the office sees every company
        }
        if ($companyId === null || $companyId !== $own) {
            throw new NotFoundException('お探しのページは見つかりませんでした。');
        }
    }

    /**
     * The company id a listing must be filtered by, or null for no filter.
     * Company accounts get their own id regardless of what the query string
     * asked for - a filter the user can widen is not a boundary.
     */
    public static function scopeCompanyId(int $requested = 0): ?int
    {
        $own = Auth::companyId();
        if ($own !== null) {
            return $own;
        }
        return $requested > 0 ? $requested : null;
    }
}
