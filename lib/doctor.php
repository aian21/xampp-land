<?php
// phpMyAdmin / MySQL diagnostics and one-click fixes.

const PMA_SERVER = "\$cfg['Servers'][\$i]";

/** Loads config.inc.php in isolation. Returns [$cfg, $error]. */
function pma_load_config(): array
{
    if (!is_readable(PMA_CONFIG)) {
        return [null, 'config.inc.php not found or not readable'];
    }
    $load = static function (string $file) {
        $cfg = [];
        $i = 0;
        include $file;
        return $cfg;
    };
    try {
        ob_start();
        $cfg = $load(PMA_CONFIG);
        ob_end_clean();
        return [$cfg, null];
    } catch (Throwable $e) {
        ob_end_clean();
        return [null, $e->getMessage() . ' (line ' . $e->getLine() . ')'];
    }
}

function pma_server_cfg(?array $cfg): array
{
    $s = $cfg['Servers'][1] ?? [];
    return [
        'auth_type'   => $s['auth_type'] ?? 'cookie',
        'host'        => $s['host'] ?? 'localhost',
        'port'        => (int) (($s['port'] ?? '') ?: 3306),
        'user'        => $s['user'] ?? 'root',
        'password'    => $s['password'] ?? '',
        'controluser' => $s['controluser'] ?? '',
        'controlpass' => $s['controlpass'] ?? '',
    ];
}

/** Last MySQL start-up session from mysql_error.log. */
function mysql_log_tail(int $bytes = 65536): array
{
    $file = MYSQL_DATA . DIRECTORY_SEPARATOR . 'mysql_error.log';
    if (!is_readable($file)) {
        return [];
    }
    $size = filesize($file);
    $fh = fopen($file, 'rb');
    fseek($fh, max(0, $size - $bytes));
    $chunk = stream_get_contents($fh);
    fclose($fh);

    $lines = preg_split('/\r?\n/', trim($chunk));
    if ($size > $bytes) {
        array_shift($lines); // probably a partial line
    }
    // Keep only the most recent start attempt.
    for ($n = count($lines) - 1; $n >= 0; $n--) {
        if (preg_match('/starting as process|Starting MariaDB/i', $lines[$n])) {
            $lines = array_slice($lines, $n);
            break;
        }
    }
    return array_slice($lines, -60);
}

function analyse_mysql_log(array $lines): ?array
{
    $text = implode("\n", $lines);
    $known = [
        'port'    => ['/another mysqld server running on port|Can\'t start server: Bind on TCP\/IP port/i',
                      'Another program is already using the MySQL port.'],
        'aria'    => ['/Aria recovery failed|aria_log|Plugin \'Aria\' (init function returned error|registration as a STORAGE ENGINE failed)/i',
                      'Aria log files are corrupted (usually after Windows shut down while MySQL was running).'],
        'innodb'  => ['/InnoDB: .*(corrupt|Plugin initialization aborted)|Plugin \'InnoDB\' init function returned error/i',
                      'InnoDB data files look damaged.'],
        'grants'  => ['/Can\'t open and lock privilege tables|Table \'mysql\.\w+\' doesn\'t exist|mysql\.user/i',
                      'The system tables (user accounts) are missing or damaged.'],
    ];
    foreach ($known as $kind => [$re, $msg]) {
        if (preg_match($re, $text)) {
            return ['kind' => $kind, 'message' => $msg];
        }
    }
    if (preg_match('/\[ERROR\]/', $text)) {
        return ['kind' => 'other', 'message' => 'MySQL logged errors during its last start attempt.'];
    }
    return null;
}

/** Fetches /phpmyadmin/ through Apache and summarises what a browser would see. */
function probe_phpmyadmin(): array
{
    $port = (int) ($_SERVER['SERVER_PORT'] ?? 80);
    $url = 'http://127.0.0.1' . ($port === 80 ? '' : ':' . $port) . '/phpmyadmin/';
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true, 'follow_location' => 0]]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int) $m[1];
    }
    if ($body === false && !$status) {
        return ['status' => 0, 'message' => 'No response from ' . $url];
    }
    $msg = '';
    $plain = html_entity_decode(strip_tags((string) $body));
    if (preg_match('/(#\d{4}\s*-[^\n]{0,200}|mysqli::real_connect\(\)[^\n]{0,200}|Cannot connect: invalid settings\.?|Fatal error:[^\n]{0,200})/i', $plain, $m)) {
        $msg = trim(preg_replace('/\s+/', ' ', $m[1]));
    }
    return ['status' => $status, 'message' => $msg, 'url' => $url];
}

