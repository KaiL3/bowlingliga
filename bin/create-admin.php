<?php

declare(strict_types=1);

/**
 * Creates an admin account from the command line – needed for the first
 * account after installation, since the admin area requires a login.
 *
 * Usage:
 *   php bin/create-admin.php <username>             the password is prompted for
 *   php bin/create-admin.php <username> --only-if-empty
 *        only creates the account if no admin account exists yet
 *
 * For scripts the password can also be passed via the environment variable
 * BOWLINGLIGA_ADMIN_PASSWORD (never as argument, otherwise it shows up in
 * the process list).
 */

use Bowlingliga\I18n\Translator;
use Bowlingliga\Persistence\AdminAccountRepository;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Persistence\AccountLogRepository;
use Bowlingliga\Persistence\AccountPermissionRepository;
use Bowlingliga\Security\AccountException;
use Bowlingliga\Security\AccountLogStore;
use Bowlingliga\Security\AccountManagement;
use Bowlingliga\Security\PasswordHasher;
use Bowlingliga\Security\Permission;
use Bowlingliga\Security\TotpSecretEncryption;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$username = $argv[1] ?? '';
$onlyIfEmpty  = in_array('--only-if-empty', $argv, true);
if ($username === '' || str_starts_with($username, '--')) {
    fwrite(STDERR, "Usage: php bin/create-admin.php <username> [--only-if-empty]\n");
    exit(2);
}

$configurationFile = getenv('BOWLINGLIGA_CONFIG') ?: dirname(__DIR__) . '/config/database.php';
if (! is_file($configurationFile)) {
    fwrite(STDERR, "Configuration missing: {$configurationFile}\n");
    exit(1);
}
$config = require $configurationFile;

$database = new Database($config);
$accounts = new AdminAccountRepository($database);
if ($onlyIfEmpty && $accounts->countItems() > 0) {
    echo "There is an admin account already – nothing created.\n";
    exit(0);
}

$password = getenv('BOWLINGLIGA_ADMIN_PASSWORD');
if (! is_string($password) || $password === '') {
    $password = readPassword('Password for ' . $username . ': ');
    if ($password !== readPassword('Repeat the password: ')) {
        fwrite(STDERR, "The passwords do not match.\n");
        exit(1);
    }
}

if (mb_strlen($password) < AccountManagement::PASSWORD_MIN) {
    fwrite(STDERR, sprintf("The password must be at least %d characters long.\n", AccountManagement::PASSWORD_MIN));
    exit(1);
}

// The former key name `totp_schluessel` is still accepted.
$key = base64_decode((string) ($config['totp_key'] ?? $config['totp_schluessel'] ?? ''), true) ?: '';
$management = new AccountManagement($accounts, new PasswordHasher(), new TotpSecretEncryption($key));

try {
    $id = $management->createAccount($username, $password);
    // The command line is the way in when nobody can manage accounts any more:
    // an account created here holds every permission (R23.10, R23.11).
    (new AccountPermissionRepository($database))->replace($id, array_map(static fn (Permission $p): string => $p->value, Permission::cases()));
    (new AccountLogRepository($database))->record(new DateTimeImmutable(), null, null, $username, AccountLogStore::CREATED);
} catch (AccountException $e) {
    fwrite(STDERR, (new Translator('en'))->error($e) . "\n");
    exit(1);
}

echo "Admin account \"{$username}\" created. Set up the two-factor login afterwards under \"My account\".\n";

/**
 * Reads a password from standard input without echo (on a terminal).
 */
function readPassword(string $question): string
{
    fwrite(STDOUT, $question);
    $terminal = function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($terminal) {
        shell_exec('stty -echo');
    }
    $input = rtrim((string) fgets(STDIN), "\r\n");
    if ($terminal) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }

    return $input;
}
