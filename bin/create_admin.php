<?php

declare(strict_types=1);

/**
 * Create a back office account, or reset the password of an existing one
 * (bin/seed.php points here for password changes).
 *
 * Usage:
 *   php bin/create_admin.php <username> <password> [display_name]
 *   php bin/create_admin.php <username> <password> [display_name] --company=<id>
 *
 * Without --company the account is office staff (superadmin), seeing every
 * company. With it, the account is confined to that one company's events and
 * applicants. Run with --list-companies to see the ids.
 *
 * On an existing username this only resets the password (and clears any
 * lockout); role and company are changed from /admin/users.
 */

if (PHP_SAPI !== 'cli') {
    exit("This script is CLI-only.\n");
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Auth;
use App\Domain\AdminRole;
use App\Repository\AdminUserRepository;
use App\Repository\CompanyRepository;

$positional = [];
$companyId = null;
$listCompanies = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--list-companies') {
        $listCompanies = true;
    } elseif (preg_match('/^--company=(\d+)$/', $arg, $m)) {
        $companyId = (int) $m[1];
    } elseif (!str_starts_with($arg, '--')) {
        $positional[] = $arg;
    } else {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(1);
    }
}

if ($listCompanies) {
    foreach ((new CompanyRepository())->options() as $id => $name) {
        printf("%4d  %s\n", $id, $name);
    }
    exit(0);
}

$username    = $positional[0] ?? '';
$password    = $positional[1] ?? '';
$displayName = $positional[2] ?? $username;

if ($username === '' || $password === '') {
    fwrite(STDERR, "Usage: php bin/create_admin.php <username> <password> [display_name] [--company=<id>]\n");
    fwrite(STDERR, "       php bin/create_admin.php --list-companies\n");
    exit(1);
}
if (mb_strlen($username) > 60) {
    fwrite(STDERR, "Username must be 60 characters or fewer.\n");
    exit(1);
}
if (mb_strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$repo = new AdminUserRepository();
$hash = Auth::hashPassword($password);

$existing = $repo->findByUsername($username);
if ($existing !== null) {
    if ($companyId !== null) {
        fwrite(STDERR, "'{$username}' already exists; change its company from /admin/users.\n");
        exit(1);
    }
    // updatePassword also clears failed_attempts / locked_until, so this
    // doubles as the way to unlock an account.
    $repo->updatePassword((int) $existing['id'], $hash);
    echo "Password updated (and lockout cleared) for '{$username}'.\n";
    exit(0);
}

$role = AdminRole::Superadmin;
if ($companyId !== null) {
    $companies = (new CompanyRepository())->options();
    if (!isset($companies[$companyId])) {
        fwrite(STDERR, "No company with id {$companyId}. Try --list-companies.\n");
        exit(1);
    }
    $role = AdminRole::Company;
}

$repo->create($username, $hash, $displayName, $role->value, $companyId);

printf(
    "Created '%s' (%s%s).\n",
    $username,
    $role->label(),
    $companyId !== null ? ': ' . (new CompanyRepository())->options()[$companyId] : ''
);
