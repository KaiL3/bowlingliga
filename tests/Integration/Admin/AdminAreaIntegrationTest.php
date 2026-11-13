<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Integration\Admin;

use Bowlingliga\I18n\Translator;
use Bowlingliga\Persistence\Database;
use Bowlingliga\Security\PasswordHasher;
use OTPHP\TOTP;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * End-to-end integration test of the admin area against a local
 * MySQL/MariaDB test database (task 35.7).
 *
 * Validates: R5.1, R28.8, R28.9, R28.10, R28.12, R28.16, R40.1, R40.2, R40.3
 *
 * Starts the built-in PHP web server with the production front controller
 * `public/index.php`, which points to the test database via
 * `BOWLINGLIGA_CONFIG`, and talks to it via HTTP – including session cookie
 * and CSRF token. Checked are:
 *
 * - access protection: without login every functional area denies access
 *   (401 + login request), writing actions change nothing,
 * - login, logout and CSRF check,
 * - per functional area a writing run followed by a persistence check: master
 *   data → season planning → result entry, plus printed score sheets and
 *   honours preview on the same data and the CMS editing (home page posts,
 *   rules).
 *
 * Schema: migrations 001–004 per test; skipped without a reachable database or
 * a web server that can be started.
 */
final class AdminAreaIntegrationTest extends TestCase
{
    private const USER = 'testadmin';
    private const PASSWORD = 'Kegel#Abend-Mittwoch-35.7';

    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private static string $configFilePath = '';
    private static string $scoreSheetDir = '';

    private Database $database;
    private PDO $pdo;

    /** @var \CurlHandle|null */
    private $http = null;