function run_diagnostics(): array
{
    $checks = [];
    $add = function (string $id, string $status, string $label, string $detail = '', ?array $fix = null) use (&$checks) {
        $checks[] = compact('id', 'status', 'label', 'detail', 'fix');
    };

    // 1. PHP side
    if (extension_loaded('mysqli')) {
        $add('mysqli', 'ok', 'PHP mysqli extension loaded', 'PHP ' . PHP_VERSION);
    } else {
        $add('mysqli', 'fail', 'PHP mysqli extension is missing', 'Enable "extension=mysqli" in ' . php_ini_loaded_file() . ' and restart Apache.');
    }

    // 2. Config file
    [$cfg, $cfgError] = pma_load_config();
    $server = pma_server_cfg($cfg);
    if (!file_exists(PMA_CONFIG)) {
        $add('config', 'fail', 'config.inc.php is missing', PMA_CONFIG, ['action' => 'reset_config', 'label' => 'Create default config']);
    } elseif ($cfgError) {
        $add('config', 'fail', 'config.inc.php has an error', $cfgError, ['action' => 'reset_config', 'label' => 'Reset to XAMPP default']);
    } elseif (!is_writable(PMA_CONFIG)) {
        $add('config', 'warn', 'config.inc.php is read-only', 'Fixes that edit the config will fail. Remove the read-only flag on ' . PMA_CONFIG);
    } else {
        $add('config', 'ok', 'config.inc.php loads correctly', 'auth_type = ' . $server['auth_type'] . ', user = ' . $server['user'] . ', host = ' . $server['host'] . ':' . $server['port']);
    }

    // 3. MySQL server
    $iniPort = mysql_port();
    $running = process_running('mysqld.exe');
    $listening = port_open('127.0.0.1', $iniPort);
    $log = mysql_log_tail();
    $logIssue = analyse_mysql_log($log);
    $mysqlUp = $running && $listening;

    if ($mysqlUp) {
        $add('mysqld', 'ok', 'MySQL is running', 'Listening on port ' . $iniPort);
    } elseif (!$running && $listening) {
        $owner = port_owner($iniPort);
        $add('mysqld', 'fail', "Port $iniPort is used by another program", ($owner ? "Process: $owner. " : '') . 'Stop that program (often another MySQL install or a Windows service) or change the port in my.ini.');
    } elseif ($running && !$listening) {
        $add('mysqld', 'warn', 'MySQL is starting or stuck', "mysqld.exe is running but not accepting connections on port $iniPort yet. Wait a few seconds and re-run.");
    } else {
        $fix = ['action' => 'start_mysql', 'label' => 'Start MySQL'];
        $detail = 'phpMyAdmin needs MySQL running. Start it here or from the XAMPP Control Panel.';
        if ($logIssue && in_array($logIssue['kind'], ['aria', 'innodb', 'grants'], true)) {
            $detail = 'Last start attempt failed: ' . $logIssue['message'];
            $fix = ['action' => 'restore_mysql_data', 'label' => 'Repair from backup', 'danger' => true];
        } elseif ($logIssue && $logIssue['kind'] === 'port') {
            $detail = $logIssue['message'];
        }
        $add('mysqld', 'fail', 'MySQL is not running', $detail, $fix);
    }

    // 4. Port agreement between phpMyAdmin and my.ini
    if ($cfg !== null && $server['port'] !== $iniPort && in_array($server['host'], ['127.0.0.1', 'localhost'], true)) {
        $add('port', 'fail', 'Port mismatch', "phpMyAdmin connects to port {$server['port']} but MySQL is configured for $iniPort.", ['action' => 'set_port', 'label' => "Use port $iniPort", 'params' => ['port' => $iniPort]]);
    }

    // 5. Login with the configured account
    if ($cfg !== null && $mysqlUp) {
        $login = mysql_try($server['host'], $server['port'], $server['user'], $server['password']);
        if ($server['auth_type'] === 'config') {
            if ($login['ok']) {
                $add('login', 'ok', "Logged in as {$server['user']}", "MariaDB/MySQL {$login['version']} · {$login['databases']} databases");
            } elseif ($login['errno'] === 1045) {
                $add('login', 'fail', 'Access denied for ' . $server['user'], "The password in config.inc.php doesn't match MySQL. Enter the correct password below, or switch phpMyAdmin to a login screen.", ['action' => 'open_credentials', 'label' => 'Set credentials']);
            } else {
                $add('login', 'fail', 'Cannot log in to MySQL', "#{$login['errno']} {$login['error']}", ['action' => 'open_credentials', 'label' => 'Set credentials']);
            }
        } else {
            $add('login', 'info', "phpMyAdmin uses a login screen ({$server['auth_type']} auth)", $login['ok'] ? "Configured user {$server['user']} can log in." : 'You will type the username and password on the phpMyAdmin login page.');
        }
    }

    // 6. Control user (advanced features)
    if ($cfg !== null && $mysqlUp && $server['controluser'] !== '') {
        $ctl = mysql_try($server['host'], $server['port'], $server['controluser'], $server['controlpass']);
        if ($ctl['ok']) {
            $add('controluser', 'ok', "Control user '{$server['controluser']}' works");
        } else {
            $add('controluser', 'warn', "Control user '{$server['controluser']}' cannot log in", 'This causes the "Connection for controluser as defined in your configuration failed" banner. It is only needed for advanced features.', ['action' => 'disable_controluser', 'label' => 'Disable control user']);
        }
    }

    // 7. Cookie secret (phpMyAdmin 5.2 wants exactly 32 bytes)
    if ($cfg !== null) {
        $secret = (string) ($cfg['blowfish_secret'] ?? '');
        if (strlen($secret) !== 32) {
            $add('blowfish', $server['auth_type'] === 'cookie' ? 'warn' : 'info', 'blowfish_secret is not 32 characters', 'phpMyAdmin shows a warning about this (it matters for cookie login).', ['action' => 'fix_blowfish', 'label' => 'Generate secret']);
        } else {
            $add('blowfish', 'ok', 'blowfish_secret is set correctly');
        }
    }

    // 8. Temp dir
    $tmp = PMA_DIR . DIRECTORY_SEPARATOR . 'tmp';
    if (!is_dir($tmp) || !is_writable($tmp)) {
        $add('tmp', 'warn', 'phpMyAdmin tmp folder is missing or not writable', $tmp . ' (needed for template caching).', ['action' => 'fix_tmp', 'label' => 'Create folder']);
    } else {
        $add('tmp', 'ok', 'phpMyAdmin tmp folder is writable');
    }

    // 9. What the browser actually gets
    $probe = probe_phpmyadmin();
    if ($probe['status'] === 200 && $probe['message'] === '') {
        $add('http', 'ok', 'phpMyAdmin page loads', 'HTTP 200 from /phpmyadmin/');
    } elseif ($probe['status'] === 0) {
        $add('http', 'info', 'Could not request /phpmyadmin/', $probe['message']);
    } else {
        $add('http', $probe['status'] >= 400 || $probe['message'] ? 'fail' : 'warn', 'phpMyAdmin returned HTTP ' . $probe['status'], $probe['message'] ?: 'Open phpMyAdmin to see the full error.');
    }

    return [
        'checks'  => $checks,
        'server'  => array_diff_key($server, ['password' => 1, 'controlpass' => 1]) + ['hasPassword' => $server['password'] !== ''],
        'mysql'   => ['running' => $mysqlUp, 'port' => $iniPort],
        'log'     => $log,
        'logIssue' => $logIssue,
        'backups' => list_config_backups(),
    ];
}

