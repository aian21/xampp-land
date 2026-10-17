<?php
// Shared paths and helpers for the landing page.

define('XAMPP_ROOT', dirname(__DIR__, 3));            // C:\xampp
define('HTDOCS', dirname(__DIR__, 2));                // C:\xampp\htdocs
define('PMA_DIR', XAMPP_ROOT . DIRECTORY_SEPARATOR . 'phpMyAdmin');
define('PMA_CONFIG', PMA_DIR . DIRECTORY_SEPARATOR . 'config.inc.php');
define('MYSQL_DIR', XAMPP_ROOT . DIRECTORY_SEPARATOR . 'mysql');
define('MYSQL_INI', MYSQL_DIR . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'my.ini');
define('MYSQL_DATA', MYSQL_DIR . DIRECTORY_SEPARATOR . 'data');
define('MYSQL_BACKUP', MYSQL_DIR . DIRECTORY_SEPARATOR . 'backup');
define('IS_WINDOWS', DIRECTORY_SEPARATOR === '\\');

// Folders that ship with XAMPP (or are this app) and aren't user projects.
const SYSTEM_FOLDERS = ['dashboard', 'img', 'webalizer', 'xampp', 'xampp-front', 'forbidden', 'restricted'];

session_start();

function is_local_request(): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($ip, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);
}

function csrf_token(): string
{
    if (empty($_SESSION['xf_csrf'])) {
        $_SESSION['xf_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['xf_csrf'];
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function port_open(string $host, int $port, float $timeout = 1.0): bool
{
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($fp) {
        fclose($fp);
        return true;
    }
    return false;
}

function process_running(string $exe): bool
{
    if (!IS_WINDOWS || !function_exists('shell_exec')) {
        return false;
    }
    $out = (string) @shell_exec('tasklist /FI "IMAGENAME eq ' . $exe . '" /NH 2>NUL');
    return stripos($out, $exe) !== false;
}

/** Returns "PID name" of whatever is listening on $port, or null. */
function port_owner(int $port): ?string
{
    if (!IS_WINDOWS || !function_exists('shell_exec')) {
        return null;
    }
    $out = (string) @shell_exec('netstat -ano -p tcp 2>NUL');
    foreach (preg_split('/\r?\n/', $out) as $line) {
        if (preg_match('/^\s*TCP\s+\S+:' . $port . '\s+\S+\s+LISTENING\s+(\d+)/i', $line, $m)) {
            $pid = (int) $m[1];
            $task = (string) @shell_exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL');
            $name = preg_match('/^"([^"]+)"/', trim($task), $t) ? $t[1] : 'unknown';
            return $pid . ' ' . $name;
        }
    }
    return null;
}

function mysql_port(): int
{
    if (is_readable(MYSQL_INI)) {
        $ini = file_get_contents(MYSQL_INI);
        if (preg_match('/^\s*\[mysqld\][^\[]*?^\s*port\s*=\s*(\d+)/ms', $ini, $m)) {
            return (int) $m[1];
        }
    }
    return 3306;
}

/** Try a MySQL login. Returns ['ok'=>bool, 'error'=>string, 'errno'=>int, 'version'=>string, 'databases'=>int]. */
function mysql_try(string $host, int $port, string $user, string $pass): array
{
    if (!extension_loaded('mysqli')) {
        return ['ok' => false, 'errno' => 0, 'error' => 'mysqli extension is not loaded'];
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $db = mysqli_init();
    $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);
    if (!@$db->real_connect($host, $user, $pass, '', $port)) {
        return ['ok' => false, 'errno' => mysqli_connect_errno(), 'error' => mysqli_connect_error()];
    }
    $version = $db->server_info;
    $count = 0;
    if ($res = $db->query('SHOW DATABASES')) {
        $count = $res->num_rows;
    }
    $db->close();
    return ['ok' => true, 'errno' => 0, 'error' => '', 'version' => $version, 'databases' => $count];
}
