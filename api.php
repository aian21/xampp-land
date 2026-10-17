<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/projects.php';
require __DIR__ . '/lib/doctor.php';

$action = $_GET['action'] ?? '';

if ($action === 'projects') {
    json_out(scan_projects());
}

if ($action === 'status') {
    $port = mysql_port();
    json_out([
        'php'   => PHP_VERSION,
        'apache' => explode(' ', $_SERVER['SERVER_SOFTWARE'] ?? 'Apache')[0],
        'mysql' => ['port' => $port, 'up' => port_open('127.0.0.1', $port, 0.5)],
    ]);
}

// Everything below reads or changes local server config: localhost only.
if (!is_local_request()) {
    json_out(['error' => 'The phpMyAdmin doctor only works from this computer (localhost).'], 403);
}

if ($action === 'diagnose') {
    json_out(run_diagnostics());
}

if ($action === 'fix' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!hash_equals(csrf_token(), (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        json_out(['error' => 'Session expired, reload the page.'], 419);
    }
    @set_time_limit(300);
    try {
        json_out(['ok' => true, 'message' => run_fix((string) ($body['fix'] ?? ''), (array) ($body['params'] ?? []))]);
    } catch (Throwable $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}

json_out(['error' => 'Unknown action'], 404);