/* ---------------------------------------------------------------- fixes */

function list_config_backups(): array
{
    $files = glob(PMA_DIR . DIRECTORY_SEPARATOR . 'config.inc.php.xf-*') ?: [];
    rsort($files);
    return array_map(fn($f) => ['file' => basename($f), 'modified' => filemtime($f)], array_slice($files, 0, 10));
}

function backup_config(): ?string
{
    if (!file_exists(PMA_CONFIG)) return null;
    $dest = PMA_CONFIG . '.xf-' . date('Ymd-His');
    if (!@copy(PMA_CONFIG, $dest)) {
        throw new RuntimeException('Could not back up config.inc.php');
    }
    return basename($dest);
}

/** Writes new config contents, rolling back if the result doesn't load. */
function write_config(string $src): string
{
    $backup = backup_config();
    if (@file_put_contents(PMA_CONFIG, $src) === false) {
        throw new RuntimeException('Could not write ' . PMA_CONFIG . ' (is it read-only?)');
    }
    if (function_exists('opcache_invalidate')) @opcache_invalidate(PMA_CONFIG, true);
    [, $err] = pma_load_config();
    if ($err) {
        if ($backup) copy(PMA_DIR . DIRECTORY_SEPARATOR . $backup, PMA_CONFIG);
        throw new RuntimeException('Edit produced an invalid config, rolled back: ' . $err);
    }
    return $backup ? "Backup saved as $backup" : 'Config created';
}

