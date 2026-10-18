<?php
// Makes this app the localhost landing page, or undoes that. Run by install.bat / uninstall.bat.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = dirname(__DIR__);
$htdocs = dirname($app);
$name = basename($app);
$index = $htdocs . DIRECTORY_SEPARATOR . 'index.php';
$backup = $index . '.xampp-original';

function say(string $msg): void { echo $msg, PHP_EOL; }
function fail(string $msg): void { say(''); say('ERROR: ' . $msg); exit(1); }

/** The redirect we write contains this, so we can recognise it later. */
function is_ours(string $file): bool
{
    $src = @file_get_contents($file);
    return $src !== false && str_contains($src, 'xampp-land');
}

/** http://localhost/ with the port Apache listens on, read from httpd.conf. */
function local_url(string $xampp): string
{
    $conf = (string) @file_get_contents($xampp . '\apache\conf\httpd.conf');
    $port = preg_match('/^\s*Listen\s+(?:\S+:)?(\d+)\s*$/m', $conf, $m) ? (int) $m[1] : 80;
    return 'http://localhost' . ($port === 80 ? '' : ':' . $port) . '/';
}

if (basename($htdocs) !== 'htdocs' || !is_dir(dirname($htdocs) . DIRECTORY_SEPARATOR . 'apache')) {
    fail("This folder must sit directly inside xampp\\htdocs.\n       It is in: $htdocs");
}

$url = local_url(dirname($htdocs));
$mode = $argv[1] ?? 'install';

if ($mode === 'install') {
    say("XAMPP:   " . dirname($htdocs));
    say("App:     $name");
    say('');

    if (is_file($index) && !is_ours($index)) {
        // Never overwrite an earlier backup; keep this copy next to it instead.
        $dest = is_file($backup) ? $index . '.before-xampp-land-' . date('Ymd-His') : $backup;
        if (!@copy($index, $dest)) fail("Could not back up $index");
        say('Backed up your old index.php as ' . basename($dest));
    }

    $src = "<?php\n"
        . "// Landing page set by xampp-land. Run uninstall.bat in the xampp-land folder to undo.\n"
        . "header('Location: /' . rawurlencode(" . var_export($name, true) . ") . '/');\n"
        . "exit;\n";
    if (@file_put_contents($index, $src) === false) fail("Could not write $index (is it read-only?)");

    say($url . ' now opens ' . $name . '.');
    if (!in_array('--no-browser', $argv, true)) {
        say('');
        say('Make sure Apache is started in the XAMPP Control Panel.');
        say('Opening ' . $url . ' ...');
        pclose(popen('start "" "' . $url . '"', 'r'));
    }
    exit(0);
}

if ($mode === 'uninstall') {
    if (!is_file($index) || !is_ours($index)) {
        say('index.php is not the xampp-land redirect, so there is nothing to undo.');
        exit(0);
    }
    if (is_file($backup)) {
        if (!@unlink($index) || !@rename($backup, $index)) fail("Could not restore $backup");
        say('Restored your original index.php. ' . $url . ' is back to how it was.');
        exit(0);
    } else {
        // No backup: put back the redirect XAMPP ships with.
        $stock = "<?php\n"
            . "\tif (!empty(\$_SERVER['HTTPS']) && ('on' == \$_SERVER['HTTPS'])) {\n\t\t\$uri = 'https://';\n\t} else {\n\t\t\$uri = 'http://';\n\t}\n"
            . "\t\$uri .= \$_SERVER['HTTP_HOST'];\n"
            . "\theader('Location: '.\$uri.'/dashboard/');\n"
            . "\texit;\n?>\n"
            . "Something is wrong with the XAMPP installation :-(\n";
        if (@file_put_contents($index, $stock) === false) fail("Could not write $index");
        say('No backup found, so index.php was reset to the XAMPP default.');
    }
    say($url . ' opens the XAMPP dashboard again.');
    exit(0);
}

fail("Unknown mode: $mode");
