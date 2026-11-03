<?php

declare(strict_types=1);

/**
 * Router script for `php -S` for the visual check (GET requests only).
 *
 * Environment variables:
 *   BOWLINGLIGA_STYLE  lane | bento             (cookie bl_style)
 *   BOWLINGLIGA_MODE   auto | light | dark      (cookie bl_mode)
 *   BOWLINGLIGA_LANG   de | en                  (cookie bl_lang, language of the texts)
 *   BOWLINGLIGA_ADMIN  id of an admin account   (sets up a logged-in session)
 *
 * Existing files under public/ are served directly by the built-in server,
 * everything else goes to public/index.php.
 */

$public = dirname(__DIR__, 3) . '/public';
$pathName = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

if ($pathName !== '/' && is_file($public . $pathName)) {
    return false;
}

if (getenv('BOWLINGLIGA_STYLE')) {
    $_COOKIE['bl_style'] = getenv('BOWLINGLIGA_STYLE');
}
if (getenv('BOWLINGLIGA_MODE')) {
    $_COOKIE['bl_mode'] = getenv('BOWLINGLIGA_MODE');
}
if (getenv('BOWLINGLIGA_LANG')) {
    $_COOKIE['bl_lang'] = getenv('BOWLINGLIGA_LANG');
}

if (getenv('BOWLINGLIGA_ADMIN')) {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo 'Visual check: GET only with an admin session.';
        return true;
    }
    session_start();
    $accountId = (int) getenv('BOWLINGLIGA_ADMIN');
    $_SESSION['auth_account_id'] = $accountId;
    $_SESSION['auth_logged_in_at'] = time();
    $_SESSION['auth_last_activity'] = time();
    // The session must carry the account's current session version (read only).
    $config = require (getenv('BOWLINGLIGA_CONFIG') ?: dirname(__DIR__, 3) . '/config/database.php');
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['dbname']),
        $config['user'],
        $config['password'],
    );
    $statement = $pdo->prepare('SELECT session_version FROM admin_account WHERE id = :id');
    $statement->execute(['id' => $accountId]);
    $_SESSION['auth_session_version'] = (int) ($statement->fetchColumn() ?: 1);
}

chdir($public);
require $public . '/index.php';