/** Sets `$key = value;` on its own line, replacing an existing line or inserting one. */
function cfg_set(string $src, string $key, $value): string
{
    $line = $key . ' = ' . var_export($value, true) . ';';
    $re = '/^[ \t]*' . preg_quote($key, '/') . '\s*=.*$/m';
    if (preg_match($re, $src)) {
        return preg_replace_callback($re, fn() => $line, $src, 1);
    }
    $anchor = str_starts_with($key, PMA_SERVER) ? '/^\s*\$i\+\+;[ \t]*$/m' : '/^<\?php[ \t]*$/m';
    if (preg_match($anchor, $src, $m, PREG_OFFSET_CAPTURE)) {
        $at = $m[0][1] + strlen($m[0][0]);
        return substr($src, 0, $at) . "\n" . $line . substr($src, $at);
    }
    return rtrim(preg_replace('/\?>\s*$/', '', $src)) . "\n" . $line . "\n";
}

function cfg_comment_out(string $src, string $key): string
{
    return preg_replace_callback('/^([ \t]*)(' . preg_quote($key, '/') . '\s*=.*)$/m', fn($m) => $m[1] . '// ' . $m[2], $src);
}

function default_config(): string
{
    $secret = bin2hex(random_bytes(16));
    return <<<PHP
<?php
/* phpMyAdmin config — reset by the htdocs landing page */
\$cfg['blowfish_secret'] = '$secret';

\$i = 0;
\$i++;

/* Authentication type and info */
\$cfg['Servers'][\$i]['auth_type'] = 'config';
\$cfg['Servers'][\$i]['user'] = 'root';
\$cfg['Servers'][\$i]['password'] = '';
\$cfg['Servers'][\$i]['extension'] = 'mysqli';
\$cfg['Servers'][\$i]['AllowNoPassword'] = true;
\$cfg['Lang'] = '';

/* Bind to the localhost ipv4 address and tcp */
\$cfg['Servers'][\$i]['host'] = '127.0.0.1';
\$cfg['Servers'][\$i]['connect_type'] = 'tcp';

PHP;
}

function copy_dir(string $from, string $to): void
{
    if (!is_dir($to) && !@mkdir($to, 0777, true)) {
        throw new RuntimeException("Could not create $to");
    }
    foreach (scandir($from) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $src = $from . DIRECTORY_SEPARATOR . $f;
        $dst = $to . DIRECTORY_SEPARATOR . $f;
        if (is_dir($src)) {
            copy_dir($src, $dst);
        } elseif (!@copy($src, $dst)) {
            throw new RuntimeException("Could not copy $src");
        }
    }
}

function start_mysql(): string
{
    if (port_open('127.0.0.1', mysql_port())) {
        return 'MySQL is already running';
    }
    $exe = MYSQL_DIR . '\\bin\\mysqld.exe';
    if (!is_file($exe)) throw new RuntimeException("Not found: $exe");
    // Output goes to NUL so the request doesn't wait on the child process.
    pclose(popen('start "" /B "' . $exe . '" --defaults-file="' . MYSQL_INI . '" --standalone >NUL 2>&1', 'r'));
    for ($t = 0; $t < 20; $t++) {
        usleep(500000);
        if (port_open('127.0.0.1', mysql_port(), 0.3)) {
            return 'MySQL started';
        }
        if ($t > 4 && !process_running('mysqld.exe')) break;
    }
    throw new RuntimeException('MySQL did not start. Check the error log below.');
}