    public static function setUpBeforeClass(): void
    {
        if (! extension_loaded('curl') || ! in_array('mysql', PDO::getAvailableDrivers(), true)) {
            return;
        }

        $root = dirname(__DIR__, 3);
        self::$configFilePath = (string) tempnam(sys_get_temp_dir(), 'ml-cfg');
        self::$scoreSheetDir = sys_get_temp_dir() . '/ml-sheets-' . bin2hex(random_bytes(4));
        file_put_contents(
            self::$configFilePath,
            '<?php return ' . var_export(self::testConfiguration() + [
                'totp_key'        => base64_encode(random_bytes(32)),
                'score_sheet_dir' => self::$scoreSheetDir,
                // Removed with the score sheets after the class.
                'media_dir'       => self::$scoreSheetDir . '/media',
            ], true) . ';'
        );

        $port = self::freePort();
        $environment = getenv();
        $environment['BOWLINGLIGA_CONFIG'] = self::$configFilePath;

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root . '/public', $root . '/public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', self::nullDevice(), 'w'], 2 => ['file', self::nullDevice(), 'w']],
            $pipes,
            $root,
            $environment,
        );
        if (! is_resource($process)) {
            return;
        }
        self::$server = $process;

        // Wait until the server accepts connections (at most 5 s).
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $code, $message, 0.1);
            if ($socket !== false) {
                fclose($socket);
                self::$base = 'http://127.0.0.1:' . $port;

                return;
            }
            usleep(100_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
        if (self::$configFilePath !== '' && is_file(self::$configFilePath)) {
            unlink(self::$configFilePath);
        }
        if (self::$scoreSheetDir !== '' && is_dir(self::$scoreSheetDir)) {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::$scoreSheetDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($entries as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir(self::$scoreSheetDir);
        }
    }

    protected function setUp(): void
    {
        if (! extension_loaded('curl')) {
            self::markTestSkipped('The cURL extension is not available; integration test skipped.');
        }
        if (! in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('The PDO MySQL driver is not available; integration test skipped.');
        }

        $this->database = new Database(self::testConfiguration());

        try {
            $this->pdo = $this->database->pdo();
            $this->pdo->query('SELECT 1');
        } catch (Throwable $throwable) {
            self::markTestSkipped('No reachable MySQL test database: ' . $throwable->getMessage());
        }

        if (self::$base === '') {
            self::markTestSkipped('The built-in PHP web server could not be started.');
        }

        $this->applySchema();
        $this->pdo->prepare('INSERT INTO admin_account (username, password_hash) VALUES (?, ?)')
            ->execute([self::USER, (new PasswordHasher())->hash(self::PASSWORD)]);

        // Per test its own cURL session with its own cookie store.
        $this->http = curl_init();
        curl_setopt_array($this->http, [
            CURLOPT_COOKIEFILE     => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 30,
        ]);
    }

    protected function tearDown(): void
    {
        $this->http = null;

        if (isset($this->pdo)) {
            $this->dropSchema();
        }
    }

    /**
     * R5.1/R28.9: without login every page of the admin area denies access and
     * asks the user to log in.
     */
    public function testWithoutLoginEveryFunctionalAreaDeniesAccess(): void
    {
        foreach ([
            '/admin',
            '/admin/master-data',
            '/admin/master-data?tab=import',
            '/admin/master-data/import-preview',
            '/admin/season-planning',
            '/admin/season-planning/preview',
            '/admin/results',
            '/admin/results/score-sheet?fixture=1',
            '/admin/results/handicap-chain?fixture=1',
            '/admin/account',
            '/admin/print?fixture=1',
            '/admin/honours',
            '/admin/home-page',
            '/admin/rules',
            '/admin/league',
        ] as $pathName) {
            [$status, $html] = $this->get($pathName);

            self::assertSame(401, $status, $pathName);
            self::assertStringContainsString('Bitte melden Sie sich an', $html, $pathName);
            self::assertStringContainsString('action="/admin/login"', $html, $pathName);
        }
    }

    /**
     * R5.1: writing actions without login are refused and do not change the
     * data – not even with a formally valid token from a session that is not
     * logged in.
     */
    public function testWithoutLoginWritingActionsChangeNothing(): void
    {
        [, $html] = $this->get('/admin/login');
        $token = $this->csrfToken($html);

        foreach ([
            '/admin/master-data/player-create'   => ['name' => 'Eindringling', 'gender' => 'male'],
            '/admin/master-data/season-create'    => ['name' => 'Fremdsaison', 'start_date' => '2030-09-04', 'end_date' => '2030-12-31'],
            '/admin/master-data/account-create'     => ['username' => 'hacker', 'password' => 'Sehr-Langes-Passwort-1'],
            '/admin/master-data/import'            => ['season_id' => '1', 'csv' => base64_encode("Team;Name;Geschlecht\nA;Eindringling;m\n")],
            '/admin/home-page/create'           => ['title' => 'Gehackt', 'text' => 'Gehackt'],
            '/admin/rules/save'        => ['text' => 'Gehackt'],
            '/admin/league/save'       => ['name' => 'Gehackt'],
            '/admin/season-planning/apply'    => ['season_id' => '1', 'fingerprint' => 'x', 'confirmed' => '1'],
            '/admin/season/lot-draw'          => ['matchday_id' => '1', 'home' => ['1'], 'away' => ['2']],
            '/admin/results/save'         => ['fixture_id' => '1', 'action' => 'confirm'],
            '/admin/results/handicap-chain'    => ['fixture_id' => '1', 'fingerprint' => 'x', 'confirmed' => '1'],
            '/admin/account/totp-start'          => [],
            '/admin/account/password'              => ['current' => 'x', 'new' => 'Gehackt-123', 'repeat' => 'Gehackt-123'],
        ] as $pathName => $fields) {
            [$status] = $this->post($pathName, $fields + ['csrf_token' => $token]);
            self::assertSame(401, $status, $pathName);
        }

        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM player'));
        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM season'));
        self::assertSame(1, $this->numeral('SELECT COUNT(*) FROM admin_account'));
        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM home_page_post'));
        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM rules'));
        self::assertSame('Bowlingliga', $this->text('SELECT name FROM league_setting'));
    }

    /**
     * R28.8/R28.10/R5.3/R5.5: a wrong password fails; after a successful login
     * the overview is reachable, after logout no longer.
     */
    public function testLoginAndLogout(): void
    {
        [, $html] = $this->get('/admin/login');
        [$status] = $this->post('/admin/login', [
            'csrf_token'   => $this->csrfToken($html),
            'username' => self::USER,
            'password'     => 'falsch',
        ]);
        self::assertSame(401, $status);
        self::assertSame(401, $this->get('/admin')[0]);
        self::assertStringNotContainsString('header__admin', $this->get('/')[1], 'Visitors see no way into the admin area (R28.19).');

        $this->logIn();
        self::assertStringContainsString('<a class="header__admin" href="/admin" title="Adminbereich" aria-label="Adminbereich">', $this->get('/')[1], 'A logged-in admin finds the way back (R28.19).');
        self::assertStringContainsString('class="header__admin"', $this->get('/table')[1]);

        [$status, $html] = $this->get('/admin');
        self::assertStringNotContainsString('header__admin', $html, 'Not inside the admin area, it has its own bar.');
        self::assertSame(200, $status);
        foreach (['/admin/master-data', '/admin/season-planning', '/admin/results', '/admin/home-page', '/admin/rules', '/admin/league'] as $link) {
            self::assertStringContainsString('href="' . $link . '"', $html, 'The overview links ' . $link);
        }

        [$status, , $header] = $this->post('/admin/logout', []);
        self::assertSame(302, $status);
        self::assertStringContainsString('Location: /admin/login', $header);
        self::assertSame(401, $this->get('/admin/master-data')[0]);
        self::assertStringNotContainsString('header__admin', $this->get('/')[1], 'Gone after logging out.');
    }

    /**
     * R23.10–R23.17: a system admin creates a league admin, whose password
     * has to meet the policy; the new account replaces it at its first login
     * and sees neither the admin accounts nor the system area.
     */
    public function testOnlySystemAdminsManageAccountsAndPolicies(): void
    {
        $this->pdo->exec("INSERT INTO account_permission (account_id, permission) SELECT id, 'system.admin' FROM admin_account");
        $this->logIn();

        [, $form] = $this->get('/admin/master-data?tab=accounts');
        self::assertStringContainsString('action="/admin/master-data/account-create"', $form);
        self::assertStringContainsString('data-strength-for="account-password" data-min="70"', $form, 'Strength display with the minimum (R23.16).');
        [, $html] = $this->post('/admin/master-data/account-create', [
            'csrf_token' => $this->csrfToken($form),
            'username'   => 'helfer',
            'password'   => 'helfer-passwort',
        ]);
        self::assertStringContainsString('Das Passwort ist zu schwach:', $html, 'R23.15');
        self::assertSame(1, $this->numeral('SELECT COUNT(*) FROM admin_account'));
        [$status, $html] = $this->post('/admin/master-data/account-create', [
            'csrf_token' => $this->csrfToken($form),
            'username'   => 'helfer',
            'password'   => 'Vorlaeufig#Bahn-7-Kegel',
        ]);
        self::assertSame(200, $status, $this->message($html));
        self::assertStringContainsString('testadmin hat das Konto helfer angelegt.', $html);
        self::assertStringContainsString('muss bei der nächsten Anmeldung geändert werden', $html);
        self::assertSame(0, $this->numeral("SELECT COUNT(*) FROM account_permission p JOIN admin_account a ON a.id = p.account_id WHERE a.username = 'helfer'"), 'R23.13');

        // The system area belongs to the system admin.
        [, $overview] = $this->get('/admin');
        self::assertStringContainsString('href="/admin/system"', $overview);
        [$status, $system] = $this->get('/admin/system');
        self::assertSame(200, $status);
        self::assertStringContainsString('Passwortrichtlinie: Spieler', $system);
        [$status, , $header] = $this->post('/admin/system/password-policy', [
            'csrf_token' => $this->csrfToken($system), 'account_kind' => 'user', 'min_bits' => '60', 'max_age_days' => '-1', 'history_size' => '3',
        ]);
        self::assertSame(303, $status);
        self::assertStringContainsString('Location: /admin/system?saved=user', $header);
        self::assertSame(60, $this->numeral("SELECT min_bits FROM password_policy WHERE account_kind = 'user'"));

        // The strength display asks the server (R23.16).
        [$status, $answer] = $this->post('/admin/password-strength', ['csrf_token' => $this->csrfToken($system), 'password' => 'kX9#mP2$vL7q']);
        self::assertSame(200, $status);
        self::assertSame('{"bits":78}', $answer);
        $this->post('/admin/logout', []);
        [$status] = $this->post('/admin/password-strength', ['csrf_token' => $this->csrfToken($system), 'password' => 'kX9#mP2$vL7q']);
        self::assertSame(403, $status, 'Not without a login.');

        // First login of the new account: a new password first (R23.17).
        [, $login] = $this->get('/admin/login');
        [$status, , $header] = $this->post('/admin/login', ['csrf_token' => $this->csrfToken($login), 'username' => 'helfer', 'password' => 'Vorlaeufig#Bahn-7-Kegel']);
        self::assertSame(303, $status);
        self::assertStringContainsString('Location: /admin/login/password', $header);
        self::assertSame(401, $this->get('/admin/master-data')[0], 'Nothing is accessible before the new password.');
        [$status, $change] = $this->get('/admin/login/password');
        self::assertSame(200, $status);
        self::assertStringContainsString('Dieses Passwort hat jemand anderes festgelegt.', $change);
        [$status, $answer] = $this->post('/admin/password-strength', ['csrf_token' => $this->csrfToken($change), 'password' => 'geheim123']);
        self::assertSame([200, '{"bits":17}'], [$status, $answer], 'The display works on this page too.');
        [$status, $change] = $this->post('/admin/login/password', ['csrf_token' => $this->csrfToken($change), 'new' => 'Vorlaeufig#Bahn-7-Kegel', 'repeat' => 'Vorlaeufig#Bahn-7-Kegel']);
        self::assertSame(422, $status);
        self::assertStringContainsString('Das Passwort darf weder das aktuelle noch eines der letzten 5 sein.', $change);
        [$status, , $header] = $this->post('/admin/login/password', ['csrf_token' => $this->csrfToken($change), 'new' => 'Eigenes!Passwort-von-Helfer-9', 'repeat' => 'Eigenes!Passwort-von-Helfer-9']);
        self::assertSame(303, $status);
        self::assertStringContainsString('Location: /admin', $header);

        // The league admin works in the admin area but sees neither accounts nor system.
        [$status, $page] = $this->get('/admin/master-data?tab=accounts');
        self::assertSame(200, $status);
        self::assertStringNotContainsString('>Adminkonten</a>', $page, 'No tab of the admin accounts.');
        self::assertStringNotContainsString('account-create', $page);
        self::assertStringNotContainsString('href="/admin/system"', $page);
        self::assertStringContainsString('Dafür fehlt diesem Konto das Recht „Systemadmin“.', $page);
        self::assertStringContainsString('Dafür fehlt diesem Konto das Recht „Systemadmin“.', $this->get('/admin/system')[1]);

        // Direct requests are refused as well.
        $this->post('/admin/master-data/account-create', ['csrf_token' => $this->csrfToken($page), 'username' => 'dritter', 'password' => 'Dritter#Kegel-Bahn-77!']);
        $this->post('/admin/master-data/account-permissions', ['csrf_token' => $this->csrfToken($page), 'account_id' => (string) $this->numeral("SELECT id FROM admin_account WHERE username = 'helfer'"), 'permissions' => ['system.admin']]);
        $this->post('/admin/system/password-policy', ['csrf_token' => $this->csrfToken($page), 'account_kind' => 'admin', 'min_bits' => '0', 'max_age_days' => '-1', 'history_size' => '0']);
        self::assertSame(2, $this->numeral('SELECT COUNT(*) FROM admin_account'));
        self::assertSame(1, $this->numeral('SELECT COUNT(*) FROM account_permission'));
        self::assertSame(70, $this->numeral("SELECT min_bits FROM password_policy WHERE account_kind = 'admin'"));
        self::assertSame(200, $this->get('/admin/account')[0], '"Mein Konto" stays allowed.');
    }
    /**
     * R42: an image uploaded in the area and by the editor, delivered for
     * good under its hash, used in the rules with the overlay, not removable
     * while used.
     */
    public function testImagesInTheTexts(): void
    {
        $this->logIn();
        [$status, $area] = $this->get('/admin/media');
        self::assertSame(200, $status);
        self::assertStringContainsString('Es sind noch keine Bilder hochgeladen.', $area);

        // The form of the area.
        $image = imagecreatetruecolor(30, 20);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        [$status, $html] = $this->postFile('/admin/media/upload', ['csrf_token' => $this->csrfToken($area)], 'file', 'mannschaft.jpg', $jpeg);
        self::assertSame(200, $status, $this->message($html));
        self::assertStringContainsString('Das Bild ist gespeichert.', $html);
        $hash = $this->text('SELECT content_hash FROM content_image');
        $url  = '/media/' . $hash . '.jpg';

        // Delivery: public, for good, with a narrow CSP.
        $this->post('/admin/logout', []);
        [$status, $content, $header] = $this->get($url);
        self::assertSame(200, $status);
        self::assertSame([30, 20], array_slice((array) getimagesizefromstring($content), 0, 2));
        self::assertStringContainsString('Content-Type: image/jpeg', $header);
        self::assertStringContainsString('Cache-Control: public, max-age=31536000, immutable', $header);
        self::assertStringContainsString("Content-Security-Policy: default-src 'none'", $header);
        self::assertSame(404, $this->get('/media/' . $hash . '.png')[0]);
        self::assertSame(404, $this->get('/media/' . str_repeat('0', 64) . '.jpg')[0]);
        self::assertSame(401, $this->get('/admin/media/list')[0], 'The list for the editor needs a login.');
        $this->logIn();

        // The editor asks for JSON.
        [, $rules] = $this->get('/admin/rules');
        curl_setopt($this->http, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $png = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($png);
        [$status, $answer] = $this->postFile('/admin/media/upload', ['csrf_token' => $this->csrfToken($rules)], 'file', 'logo.png', (string) ob_get_clean());
        self::assertSame(200, $status);
        self::assertMatchesRegularExpression('#^\{"location":"\\\\/media\\\\/[0-9a-f]{64}\.png"\}$#', $answer);
        [$status, $answer] = $this->postFile('/admin/media/upload', ['csrf_token' => $this->csrfToken($rules)], 'file', 'boese.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        self::assertSame([422, '{"error":"Die Datei ist kein Bild im Format JPEG, PNG oder WebP."}'], [$status, $answer]);
        curl_setopt($this->http, CURLOPT_HTTPHEADER, []);
        [, $list] = $this->get('/admin/media/list');
        self::assertCount(2, (array) json_decode($list, true));

        // Used in the rules: shown with the overlay, the image stays.
        $this->sendSuccessful('/admin/rules', '/admin/rules/save', [
            'text' => '<h2>Mannschaft</h2><figure class="image"><img src="' . $url . '" alt="Unsere Mannschaft" class="image--left"><figcaption>Saison 2026</figcaption></figure>',
        ]);
        [, $public] = $this->get('/rules');
        self::assertStringContainsString('<img src="' . $url . '" alt="Unsere Mannschaft" class="image--left" />', $public);
        self::assertStringContainsString('js-lightbox-dialog', $public);

        [, $area] = $this->get('/admin/media');
        self::assertStringContainsString('<a href="/admin/rules">Spielregeln</a>', $area);
        [$status, $html] = $this->post('/admin/media/remove', ['csrf_token' => $this->csrfToken($area), 'hash' => $hash]);
        self::assertSame(422, $status);
        self::assertStringContainsString('Das Bild wird noch in 1 Text(en) verwendet', $html);

        // Without a description the text is not saved.
        [, $form] = $this->get('/admin/rules');
        [, $html] = $this->post('/admin/rules/save', ['csrf_token' => $this->csrfToken($form), 'text' => '<p><img src="' . $url . '"></p>']);
        self::assertStringContainsString('Jedes Bild braucht eine Bildbeschreibung', $html);

        // Taken out of the text: removable, the address is gone.
        $this->sendSuccessful('/admin/rules', '/admin/rules/save', ['text' => '<p>Ohne Bild</p>']);
        [, $area] = $this->get('/admin/media');
        [$status, $html] = $this->post('/admin/media/remove', ['csrf_token' => $this->csrfToken($area), 'hash' => $hash]);
        self::assertSame(200, $status, $this->message($html));
        self::assertStringContainsString('Das Bild ist entfernt.', $html);
        self::assertSame(404, $this->get($url)[0]);
    }

    /**
     * R23.6–R23.8: set up the two-factor login via "Mein Konto" (only active
     * with a valid code); afterwards the login requires the code. Disable with
     * password and code; change the password.
     */
    public function testSetUpTwoFactorAndLogIn(): void
    {
        self::assertSame(401, $this->get('/admin/account')[0]);

        $this->logIn();
        [$status, $html] = $this->get('/admin/account');
        self::assertSame(200, $status);
        self::assertStringContainsString('nicht aktiv', $html);

        // Step 1: generate the secret – nothing stored yet.
        [, $html] = $this->post('/admin/account/totp-start', ['csrf_token' => $this->csrfToken($html)]);
        self::assertStringContainsString('<svg', $html, 'The QR code is shown.');
        self::assertMatchesRegularExpression('#href="otpauth://totp/#', $html);
        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM admin_account WHERE totp_secret_encrypted IS NOT NULL'));
        self::assertSame(1, preg_match('#<p class="setup__secret"><code>([A-Z2-7 ]+)</code>#', $html, $matches));
        $secret = str_replace(' ', '', $matches[1]);

        // Wrong code: not active; correct code: active.
        $code = TOTP::createFromSecret($secret)->now();
        [$status] = $this->post('/admin/account/totp-activate', ['csrf_token' => $this->csrfToken($html), 'code' => $code === '000000' ? '111111' : '000000']);
        self::assertSame(422, $status);
        [$status, $html] = $this->post('/admin/account/totp-activate', ['csrf_token' => $this->csrfToken($html), 'code' => $code]);
        self::assertSame(200, $status, $this->message($html));
        self::assertStringContainsString('Die Zwei-Faktor-Anmeldung ist aktiv.', $html);
        self::assertSame(1, $this->numeral('SELECT COUNT(*) FROM admin_account WHERE totp_secret_encrypted IS NOT NULL'));
        self::assertStringNotContainsString($secret, $this->text('SELECT totp_secret_encrypted FROM admin_account'), 'Stored only encrypted (R23.9).');

        // Two-step login (R23.7, R23.8): step 2 is not reachable without step 1.
        $this->post('/admin/logout', []);
        [$status, , $header] = $this->get('/admin/login/code');
        self::assertSame(302, $status);
        self::assertStringContainsString('Location: /admin/login', $header);

        // Step 1: correct password → on to the code, no access yet, no failed attempt.
        [, $login] = $this->get('/admin/login');
        self::assertStringNotContainsString('name="totp"', $login, 'Step 1 asks only for username and password.');
        [$status, , $header] = $this->post('/admin/login', ['csrf_token' => $this->csrfToken($login), 'username' => self::USER, 'password' => self::PASSWORD]);
        self::assertSame(303, $status);
        self::assertStringContainsString('Location: /admin/login/code', $header);
        self::assertSame(401, $this->get('/admin/master-data')[0]);
        self::assertSame(0, $this->numeral('SELECT failed_attempts FROM admin_account'));

        // Schritt 2: falscher Code → erneut; richtiger Code → angemeldet.
        [$status, $codePage] = $this->get('/admin/login/code');
        self::assertSame(200, $status);
        self::assertStringContainsString('Code aus der App', $codePage);
        $code = TOTP::createFromSecret($secret)->now();
        [$status, $codePage] = $this->post('/admin/login/code', ['csrf_token' => $this->csrfToken($codePage), 'totp' => $code === '000000' ? '111111' : '000000']);
        self::assertSame(401, $status);
        self::assertStringContainsString('Der zweite Faktor ist ungültig', $codePage);
        [$status, , $header] = $this->post('/admin/login/code', ['csrf_token' => $this->csrfToken($codePage), 'totp' => TOTP::createFromSecret($secret)->now()]);
        self::assertSame(302, $status);
        self::assertStringContainsString('Location: /admin', $header);
        self::assertSame(200, $this->get('/admin/master-data')[0]);
        self::assertSame(0, $this->numeral('SELECT failed_attempts FROM admin_account'), 'Success resets the counter.');

        // Disable with password and code.
        [, $html] = $this->get('/admin/account');
        [$status, $html] = $this->post('/admin/account/totp-deactivate', [
            'csrf_token' => $this->csrfToken($html), 'password' => self::PASSWORD, 'code' => TOTP::createFromSecret($secret)->now(),
        ]);
        self::assertSame(200, $status, $this->message($html));
        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM admin_account WHERE totp_secret_encrypted IS NOT NULL'));

        // Change the password.
        [$status, $html] = $this->post('/admin/account/password', [
            'csrf_token' => $this->csrfToken($html), 'current' => self::PASSWORD, 'new' => 'Ganz-Neues-Passwort-2-Bahn9!', 'repeat' => 'Ganz-Neues-Passwort-2-Bahn9!',
        ]);
        self::assertSame(200, $status, $this->message($html));
        self::assertTrue(password_verify('Ganz-Neues-Passwort-2-Bahn9!', $this->text('SELECT password_hash FROM admin_account')));
    }

    /**
     * Security headers on every page; an original score sheet only becomes
     * public once its fixture is entered completely.
     */
    /**
     * R37: the admin switches the team entry on, both teams open their page via
     * the code (QR code), enter their own lineup and results game by game, send
     * them and confirm the opponent's entry – then the fixture is complete and
     * public.
     */
    public function testTeamsEnterAndConfirmTheirResultsViaTheirCodes(): void
    {
        $this->logIn();
        $today = date('Y-m-d');
        $seasonId = $this->insert("INSERT INTO season (name, start_date, end_date) VALUES ('Teamsaison', DATE_SUB(CURDATE(), INTERVAL 7 DAY), DATE_ADD(CURDATE(), INTERVAL 200 DAY))");
        $this->insert("INSERT INTO season_config (season_id, lane_count, handicap_formula) VALUES ($seasonId, 2, 'standard')");
        $lanePair = $this->insert("INSERT INTO lane_pair (season_id, lane_a, lane_b) VALUES ($seasonId, 3, 4)");
        $matchday = $this->insert("INSERT INTO matchday (season_id, matchday_number, date) VALUES ($seasonId, 1, '$today')");
        $teams = [];
        $players = [];
        foreach (['home' => 'Adler', 'away' => 'Falken'] as $side => $name) {
            $team = $this->insert('INSERT INTO team () VALUES ()');
            $teams[$side] = $this->insert("INSERT INTO team_season (team_id, season_id, team_name) VALUES ($team, $seasonId, '$name')");
            for ($i = 1; $i <= 4; $i++) {
                $players[$side][$i] = $this->insert("INSERT INTO player (name, gender) VALUES ('$name $i', 'male')");
                $this->insert("INSERT INTO roster (team_season_id, player_id) VALUES ({$teams[$side]}, {$players[$side][$i]})");
            }
        }
        $fixture = $this->insert("INSERT INTO fixture (matchday_id, home_team_season_id, away_team_season_id, lane_pair_id) VALUES ($matchday, {$teams['home']}, {$teams['away']}, $lanePair)");

        // Switched off: no codes on the score sheet page.
        [, $sheet] = $this->get('/admin/results/score-sheet?fixture=' . $fixture);
        self::assertStringNotContainsString('/entry?code=', $sheet);

        $this->sendSuccessful('/admin/results', '/admin/results/team-entry', ['season_id' => (string) $seasonId, 'enabled' => '1'], 'jetzt selbst ein');
        self::assertSame(1, $this->numeral("SELECT team_entry FROM season WHERE id = $seasonId"));

        [, $sheet] = $this->get('/admin/results/score-sheet?fixture=' . $fixture);
        self::assertStringContainsString('Ergebniseingabe durch die Teams', $sheet);
        self::assertSame(2, preg_match_all('#/entry\?code=([A-Za-z0-9_-]{26})#', $sheet, $matches));
        [$home, $away] = $matches[1];
        $this->post('/admin/logout', []);

        [$status, $page, $header] = $this->get('/entry?code=' . $home);
        self::assertSame(200, $status);
        self::assertStringContainsString('Spiel 1 – wer spielt?', $page);
        self::assertStringContainsString('name="lineup[' . $players['home'][1] . ']"', $page);
        self::assertStringNotContainsString('name="lineup[' . $players['away'][1] . ']"', $page, 'Each team enters only its own players.');
        self::assertStringContainsString('Referrer-Policy: no-referrer', $header, 'The code must not leak via the Referer.');
        self::assertStringContainsString('Cache-Control: no-store', $header);
        self::assertSame(404, $this->get('/entry?code=' . substr($home, 0, -2) . 'xx')[0]);

        // "Weiter" with a missing result stays on the game with a message.
        [$status, $html] = $this->post('/entry/save', ['code' => $home, 'game' => '1', 'next' => '2', 'lineup' => [$players['home'][1] => 'plays']]);
        self::assertSame(422, $status);
        self::assertStringContainsString('fehlt das Ergebnis', $this->message($html));

        // Each team enters its own lineup and results game by game.
        foreach (['home' => [$home, 180], 'away' => [$away, 150]] as $side => [$code, $net]) {
            foreach ([1, 2, 3] as $game) {
                [$status, $html, $header] = $this->post('/entry/save', [
                    'code'   => $code,
                    'game'  => (string) $game,
                    'next'   => (string) ($game + 1),
                    'lineup' => array_fill_keys($players[$side], 'plays'),
                    'nets'   => array_fill_keys($players[$side], (string) $net),
                ]);
                self::assertSame(303, $status, $this->message($html));
                self::assertStringContainsString('step=' . ($game + 1), $header);
            }
        }
        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM game_result'), 'Nothing is public before the confirmation.');

        // Send, confirm the opponent's entry – in any order.
        foreach ([['submit', $home, 'sent'], ['confirm', $away, 'confirmed'], ['submit', $away, 'sent'], ['confirm', $home, 'final']] as [$action, $code, $notice]) {
            [$status, $html, $header] = $this->post('/entry/' . $action, ['code' => $code]);
            self::assertSame(303, $status, $action . ': ' . $this->message($html));
            self::assertStringContainsString('notice=' . $notice, $header);
        }

        // The confirmation step for team-entry.js: only the replaceable part.
        [$status, $part] = $this->get('/entry?part=1&step=5&code=' . $home);
        self::assertSame(200, $status);
        self::assertStringNotContainsString('<html', $part);
        self::assertStringContainsString('Die Ergebnisse stehen fest', $part);
        [, $html] = $this->get('/entry?code=' . $home . '&step=5&notice=final');
        self::assertStringContainsString('<p class="notice notice--ok" role="status">Die Ergebnisse stehen fest und sind veröffentlicht.</p>', $html);

        self::assertSame('complete', $this->text("SELECT completion_status FROM fixture WHERE id = $fixture"));
        self::assertSame(24, $this->numeral('SELECT COUNT(*) FROM game_result'));
        self::assertSame(1, $this->numeral("SELECT is_played FROM matchday WHERE id = $matchday"));
        self::assertSame(200, $this->get('/matchday/' . $matchday)[0], 'The matchday is public now.');

        // Afterwards only the admin changes anything.
        [$status, $html] = $this->post('/entry/save', ['code' => $home, 'game' => '1', 'next' => '2', 'nets' => array_fill_keys($players['home'], '200')]);
        self::assertSame(422, $status);
        self::assertStringContainsString('abgeschlossen', $html);

        // The history is never part of the team page …
        foreach (['/entry?code=' . $home, '/entry?part=1&code=' . $home] as $teamPage) {
            [, $html] = $this->get($teamPage);
            self::assertStringNotContainsString('Verlauf', $html, $teamPage);
            self::assertStringNotContainsString('– → 180', $html, $teamPage);
        }
        // … and not reachable without login.
        [$status, $html] = $this->get('/admin/results/score-sheet?fixture=' . $fixture);
        self::assertSame(401, $status);
        self::assertStringNotContainsString('Verlauf', $html);

        // The admin sees every entry with time and value before → after.
        $this->logIn();
        [, $sheet] = $this->get('/admin/results/score-sheet?fixture=' . $fixture);
        self::assertStringContainsString('<h2>Verlauf</h2>', $sheet);
        self::assertMatchesRegularExpression('#<td>\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2}</td><td>Adler \(Heim\)</td><td>Ergebnis</td><td>Adler 1</td><td>S1</td><td>– → 180</td>#', $sheet);
        self::assertStringContainsString('<td>Adler (Heim)</td><td>Aufstellung</td><td>Adler 1</td><td>S1</td><td>– → spielt</td>', $sheet);
        self::assertStringContainsString('<td>Endgültig gespeichert: Von beiden Teams bestätigt und gespeichert</td>', $sheet);

        // A later correction by the admin carries the account name.
        $correction = ['fixture_id' => (string) $fixture, 'action' => 'draft'];
        foreach (['home' => 150, 'away' => 180] as $side => $value) {
            foreach ($players[$side] as $playerId) {
                for ($game = 1; $game <= 3; $game++) {
                    $correction['net'][$side][$playerId][$game] = (string) ($side === 'away' && $playerId === $players['away'][1] && $game === 1 ? 155 : $value);
                }
            }
        }
        $correction['csrf_token'] = $this->csrfToken($sheet);
        [$status, $html] = $this->post('/admin/results/save', $correction);
        self::assertSame(200, $status, $this->message($html));
        self::assertStringContainsString('<td>Admin ' . self::USER . '</td><td>Ergebnis</td><td>Falken 1</td><td>S1</td><td>150 → 155</td>', $html);
    }

    public function testSecurityHeadersAndOriginalScoreSheetProtection(): void
    {
        [, , $header] = $this->get('/');
        self::assertStringContainsString("Content-Security-Policy: default-src 'self'", $header);
        self::assertStringContainsString('X-Frame-Options: SAMEORIGIN', $header);
        self::assertStringContainsString('Permissions-Policy: geolocation=(), microphone=()', $header);
        self::assertStringContainsString('X-Content-Type-Options: nosniff', $header);
        self::assertStringNotContainsString('Strict-Transport-Security', $header, 'HSTS only over HTTPS.');

        $season = $this->insert("INSERT INTO season (name, start_date, end_date) VALUES ('S', '2030-09-04', '2031-05-28')");
        $matchday = $this->insert("INSERT INTO matchday (season_id, matchday_number, date) VALUES ($season, 1, '2030-09-04')");
        $teams = [];
        foreach (['Adler', 'Falken'] as $name) {
            $team = $this->insert('INSERT INTO team () VALUES ()');
            $teams[] = $this->insert("INSERT INTO team_season (team_id, season_id, team_name) VALUES ($team, $season, '$name')");
        }
        $tp = $this->insert("INSERT INTO fixture (matchday_id, home_team_season_id, away_team_season_id, completion_status) VALUES ($matchday, {$teams[0]}, {$teams[1]}, 'in_progress')");
        $files = new \Bowlingliga\Persistence\FileSystemBlobStore(self::$scoreSheetDir);
        $sheet = (new \Bowlingliga\Persistence\OriginalScoreSheetRepository($this->database, $files))->save($tp, 'PNGDATEN', 'image/png');
        $etag = '"' . hash('sha256', 'PNGDATEN') . '"';

        self::assertSame(404, $this->get('/score-sheet/' . $sheet)[0], 'Not public before the confirmation.');

        $this->logIn();
        self::assertSame(200, $this->get('/score-sheet/' . $sheet)[0], 'Angemeldete Admins sehen ihn schon vorher.');
        $this->post('/admin/logout', []);

        $this->pdo->exec("UPDATE fixture SET completion_status = 'complete' WHERE id = $tp");
        self::assertSame(404, $this->get('/score-sheet/' . $sheet)[0], 'The original shows names: not public without public player data (R40.2).');
        $player = $this->insert("INSERT INTO player (name, gender) VALUES ('Anna', 'female')");
        [$status, $page] = $this->get('/player/' . $player);
        self::assertSame(404, $status, 'No public player page either (R40.2).');
        self::assertStringNotContainsString('Anna', $page);

        $this->logIn();
        self::assertSame(200, $this->get('/player/' . $player)[0], 'Logged-in admins see player data (R40.3).');
        self::assertSame(200, $this->get('/score-sheet/' . $sheet)[0]);
        $this->post('/admin/logout', []);

        $this->pdo->exec('UPDATE league_setting SET players_public = 1');
        self::assertSame(200, $this->get('/player/' . $player)[0], 'Public player data (R40.2).');
        [$status, $content, $header] = $this->get('/score-sheet/' . $sheet);
        self::assertSame(200, $status);
        self::assertSame('PNGDATEN', $content);
        self::assertStringContainsString('Content-Type: image/png', $header);
        self::assertStringContainsString("Content-Security-Policy: default-src 'none'; img-src 'self';", $header, 'Images get a narrow CSP of their own.');
        self::assertStringNotContainsString("default-src 'self'", $header, 'Not the page CSP.');
        self::assertStringContainsString('ETag: ' . $etag, $header, 'The hash of the immutable file serves as ETag.');

        $pdf = (new \Bowlingliga\Persistence\OriginalScoreSheetRepository($this->database, $files))->save($tp, '%PDF-DATEN', 'application/pdf');
        [$status, , $header] = $this->get('/score-sheet/' . $pdf);
        self::assertSame(200, $status);
        self::assertStringNotContainsString('Content-Security-Policy', $header, 'PDFs go without a CSP for the browsers\' PDF viewers.');

        curl_setopt($this->http, CURLOPT_HTTPHEADER, ['If-None-Match: ' . $etag]);
        self::assertSame(304, $this->get('/score-sheet/' . $sheet)[0], 'Unchanged content is not sent again.');
        curl_setopt($this->http, CURLOPT_HTTPHEADER, []);

        // A missing file is lost raw data, not "not found".
        $files->delete(hash('sha256', 'PNGDATEN'));
        self::assertSame(500, $this->get('/score-sheet/' . $sheet)[0]);
    }

    private function insert(string $sql): int
    {
        $this->pdo->exec($sql);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * The admin bar only appears after login and marks the area of the current
     * page.
     */
    public function testAdminBarOnlyAfterLogin(): void
    {
        [, $html] = $this->get('/admin/login');
        self::assertStringNotContainsString('class="admin-bar"', $html);

        [, $html] = $this->get('/admin/master-data');
        self::assertStringNotContainsString('class="admin-bar"', $html, 'The denied page shows no bar either.');

        $this->logIn();

        [, $html] = $this->get('/admin/master-data');
        self::assertStringContainsString('class="admin-bar"', $html);
        self::assertStringContainsString('href="/admin/master-data" aria-current="page">Stammdaten</a>', $html);
        foreach (['/admin', '/admin/season-planning', '/admin/results', '/admin/print', '/admin/honours', '/admin/home-page', '/admin/rules'] as $link) {
            self::assertStringContainsString('class="admin-bar__link" href="' . $link . '"', $html, $link);
        }
    }

    /**
     * R22.5: even when logged in, a writing action with an invalid CSRF token
     * is refused.
     */
    public function testInvalidCsrfTokenIsRefused(): void
    {
        $this->logIn();

        [$status, $html] = $this->post('/admin/master-data/player-create', [
            'csrf_token' => 'gefaelscht',
            'name'       => 'Anna',
            'gender' => 'female',
        ]);

        self::assertSame(400, $status);
        self::assertStringContainsString('Die Sitzung ist abgelaufen', $html);
        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM player'));
    }

    /**
     * R28.10–R28.14: end-to-end flow across the functional areas – create
     * master data, apply the schedule, enter and confirm results, then printed
     * score sheets and honours preview on this data; after every step the data
     * is checked.
     */
    public function testMasterDataSeasonPlanningAndResultEntryEndToEnd(): void
    {
        $this->logIn();

        // --- MasterData (R28.10) ---
        $this->sendSuccessful('/admin/master-data', '/admin/master-data/season-create', [
            'name' => 'Saison 2030', 'start_date' => '2030-09-04', 'end_date' => '2030-10-30',
        ], 'Saison wurde angelegt.');
        $seasonId = $this->numeral("SELECT id FROM season WHERE name = 'Saison 2030'");
        self::assertSame(2, $this->numeral('SELECT lane_count FROM season_config WHERE season_id = ?', [$seasonId]));

        // Rules page: the handicap table tab only appears with a readable
        // formula of the current season.
        $this->pdo->exec("UPDATE season_config SET handicap_formula = NULL WHERE season_id = $seasonId");
        self::assertStringNotContainsString('Handicap-Tabelle', $this->get('/rules')[1]);
        $this->pdo->exec("UPDATE season_config SET handicap_formula = 'HDC = (Zielwert - Durchschnitt) * 0,8' WHERE season_id = $seasonId");
        [$status, $html] = $this->get('/rules');
        self::assertSame(200, $status);
        self::assertStringContainsString('Handicap-Tabelle', $html);
        self::assertStringContainsString('Saison 2030', $html);
        self::assertStringContainsString('<tr><td>≤ 120</td><td>64</td></tr>', $html, 'New season with target score 200.');

        foreach (['Adler', 'Falken'] as $teamLabel) {
            $this->sendSuccessful('/admin/master-data', '/admin/master-data/team-create', [
                'season_id' => (string) $seasonId, 'team_name' => $teamLabel,
            ], 'Team wurde angelegt.');
        }
        $teams = $this->column('SELECT id FROM team_season WHERE season_id = ? AND is_ghost_team = 0 ORDER BY team_name', [$seasonId]);
        self::assertCount(2, $teams);

        $names = ['Anna', 'Bernd', 'Carla', 'Dirk', 'Erika', 'Fritz', 'Gisela', 'Hans'];
        foreach ($names as $index => $name) {
            $this->sendSuccessful('/admin/master-data', '/admin/master-data/player-create', [
                'name' => $name, 'gender' => $index % 2 === 0 ? 'female' : 'male',
            ], 'Spieler wurde angelegt.');
            $playerId = $this->numeral('SELECT id FROM player WHERE name = ?', [$name]);
            $this->sendSuccessful('/admin/master-data', '/admin/master-data/roster-assign', [
                'team_season_id' => (string) $teams[intdiv($index, 4)], 'player_id' => (string) $playerId,
            ], 'Spieler wurde dem Kader hinzugefügt.');
        }
        self::assertSame(8, $this->numeral('SELECT COUNT(*) FROM player'));

        // After a roster action the tab "Teams" stays selected.
        [, $html] = $this->post('/admin/master-data/roster-assign', [
            'csrf_token' => $this->csrfToken($this->get('/admin/master-data')[1]), 'team_season_id' => (string) $teams[0], 'player_id' => '999999',
        ]);
        self::assertMatchesRegularExpression('#tab=teams[^"]*" aria-current="page"#', $html);

        // R6.8: Anna (roster Adler) cannot additionally be assigned to Falken.
        [, $html] = $this->post('/admin/master-data/roster-assign', [
            'csrf_token'     => $this->csrfToken($this->get('/admin/master-data')[1]),
            'team_season_id' => (string) $teams[1],
            'player_id'     => (string) $this->numeral("SELECT id FROM player WHERE name = 'Anna'"),
        ]);
        self::assertStringContainsString('Anna spielt in dieser Saison bereits für Adler.', $html);
        self::assertSame([4, 4], array_map('intval', $this->column(
            'SELECT COUNT(*) FROM roster WHERE team_season_id IN (?, ?) GROUP BY team_season_id ORDER BY team_season_id',
            $teams
        )));

        // --- Season: change the configuration before the season start (R8.16, R12.7) ---
        $this->sendSuccessful('/admin/season?season=' . $seasonId, '/admin/season/configuration', [
            'season_id' => (string) $seasonId, 'lane_count' => '2', 'entry_fee' => '7,50',
            'blind_fee' => '2,50', 'max_handicap' => '64', 'handicap_factor' => '0,8', 'target_score' => '190',
            'ghost_score' => '185', 'previous_season_matchdays' => '4', 'positionday_interval' => '10',
            'win_points' => '3', 'draw_points' => '1', 'loss_points' => '0',
            'bonus_threshold' => '2100', 'bonus_step' => '100', 'bonus_maximum' => '6',
        ], 'Die Konfiguration der Saison wurde gespeichert.');
        self::assertSame(3, $this->numeral('SELECT win_points FROM season_config WHERE season_id = ?', [$seasonId]), 'R17.11');
        self::assertSame('7.50', $this->text('SELECT entry_fee FROM season_config WHERE season_id = ?', [$seasonId]));
        self::assertSame(185, $this->numeral('SELECT ghost_score FROM season_config WHERE season_id = ?', [$seasonId]));
        self::assertSame(4, $this->numeral('SELECT previous_season_matchdays FROM season_config WHERE season_id = ?', [$seasonId]));
        self::assertSame(
            'HDC = (Zielwert - Durchschnitt) * 0.8',
            $this->text('SELECT handicap_formula FROM season_config WHERE season_id = ?', [$seasonId])
        );

        // --- Season planning and lane assignment (R28.11) ---
        [$status, $preview] = $this->get('/admin/season-planning/preview?season=' . $seasonId);
        self::assertSame(200, $status);
        self::assertSame(1, preg_match('/name="fingerprint" value="([^"]+)"/', $preview, $matches));

        [$status, $html] = $this->post('/admin/season-planning/apply', [
            'csrf_token'    => $this->csrfToken($preview),
            'season_id'     => (string) $seasonId,
            'fingerprint' => html_entity_decode($matches[1]),
            'confirmed'    => '1',
        ]);
        self::assertSame(200, $status, $this->message($html));
        self::assertStringContainsString('samt Bahnzuordnung gespeichert', $html);

        $matchdays = $this->numeral('SELECT COUNT(*) FROM matchday WHERE season_id = ?', [$seasonId]);
        self::assertGreaterThan(0, $matchdays);
        self::assertSame(0, $this->numeral(
            'SELECT COUNT(*) FROM fixture fx JOIN matchday md ON md.id = fx.matchday_id
             WHERE md.season_id = ? AND fx.lane_pair_id IS NULL',
            [$seasonId]
        ), 'Every fixture has a lane assignment (R18).');

        $teamFixture = $this->pdo->prepare(
            "SELECT fx.id, fx.home_team_season_id AS home, fx.away_team_season_id AS away, md.id AS matchday
             FROM fixture fx JOIN matchday md ON md.id = fx.matchday_id
             WHERE md.season_id = ? ORDER BY md.matchday_number, fx.id LIMIT 1"
        );
        $teamFixture->execute([$seasonId]);
        $fixture = $teamFixture->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($fixture);
        $tp = (int) $fixture['id'];

        // --- ResultEntry (R28.12): results per roster player, the lineup follows ---
        [, $sheet] = $this->get('/admin/results/score-sheet?fixture=' . $tp);
        $net = [];
        foreach (['home' => 150, 'away' => 140] as $page => $base) {
            $roster = $this->column('SELECT player_id FROM roster WHERE team_season_id = ? ORDER BY player_id', [(int) $fixture[$page]]);
            foreach (array_slice($roster, 0, 4) as $playerId) {
                self::assertStringContainsString('name="net[' . $page . '][' . $playerId . '][1]"', $sheet, 'Every roster player has a row.');
                $net[$page][$playerId] = [1 => (string) $base, 2 => (string) ($base + 10), 3 => (string) ($base + 20)];
            }
        }
        [$status, $html] = $this->post('/admin/results/save', [
            'csrf_token'     => $this->csrfToken($sheet),
            'fixture_id' => (string) $tp,
            'action'         => 'confirm',
            'net'          => $net,
        ]);
        self::assertSame(200, $status, $this->message($html));

        self::assertSame(24, $this->numeral(
            'SELECT COUNT(*) FROM game_result e JOIN lineup a ON a.id = e.lineup_id WHERE a.fixture_id = ?',
            [$tp]
        ));
        self::assertSame(8, $this->numeral('SELECT COUNT(*) FROM lineup WHERE fixture_id = ?', [$tp]));
        self::assertSame(4 * (150 + 160 + 170), $this->numeral(
            'SELECT SUM(e.net) FROM game_result e JOIN lineup a ON a.id = e.lineup_id
             WHERE a.fixture_id = ? AND a.team_season_id = ?',
            [$tp, (int) $fixture['home']]
        ));
        self::assertSame('complete', $this->text('SELECT completion_status FROM fixture WHERE id = ?', [$tp]));

        // A correction with "Handicap-Kette neu berechnen" leads to the preview (R12.18).
        [$status, $html, $header] = $this->post('/admin/results/save', [
            'csrf_token'     => $this->csrfToken($sheet),
            'fixture_id' => (string) $tp,
            'action'         => 'confirm',
            'recalculate_chain'      => '1',
            'net'          => $net,
        ]);
        self::assertSame(303, $status, $this->message($html));
        self::assertStringContainsString('Location: /admin/results/handicap-chain?fixture=' . $tp, $header);
        [$status, $html] = $this->get('/admin/results/handicap-chain?fixture=' . $tp);
        self::assertSame(200, $status);
        self::assertStringContainsString('Handicap-Kette neu berechnen', $html);
        [$status] = $this->post('/admin/results/handicap-chain', ['csrf_token' => 'falsch', 'fixture_id' => (string) $tp, 'confirmed' => '1']);
        self::assertSame(400, $status, 'Invalid CSRF token (R22.5).');

        // --- Printed score sheets (R28.13) on the planned data ---
        [$status, $pdf, $header] = $this->get('/admin/print?fixture=' . $tp);
        self::assertSame(200, $status);
        self::assertMatchesRegularExpression('#Content-Type: (application/pdf|text/html)#i', $header);
        self::assertNotSame('', $pdf);

        // Printing all score sheets of the matchday: selection, then PDF.
        [$status, $html] = $this->get('/admin/print?season=' . $seasonId);
        self::assertSame(200, $status);
        self::assertStringContainsString('name="matchday"', $html);
        [$status, $pdf, $header] = $this->get('/admin/print?matchday=' . $fixture['matchday']);
        self::assertSame(200, $status, $this->message($pdf));
        self::assertMatchesRegularExpression('#Content-Type: (application/pdf|text/html)#i', $header);

        // --- Honours preview (R28.14) before the end of the season ---
        [$status, $html] = $this->get('/admin/honours?season=' . $seasonId);
        self::assertSame(200, $status);
        self::assertStringContainsString('Ehrungs-Vorschau', $html);
    }

    /**
     * Lot draw: chosen per matchday in the planning preview, planned without
     * fixtures, blank score sheets before the draw, fixtures entered on the
     * season page afterwards.
     */
    public function testLotDrawFromPreviewToEnteredPairings(): void
    {
        $this->logIn();
        $seasonId = $this->insert("INSERT INTO season (name, start_date, end_date, matchday_count) VALUES ('Saison 2099', '2099-09-02', '2100-05-26', 3)");
        $this->insert("INSERT INTO season_config (season_id, lane_count, handicap_formula) VALUES ($seasonId, 2, 'standard')");
        $teams = [];
        foreach (['Adler', 'Falken'] as $name) {
            $team = $this->insert('INSERT INTO team () VALUES ()');
            $teams[$name] = $this->insert("INSERT INTO team_season (team_id, season_id, team_name, is_ghost_team) VALUES ($team, $seasonId, '$name', 0)");
        }

        [$status, $preview] = $this->get("/admin/season-planning/preview?season=$seasonId&types%5B2%5D=lot_draw");
        self::assertSame(200, $status);
        self::assertStringContainsString('<option value="lot_draw" selected>Losentscheid</option>', $preview);
        self::assertStringContainsString('Paarungen werden vor Ort ausgelost', $preview);
        self::assertStringContainsString('<input type="hidden" name="types[2]" value="lot_draw">', $preview);
        self::assertSame(1, preg_match('/name="fingerprint" value="([^"]+)"/', $preview, $matches));

        [$status, $html] = $this->post('/admin/season-planning/apply', [
            'csrf_token' => $this->csrfToken($preview), 'season_id' => (string) $seasonId,
            'fingerprint' => html_entity_decode($matches[1]), 'confirmed' => '1',
            'types' => [1 => 'regular', 2 => 'lot_draw', 3 => 'regular'],
        ]);
        self::assertSame(200, $status, $this->message($html));
        $lotDraw = $this->numeral("SELECT id FROM matchday WHERE season_id = $seasonId AND matchday_type = 'lot_draw'");
        self::assertSame(2, $this->numeral("SELECT matchday_number FROM matchday WHERE id = $lotDraw"));
        self::assertSame(0, $this->numeral("SELECT COUNT(*) FROM fixture WHERE matchday_id = $lotDraw"));

        [$status, $pdf, $header] = $this->get("/admin/print?matchday=$lotDraw");
        self::assertSame(200, $status);
        self::assertStringContainsString('application/pdf', $header);
        self::assertSame(2, preg_match_all('#/Type\s*/Page[^s]#', $pdf), 'One half-prefilled score sheet per team.');

        [, $page] = $this->get("/admin/season?season=$seasonId");
        self::assertStringContainsString('<h2>Losentscheide</h2>', $page);
        [$status, $html] = $this->post('/admin/season/lot-draw', [
            'csrf_token' => $this->csrfToken($page), 'season_id' => (string) $seasonId, 'matchday_id' => (string) $lotDraw,
            'home' => [(string) $teams['Falken']], 'away' => [(string) $teams['Adler']],
        ]);
        self::assertSame(200, $status, $this->message($html));
        self::assertStringContainsString('Die ausgelosten Paarungen wurden gespeichert', $html);
        self::assertSame($teams['Falken'], $this->numeral("SELECT home_team_season_id FROM fixture WHERE matchday_id = $lotDraw"));
    }

    /**
     * R6: the template of the import file is offered for download, in the
     * visitor's language, and only to logged-in admins.
     */
    public function testImportTemplateDownload(): void
    {
        [$status] = $this->get('/admin/master-data/import-template');
        self::assertSame(401, $status, 'Without login no template.');

        $this->logIn();
        [$status, $csv, $header] = $this->get('/admin/master-data/import-template');

        self::assertSame(200, $status);
        self::assertStringContainsString('Content-Type: text/csv; charset=utf-8', $header);
        self::assertStringContainsString('Content-Disposition: attachment; filename="spieler-import.csv"', $header);
        self::assertStringStartsWith("\xEF\xBB\xBFTeam;Name;Geschlecht;Vorjahr Schnitt;Vorjahr Spiele\r\n", $csv);
    }

    /**
     * Player import: the uploaded CSV is shown as a preview without saving,
     * the confirmation imports teams and players; an invalid token is refused.
     */
    public function testPlayerImportWithPreviewAndConfirmation(): void
    {
        $this->logIn();
        $this->sendSuccessful('/admin/master-data', '/admin/master-data/season-create', [
            'name' => 'Saison 2099', 'start_date' => '2099-09-02', 'end_date' => '2100-05-26',
        ], 'Saison wurde angelegt.');
        $seasonId = $this->numeral("SELECT id FROM season WHERE name = 'Saison 2099'");
        $file = "Team;Name;Geschlecht;Vorjahr Schnitt;Vorjahr Spiele\nPin Busters;Kurt;m;143,64;99\nJolly Rollers;Alma;w;;\n";

        [$status, $form] = $this->get('/admin/master-data?tab=import&season=' . $seasonId);
        self::assertSame(200, $status);
        self::assertStringContainsString('action="/admin/master-data/import-preview" enctype="multipart/form-data"', $form);

        [$status, $preview] = $this->postFile('/admin/master-data/import-preview', [
            'csrf_token' => $this->csrfToken($form), 'season_id' => (string) $seasonId,
        ], 'file', 'spieler.csv', $file);
        self::assertSame(200, $status);
        self::assertStringContainsString('2 Spieler für Saison 2099 · 2 neue Teams · 0 vorhandene Teams · 1 mit Vorjahr', $preview);
        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM player'), 'The preview saves nothing.');

        [$status] = $this->post('/admin/master-data/import', ['csrf_token' => 'falsch', 'season_id' => (string) $seasonId, 'csv' => base64_encode($file)]);
        self::assertSame(400, $status, 'Invalid CSRF token.');
        self::assertSame(0, $this->numeral('SELECT COUNT(*) FROM player'));

        self::assertSame(1, preg_match('/name="csv" value="([^"]+)"/', $preview, $match));
        [$status, $html] = $this->post('/admin/master-data/import', [
            'csrf_token' => $this->csrfToken($preview), 'season_id' => (string) $seasonId, 'csv' => html_entity_decode($match[1]),
        ]);
        self::assertSame(200, $status);
        self::assertStringContainsString('2 Spieler importiert, 2 Teams neu angelegt, 1 mit Vorjahr.', $html);
        self::assertSame(2, $this->numeral('SELECT COUNT(*) FROM roster'));
        self::assertSame(14220, $this->numeral('SELECT net_pins_total FROM previous_season_record'));
    }

    /**
     * R38: the league name is saved in the area "Liga" and then appears in
     * the page header and on the home page; an invalid token (R22.5) or an
     * empty name change nothing.
     */
    public function testLeagueNameIsSavedAndShownEverywhere(): void
    {
        $this->logIn();
        [, $form] = $this->get('/admin/league');
        self::assertStringContainsString('value="Bowlingliga"', $form, 'Default of the schema (R38.2).');

        [$status] = $this->post('/admin/league/save', ['name' => 'Gefälscht', 'csrf_token' => 'falsch']);
        self::assertSame(400, $status, 'Invalid CSRF token (R22.5).');
        [$status] = $this->post('/admin/league/save', ['name' => '  ', 'csrf_token' => $this->csrfToken($form)]);
        self::assertSame(422, $status, 'Leerer Name (R38.3).');
        self::assertSame('Bowlingliga', $this->text('SELECT name FROM league_setting'));

        self::assertStringNotContainsString('name="players_public" value="1" checked', $form, 'Player data is not public in a new installation (R40.1).');
        [$status, , $header] = $this->post('/admin/league/save', ['name' => ' Pin & Spare Liga ', 'players_public' => '1', 'csrf_token' => $this->csrfToken($form)]);
        self::assertSame(303, $status);
        self::assertStringContainsString('Location: /admin/league?saved=1', $header);
        self::assertSame('Pin & Spare Liga', $this->text('SELECT name FROM league_setting'));
        self::assertSame('1', $this->text('SELECT players_public FROM league_setting'), 'Saved together with the name (R40.1).');

        [, $html] = $this->get('/admin/league?saved=1');
        self::assertStringContainsString('Die Einstellungen der Liga wurden gespeichert.', $html);
        self::assertStringContainsString('<span>Pin &amp; Spare Liga</span>', $html, 'Header after the redirect (R38.4).');

        [, $home] = $this->get('/');
        self::assertStringContainsString('<title>Pin &amp; Spare Liga</title>', $home);
        self::assertStringContainsString('<h1>Pin &amp; Spare Liga</h1>', $home);
    }

    /**
     * R28.16/R28.17: create a home page post and save the rules; both are
     * persisted and publicly visible.
     */
    public function testCmsEditIsPersistedAndShownPublicly(): void
    {
        $this->logIn();

        $this->sendSuccessful('/admin/home-page', '/admin/home-page/create', [
            'title' => 'Saisonauftakt', 'text' => 'Am Mittwoch geht es los.',
        ]);
        self::assertSame(1, $this->numeral("SELECT COUNT(*) FROM home_page_post WHERE title = 'Saisonauftakt'"));

        $this->sendSuccessful('/admin/rules', '/admin/rules/save', [
            'text' => '<p>Es wird mit Handicap gespielt.</p>',
        ]);
        self::assertStringContainsString('Es wird mit Handicap gespielt.', $this->text('SELECT text FROM rules LIMIT 1'));

        self::assertStringContainsString('Saisonauftakt', $this->get('/')[1]);
        self::assertStringContainsString('Es wird mit Handicap gespielt.', $this->get('/rules')[1]);
    }

    /**
     * Appearance toggle without JavaScript: the form sets style and mode as
     * cookies, redirects back to the page and only allows local return targets;
     * the next page appears in the chosen design.
     */
    /**
     * R28.18: without a choice the running season counts, even if next year's
     * season has already been created; a chosen season is kept on every page –
     * publicly via `?season=`, in the admin area via the admin session (also
     * across forms and links without the parameter).
     */
    public function testTheChosenSeasonIsKeptPubliclyAndInTheAdminArea(): void
    {
        $today = new \DateTimeImmutable();
        $old = $this->insert(sprintf("INSERT INTO season (name, start_date, end_date) VALUES ('Alt', '%s', '%s')", $today->modify('-2 years')->format('Y-m-d'), $today->modify('-1 year')->format('Y-m-d')));
        $running = $this->insert(sprintf("INSERT INTO season (name, start_date, end_date) VALUES ('Laufend', '%s', '%s')", $today->modify('-1 month')->format('Y-m-d'), $today->modify('+6 months')->format('Y-m-d')));
        $this->insert(sprintf("INSERT INTO season (name, start_date, end_date) VALUES ('Nächste', '%s', '%s')", $today->modify('+1 year')->format('Y-m-d'), $today->modify('+2 years')->format('Y-m-d')));
        $oldMatchday = $this->insert("INSERT INTO matchday (season_id, matchday_number, date) VALUES ($old, 1, '" . $today->modify('-2 years')->format('Y-m-d') . "')");

        // Public, without a choice: the running season, links without parameter.
        [, $html] = $this->get('/');
        self::assertStringContainsString('<a class="header__brand" href="/">', $html);
        self::assertStringContainsString('href="/matchdays"', $html);

        // Public, chosen season: every navigation item and the logo keep it.
        [, $html] = $this->get("/rules?season=$old");
        foreach (['/', '/matchdays', '/table', '/honours', '/rules'] as $path) {
            self::assertStringContainsString("href=\"$path?season=$old\"", $html, "Navigation $path");
        }
        self::assertStringContainsString("<a class=\"header__brand\" href=\"/?season=$old\">", $html);

        // The matchday page keeps the season of the matchday, even without a parameter.
        [, $html] = $this->get("/matchday/$oldMatchday");
        self::assertStringContainsString("href=\"/table?season=$old\"", $html);

        // Admin: without a choice the running season.
        $this->logIn();
        [, $html] = $this->get('/admin/results');
        self::assertStringContainsString("<option value=\"$running\" selected>Laufend (aktuell)</option>", $html);
        self::assertStringNotContainsString('Nicht die aktuelle Saison', $html);

        // Chosen once, kept on further pages without the parameter …
        $this->get("/admin/results?season=$old");
        [, $html] = $this->get('/admin/season');
        self::assertStringContainsString("<option value=\"$old\" selected>Alt</option>", $html);
        self::assertStringContainsString('Nicht die aktuelle Saison', $html);
        self::assertStringContainsString("href=\"/matchdays?season=$old\"", $html, 'The public navigation in the admin area follows.');

        // … and after a form: the result overview of the chosen season follows.
        [, $html] = $this->get('/admin/results');
        [$status, $html] = $this->post('/admin/results/team-entry', ['csrf_token' => $this->csrfToken($html), 'season_id' => $old, 'enabled' => '1']);
        self::assertSame(200, $status);
        self::assertStringContainsString("<option value=\"$old\" selected>Alt</option>", $html);
        self::assertStringContainsString("name=\"season_id\" value=\"$old\"", $html);

        // Back to the current season.
        [, $html] = $this->get("/admin?season=$running");
        self::assertStringNotContainsString('Nicht die aktuelle Saison', $html);
    }

    /**
     * The admin area in English: every page and a validation error appear in
     * the chosen language, without a German text of the catalog or a key
     * without its text.
     */
    public function testAdminAreaInEnglishWithoutGermanLeftovers(): void
    {
        $this->logIn();
        $seasonId = $this->insert("INSERT INTO season (name, start_date, end_date, matchday_count) VALUES ('Season 2099', '2099-09-02', '2100-05-26', 3)");
        $this->insert("INSERT INTO season_config (season_id, lane_count, handicap_formula) VALUES ($seasonId, 4, 'standard')");
        foreach (['Eagles', 'Falcons', 'Owls', 'Ravens'] as $index => $name) {
            $team = $this->insert('INSERT INTO team () VALUES ()');
            $teamSeason = $this->insert("INSERT INTO team_season (team_id, season_id, team_name, is_ghost_team) VALUES ($team, $seasonId, '$name', 0)");
            $player = $this->insert("INSERT INTO player (name, gender) VALUES ('Player $index', 'male')");
            $this->insert("INSERT INTO roster (team_season_id, player_id) VALUES ($teamSeason, $player)");
        }
        [, $preview] = $this->get("/admin/season-planning/preview?season=$seasonId");
        self::assertSame(1, preg_match('/name="fingerprint" value="([^"]+)"/', $preview, $matches));
        [$status, $html] = $this->post('/admin/season-planning/apply', [
            'csrf_token' => $this->csrfToken($preview), 'season_id' => (string) $seasonId,
            'fingerprint' => html_entity_decode($matches[1]), 'confirmed' => '1',
            'types' => [1 => 'regular', 2 => 'regular', 3 => 'regular'],
        ]);
        self::assertSame(200, $status, $this->message($html));
        $fixture = $this->numeral("SELECT fx.id FROM fixture fx JOIN matchday md ON md.id = fx.matchday_id WHERE md.season_id = $seasonId ORDER BY fx.id LIMIT 1");

        [$status, , $header] = $this->post('/appearance', ['style' => 'lane', 'mode' => 'auto', 'language' => 'en', 'return_to' => '/admin']);
        self::assertSame(303, $status);
        self::assertStringContainsString('Set-Cookie: bl_lang=en', $header);

        $html = '';
        foreach ([
            '/admin', '/admin/master-data?tab=players', '/admin/master-data?tab=seasons', "/admin/master-data?tab=teams&season=$seasonId",
            "/admin/master-data?tab=matchdays&season=$seasonId", '/admin/master-data?tab=accounts', '/admin/master-data?tab=import',
            "/admin/season?season=$seasonId", "/admin/season-planning?season=$seasonId", "/admin/season-planning/preview?season=$seasonId",
            "/admin/results?season=$seasonId", "/admin/results/score-sheet?fixture=$fixture", "/admin/print?season=$seasonId",
            "/admin/honours?season=$seasonId", '/admin/home-page', '/admin/rules', '/admin/account',
        ] as $page) {
            [$status, $body] = $this->get($page);
            self::assertSame(200, $status, $page . ': ' . $this->message($body));
            self::assertStringContainsString('<html lang="en"', $body, $page);
            $html .= $body;
        }
        // The season's language of the printed score sheets preselects the print form.
        self::assertStringContainsString('<option value="de" selected>Deutsch</option>', $this->get("/admin/print?season=$seasonId")[1]);
        [, $page] = $this->get("/admin/season?season=$seasonId");
        [$status, $saved] = $this->post('/admin/season/master-data', [
            'csrf_token' => $this->csrfToken($page), 'season_id' => (string) $seasonId, 'name' => 'Season 2099',
            'start_date' => '2099-09-02', 'end_date' => '2100-05-26', 'score_sheet_language' => 'en',
        ]);
        self::assertSame(200, $status, $this->message($saved));
        self::assertSame(1, $this->numeral("SELECT COUNT(*) FROM season WHERE id = $seasonId AND score_sheet_language = 'en'"));
        self::assertStringContainsString('<option value="en" selected>English</option>', $this->get("/admin/print?season=$seasonId")[1]);
        $matchday = $this->numeral("SELECT matchday_id FROM fixture WHERE id = $fixture");
        [$status, , $header] = $this->get("/admin/print?matchday=$matchday&language=de");
        self::assertSame(200, $status);
        self::assertMatchesRegularExpression('#Content-Type: (application/pdf|text/html)#i', $header);

        [, $form] = $this->get('/admin/master-data?tab=seasons');
        [, $error] = $this->post('/admin/master-data/season-create', ['csrf_token' => $this->csrfToken($form), 'name' => '', 'start_date' => '2099-01-01', 'end_date' => '2099-06-30']);
        self::assertStringContainsString('The field Season name must be between 1 and 100 characters long.', $error);
        $html .= $error;

        // The import help names the German column names of the file on purpose.
        $text = strip_tags((string) preg_replace('#<(pre|strong)\b.*?</\1>#s', '', $html));
        self::assertDoesNotMatchRegularExpression('/\b(admin|common|error|field|validation|results|season|planning|master_data|import|print|history|account|cms|team_entry|value|layout)\.[a-z_]+(\.[a-z_]+)*\b/', $text, 'No key without its text.');
        $german = Translator::catalog('de');
        $english = Translator::catalog('en');
        foreach ($german as $key => $value) {
            if (mb_strlen($value) >= 8 && $value !== $english[$key] && ! str_contains($value, '{')) {
                self::assertStringNotContainsString(htmlspecialchars($value), $text, "German text of $key");
            }
        }
    }

    public function testAppearanceIsSavedViaForm(): void
    {
        [$status, , $header] = $this->post('/appearance', ['style' => 'bento', 'mode' => 'dark', 'return_to' => '/rules']);

        self::assertSame(303, $status);
        self::assertStringContainsString('Location: /rules', $header);
        self::assertStringContainsString('Set-Cookie: bl_style=bento', $header);
        self::assertStringContainsString('Set-Cookie: bl_mode=dark', $header);

        [, $html] = $this->get('/rules');
        self::assertStringContainsString('<html lang="de" data-style="bento" data-mode="dark">', $html);

        [, , $header] = $this->post('/appearance', ['style' => 'lane', 'mode' => 'light', 'return_to' => '//example.org/']);
        self::assertStringContainsString("Location: /\r\n", $header, 'Foreign return targets are discarded.');

        // Browsers drop the tab from the address, which would leave //example.org.
        [, , $header] = $this->post('/appearance', ['style' => 'lane', 'mode' => 'light', 'return_to' => "/\t/example.org/"]);
        self::assertStringContainsString("Location: /\r\n", $header, 'Return targets with control characters are discarded.');
    }

    // --- Hilfsfunktionen ------------------------------------------------

    private function logIn(): void
    {
        [, $html] = $this->get('/admin/login');
        [$status, , $header] = $this->post('/admin/login', [
            'csrf_token'   => $this->csrfToken($html),
            'username' => self::USER,
            'password'     => self::PASSWORD,
        ]);

        self::assertSame(302, $status, 'The login must succeed.');
        self::assertStringContainsString('Location: /admin', $header);
        // The browser becomes a trusted device (R5.10).
        self::assertMatchesRegularExpression('/Set-Cookie: bl_device=[A-Za-z0-9_-]{43};[^\r\n]*path=\/;[^\r\n]*HttpOnly;[^\r\n]*SameSite=Strict/i', $header);
        self::assertGreaterThan(0, (int) $this->pdo->query('SELECT COUNT(*) FROM trusted_device')->fetchColumn());
    }

    /**
     * Loads the form page (for the CSRF token of the session), submits the
     * action and expects a successful response without an error message.
     *
     * @param array<string, mixed> $fields
     */
    private function sendSuccessful(string $formPage, string $action, array $fields, ?string $expectedMessage = null): string
    {
        [, $form] = $this->get($formPage);
        [$status, $html] = $this->post($action, $fields + ['csrf_token' => $this->csrfToken($form)]);

        self::assertSame(200, $status, $action . ': ' . $this->message($html));
        self::assertStringNotContainsString('class="error"', $html, $action . ': ' . $this->message($html));
        if ($expectedMessage !== null) {
            self::assertStringContainsString($expectedMessage, $html, $action);
        }

        return $html;
    }

    /**
     * @return array{0: int, 1: string, 2: string} Status, Rumpf, Kopfzeilen
     */
    private function get(string $pathName): array
    {
        curl_setopt_array($this->http, [
            CURLOPT_URL     => self::$base . $pathName,
            CURLOPT_HTTPGET => true,
        ]);

        return $this->perform();
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{0: int, 1: string, 2: string} Status, Rumpf, Kopfzeilen
     */
    private function post(string $pathName, array $fields): array
    {
        curl_setopt_array($this->http, [
            CURLOPT_URL        => self::$base . $pathName,
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
        ]);

        return $this->perform();
    }

    /**
     * POST as multipart/form-data with one uploaded file.
     *
     * @param array<string, string> $fields
     *
     * @return array{0: int, 1: string, 2: string} Status, Rumpf, Kopfzeilen
     */
    private function postFile(string $pathName, array $fields, string $fileField, string $fileName, string $content): array
    {
        curl_setopt_array($this->http, [
            CURLOPT_URL        => self::$base . $pathName,
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => $fields + [$fileField => new \CURLStringFile($content, $fileName, 'text/csv')],
        ]);

        return $this->perform();
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function perform(): array
    {
        $response = curl_exec($this->http);
        self::assertIsString($response, 'HTTP-Anfrage fehlgeschlagen: ' . curl_error($this->http));

        $headerLength = (int) curl_getinfo($this->http, CURLINFO_HEADER_SIZE);

        return [
            (int) curl_getinfo($this->http, CURLINFO_RESPONSE_CODE),
            substr($response, $headerLength),
            substr($response, 0, $headerLength),
        ];
    }

    private function csrfToken(string $html): string
    {
        self::assertSame(1, preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches), 'The page contains a CSRF token.');

        return html_entity_decode($matches[1]);
    }

    /**
     * Error/success message of a page as diagnostic text for assertions.
     */
    private function message(string $html): string
    {
        return preg_match('/<p class="(?:error|erfolg)[^"]*"[^>]*>(.*?)<\/p>/s', $html, $matches) === 1
            ? html_entity_decode(strip_tags($matches[1]))
            : '(keine Meldung)';
    }

    /**
     * @param list<mixed> $parameter
     */
    private function numeral(string $sql, array $parameter = []): int
    {
        return (int) $this->text($sql, $parameter);
    }

    /**
     * @param list<mixed> $parameter
     */
    private function text(string $sql, array $parameter = []): string
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parameter);

        return (string) $stmt->fetchColumn();
    }

    /**
     * @param list<mixed> $parameter
     *
     * @return list<int>
     */
    private function column(string $sql, array $parameter = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parameter);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function applySchema(): void
    {
        $this->dropSchema();

        $root = dirname(__DIR__, 3);
        foreach ([
            'db/migrations/001_schema.sql',
            'db/migrations/002_points_rules.sql',
            'db/migrations/003_players_public.sql',
            'db/migrations/004_matchday_cancelled.sql',
            'db/migrations/005_trusted_device.sql',
            'db/migrations/006_account_permission.sql',
            'db/migrations/007_password_policy.sql',
            'db/migrations/008_content_image.sql',
        ] as $file) {
            $this->pdo->exec((string) file_get_contents($root . '/' . $file));
        }
    }

    private function dropSchema(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'fixture_log', 'team_entry_game', 'team_entry', 'game_result', 'lineup', 'original_score_sheet', 'fixture', 'lane_pair',
            'frozen_handicap', 'roster', 'team_season', 'team', 'matchday', 'season_config', 'season',
            'player', 'previous_season_record', 'admin_account', 'login_attempt', 'trusted_device', 'account_permission', 'account_log', 'password_policy', 'password_history', 'content_image', 'home_page_post',
            'rules', 'privacy_policy', 'legal_notice', 'league_setting',
            'cache_average', 'cache_league_table', 'cache_matchday_table', 'cache_honours',
        ] as $table) {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $address, strrpos((string) $address, ':') + 1);
    }

    private static function nullDevice(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }

    /**
     * @return array<string, mixed>
     */
    private static function testConfiguration(): array
    {
        return [
            'host'            => self::env('BOWLINGLIGA_TEST_DB_HOST', '127.0.0.1'),
            'port'            => (int) self::env('BOWLINGLIGA_TEST_DB_PORT', '3306'),
            'dbname'          => self::env('BOWLINGLIGA_TEST_DB_NAME', 'bowlingliga_test'),
            'user'            => self::env('BOWLINGLIGA_TEST_DB_USER', 'root'),
            'password'        => self::env('BOWLINGLIGA_TEST_DB_PASSWORD', ''),
            'charset'         => self::env('BOWLINGLIGA_TEST_DB_CHARSET', 'utf8mb4'),
            'connect_timeout' => 5,
        ];
    }

    private static function env(string $name, string $standard): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $standard : $value;
    }
}
