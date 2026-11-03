<?php

declare(strict_types=1);

/**
 * Screenshot of a page or PDF via Firefox headless, with a time limit.
 *
 *   php screenshot.php <url> <target.png> [width=1280] [height=1500]
 *
 * PDFs: <url> = file:///<absolute path>/file.pdf (Firefox shows them with
 * pdf.js). Firefox runs with its own profile next to the target, so that an
 * open Firefox of the user stays untouched; after 40 s only this process is
 * ended. The environment variable FIREFOX overrides the path of the Firefox
 * binary.
 */

[$url, $target] = [$argv[1] ?? '', $argv[2] ?? ''];
$width = (int) ($argv[3] ?? 1280);
$height = (int) ($argv[4] ?? 1500);
if ($url === '' || $target === '') {
    fwrite(STDERR, "Usage: php screenshot.php <url> <target.png> [width] [height]\n");
    exit(2);
}

$windows = PHP_OS_FAMILY === 'Windows';
$firefox = getenv('FIREFOX') ?: match (PHP_OS_FAMILY) {
    'Windows' => 'C:\\Program Files\\Mozilla Firefox\\firefox.exe',
    'Darwin' => '/Applications/Firefox.app/Contents/MacOS/firefox',
    default => 'firefox',
};
$target = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $target);
$profile = dirname($target) . DIRECTORY_SEPARATOR . 'ff-profile';
@mkdir($profile, 0777, true);
@unlink($target);

$devNull = $windows ? 'NUL' : '/dev/null';
$command = [$firefox, '--headless', '--profile', $profile, '--no-remote',
    '--screenshot', $target, "--window-size={$width},{$height}", $url];
$process = proc_open($command, [1 => ['file', $devNull, 'w'], 2 => ['file', $devNull, 'w']], $pipes);
if ($process === false) {
    fwrite(STDERR, "Firefox could not be started: {$firefox}\n");
    exit(1);
}

$end = microtime(true) + 40;
while (proc_get_status($process)['running']) {
    if (microtime(true) > $end) {
        $pid = proc_get_status($process)['pid'];
        if ($windows) {
            exec("taskkill /PID {$pid} /T /F");
        } else {
            proc_terminate($process, 9);
        }
        fwrite(STDERR, "Time limit exceeded: {$url}\n");
        exit(1);
    }
    usleep(200_000);
}
proc_close($process);

if (! is_file($target)) {
    fwrite(STDERR, "No screenshot created.\n");
    exit(1);
}
echo "Screenshot: {$target}\n";