function restore_mysql_data(): string
{
    if (process_running('mysqld.exe')) {
        throw new RuntimeException('Stop MySQL first (XAMPP Control Panel → Stop).');
    }
    if (!is_dir(MYSQL_BACKUP)) {
        throw new RuntimeException('No backup folder at ' . MYSQL_BACKUP);
    }
    $old = MYSQL_DATA . '_old_' . date('Ymd-His');
    if (!@rename(MYSQL_DATA, $old)) {
        throw new RuntimeException('Could not rename the data folder. Close anything using it and try again.');
    }
    $log = ['Moved data → ' . basename($old)];
    copy_dir(MYSQL_BACKUP, MYSQL_DATA);
    $log[] = 'Copied fresh system tables from backup';

    $skip = ['mysql', 'performance_schema', 'phpmyadmin', 'test'];
    $dbs = [];
    foreach (scandir($old) ?: [] as $f) {
        if ($f === '.' || $f === '..' || in_array(strtolower($f), $skip, true) || !is_dir($old . DIRECTORY_SEPARATOR . $f)) continue;
        copy_dir($old . DIRECTORY_SEPARATOR . $f, MYSQL_DATA . DIRECTORY_SEPARATOR . $f);
        $dbs[] = $f;
    }
    $log[] = 'Restored ' . count($dbs) . ' databases' . ($dbs ? ': ' . implode(', ', $dbs) : '');
    if (is_file($old . '\\ibdata1')) {
        copy($old . '\\ibdata1', MYSQL_DATA . '\\ibdata1');
        $log[] = 'Restored ibdata1';
    }
    try {
        $log[] = start_mysql();
    } catch (RuntimeException $e) {
        $log[] = $e->getMessage();
    }
    return implode("\n", $log);
}

function run_fix(string $action, array $p): string
{
    $read = function () {
        $src = @file_get_contents(PMA_CONFIG);
        if ($src === false) throw new RuntimeException('Cannot read config.inc.php');
        return $src;
    };

    switch ($action) {
        case 'start_mysql':
            return start_mysql();

        case 'restore_mysql_data':
            return restore_mysql_data();

        case 'reset_config':
            return write_config(default_config());

        case 'fix_blowfish':
            return write_config(cfg_set($read(), "\$cfg['blowfish_secret']", bin2hex(random_bytes(16))));

        case 'fix_tmp':
            $tmp = PMA_DIR . DIRECTORY_SEPARATOR . 'tmp';
            if (!is_dir($tmp) && !@mkdir($tmp, 0777, true)) throw new RuntimeException("Could not create $tmp");
            return 'tmp folder ready';

        case 'disable_controluser':
            $src = cfg_comment_out($read(), PMA_SERVER . "['controluser']");
            return write_config(cfg_comment_out($src, PMA_SERVER . "['controlpass']"));

        case 'set_port':
            return write_config(cfg_set($read(), PMA_SERVER . "['port']", (string) (int) $p['port']));

        case 'test_connection':
            $r = mysql_try($p['host'] ?? '127.0.0.1', (int) ($p['port'] ?? 3306), (string) ($p['user'] ?? 'root'), (string) ($p['password'] ?? ''));
            if (!$r['ok']) throw new RuntimeException("#{$r['errno']} {$r['error']}");
            return "Connected · MariaDB/MySQL {$r['version']} · {$r['databases']} databases";

        case 'save_connection':
            $auth = in_array($p['auth_type'] ?? '', ['config', 'cookie'], true) ? $p['auth_type'] : 'config';
            $host = trim((string) ($p['host'] ?? '127.0.0.1')) ?: '127.0.0.1';
            $port = (int) ($p['port'] ?? 3306) ?: 3306;
            $user = trim((string) ($p['user'] ?? 'root')) ?: 'root';
            $pass = (string) ($p['password'] ?? '');
            if ($auth === 'config' && empty($p['force'])) {
                $r = mysql_try($host, $port, $user, $pass);
                if (!$r['ok']) throw new RuntimeException("Login failed, not saved: #{$r['errno']} {$r['error']}");
            }
            $src = $read();
            $src = cfg_set($src, PMA_SERVER . "['auth_type']", $auth);
            $src = cfg_set($src, PMA_SERVER . "['host']", $host);
            $src = cfg_set($src, PMA_SERVER . "['port']", (string) $port);
            $src = cfg_set($src, PMA_SERVER . "['user']", $user);
            $src = cfg_set($src, PMA_SERVER . "['password']", $auth === 'config' ? $pass : '');
            $src = cfg_set($src, PMA_SERVER . "['AllowNoPassword']", true);
            if ($auth === 'cookie') {
                [$cfg] = pma_load_config();
                if (strlen((string) ($cfg['blowfish_secret'] ?? '')) !== 32) {
                    $src = cfg_set($src, "\$cfg['blowfish_secret']", bin2hex(random_bytes(16)));
                }
            }
            return write_config($src);

        case 'restore_config':
            $file = basename((string) ($p['file'] ?? ''));
            $path = PMA_DIR . DIRECTORY_SEPARATOR . $file;
            if (!str_starts_with($file, 'config.inc.php.xf-') || !is_file($path)) throw new RuntimeException('Backup not found');
            return write_config(file_get_contents($path));
    }
    throw new RuntimeException("Unknown action: $action");
}
