<?php

declare(strict_types=1);

/**
 * Create an admin account, or reset the password of an existing one
 * (bin/seed.php points here for password changes).
 *
 * Usage:
 *   php bin/create_admin.php <username> <password> [display_name]
 */

if (PHP_SAPI !== 'cli') {
    exit("This script is CLI-only.\n");
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Auth;
use App\Repository\AdminUserRepository;

$username    = $argv[1] ?? '';
$password    = $argv[2] ?? '';
$displayName = $argv[3] ?? $username;

if ($username === '' || $password === '') {
    fwrite(STDERR, "Usage: php bin/create_admin.php <username> <password> [display_name]\n");
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
    // updatePassword also clears failed_attempts / locked_until, so this
    // doubles as the way to unlock an account.
    $repo->updatePassword((int) $existing['id'], $hash);
    echo "Password updated (and lockout cleared) for '{$username}'.\n";
    exit(0);
}

$repo->create($username, $hash, $displayName);
echo "Admin '{$username}' created.\n";
