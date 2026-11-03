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

use Bowlingliga\Persistence\AdminAccountRepository;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Security\AccountException;
use Bowlingliga\Security\AccountManagement;
use Bowlingliga\Security\PasswordHasher;
use Bowlingliga\Security\TotpSecretEncryption;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$username = $argv[1] ?? '';
$onlyIfEmpty  = in_array('--only-if-empty', $argv, true);
if ($username === '' || str_starts_with($username, '--')) {
    fwrite(STDERR, "Aufruf: php bin/create-admin.php <benutzername> [--only-if-empty]\n");
    exit(2);
}

$configurationFile = getenv('BOWLINGLIGA_CONFIG') ?: dirname(__DIR__) . '/config/database.php';
if (! is_file($configurationFile)) {
    fwrite(STDERR, "Konfiguration fehlt: {$configurationFile}\n");
    exit(1);
}
$config = require $configurationFile;

$accounts = new AdminAccountRepository(new Database($config));
if ($onlyIfEmpty && $accounts->countItems() > 0) {
    echo "Es gibt bereits ein Adminkonto – nichts angelegt.\n";
    exit(0);
}

$password = getenv('BOWLINGLIGA_ADMIN_PASSWORD');
if (! is_string($password) || $password === '') {
    $password = readPassword('Passwort für ' . $username . ': ');
    if ($password !== readPassword('Passwort wiederholen: ')) {
        fwrite(STDERR, "Die Passwörter stimmen nicht überein.\n");
        exit(1);
    }
}

if (mb_strlen($password) < AccountManagement::PASSWORD_MIN) {
    fwrite(STDERR, sprintf("Das Passwort muss mindestens %d Zeichen lang sein.\n", AccountManagement::PASSWORD_MIN));
    exit(1);
}

// Former key name `totp_key` still accepted (configurations from before the English conversion).
$key = base64_decode((string) ($config['totp_key'] ?? $config['totp_schluessel'] ?? ''), true) ?: '';
$management = new AccountManagement($accounts, new PasswordHasher(), new TotpSecretEncryption($key));

try {
    $management->createAccount($username, $password);
} catch (AccountException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "Adminkonto \"{$username}\" angelegt. Zwei-Faktor-Anmeldung danach unter „Mein Konto“ einrichten.\n";

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
