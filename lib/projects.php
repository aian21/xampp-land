<?php
// Scans htdocs and describes each folder as a project.

function scan_projects(): array
{
    $projects = [];
    $archives = [];

    foreach (scandir(HTDOCS) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name[0] === '.') {
            continue;
        }
        $path = HTDOCS . DIRECTORY_SEPARATOR . $name;

        if (is_file($path)) {
            if (preg_match('/\.(zip|rar|7z|tar|gz)$/i', $name)) {
                $archives[] = ['name' => $name, 'size' => filesize($path), 'modified' => filemtime($path)];
            }
            continue;
        }

        $projects[] = describe_project($name, $path);
    }

    return ['projects' => $projects, 'archives' => $archives];
}

function describe_project(string $name, string $path): array
{
    $has = fn(string $f) => file_exists($path . DIRECTORY_SEPARATOR . $f);
    $base = '/' . rawurlencode($name) . '/';

    $type = 'Folder';
    $entry = null;           // Better URL than the folder root, if any
    $tags = [];

    $pkg = null;
    if ($has('package.json')) {
        $pkg = json_decode((string) @file_get_contents($path . '/package.json'), true) ?: [];
    }
    $deps = array_merge($pkg['dependencies'] ?? [], $pkg['devDependencies'] ?? []);

    if ($has('artisan') && $has('composer.json')) {
        $type = 'Laravel';
        $entry = $base . 'public/';
    } elseif ($has('wp-config.php') || $has('wp-load.php')) {
        $type = 'WordPress';
    } elseif ($has('spark') && $has('app')) {
        $type = 'CodeIgniter';
        $entry = $base . 'public/';
    } elseif (isset($deps['next'])) {
        $type = 'Next.js';
    } elseif (isset($deps['@angular/core'])) {
        $type = 'Angular';
    } elseif (isset($deps['vue'])) {
        $type = 'Vue';
    } elseif (isset($deps['svelte'])) {
        $type = 'Svelte';
    } elseif (isset($deps['react'])) {
        $type = isset($deps['react-native']) || isset($deps['expo']) ? 'React Native' : 'React';
    } elseif ($pkg !== null) {
        $type = 'Node';
    } elseif ($has('index.php') || glob($path . '/*.php')) {
        $type = 'PHP';
    } elseif ($has('index.html') || glob($path . '/*.html')) {
        $type = 'HTML';
    }

    // Built output for JS projects is usually what's viewable through Apache.
    if ($pkg !== null && $entry === null) {
        foreach (['dist', 'build', 'out'] as $dir) {
            if ($has($dir . '/index.html')) {
                $entry = $base . $dir . '/';
                break;
            }
        }
    }

    if (isset($deps['vite'])) $tags[] = 'Vite';
    if (isset($deps['tailwindcss'])) $tags[] = 'Tailwind';
    if (isset($deps['typescript'])) $tags[] = 'TS';
    if ($has('composer.json') && $type !== 'Laravel') $tags[] = 'Composer';
    if ($has('.env')) $tags[] = '.env';

    $branch = null;
    if ($has('.git/HEAD')) {
        $head = trim((string) @file_get_contents($path . '/.git/HEAD'));
        $branch = str_starts_with($head, 'ref: refs/heads/') ? substr($head, 16) : substr($head, 0, 7);
    }

    $noIndex = !array_filter(['index.php', 'index.html', 'index.htm'], $has);

    return [
        'name'     => $name,
        'url'      => $base,
        'entry'    => $entry,
        'type'     => $type,
        'tags'     => $tags,
        'branch'   => $branch,
        'modified' => latest_mtime($path),
        'noIndex'  => $noIndex && $entry === null,
        'system'   => in_array(strtolower($name), SYSTEM_FOLDERS, true),
        'path'     => $path,
    ];
}

/** Most recent mtime among the folder and its top-level entries (cheap, no recursion). */
function latest_mtime(string $path): int
{
    $latest = (int) @filemtime($path);
    foreach (scandir($path) ?: [] as $f) {
        if ($f === '.' || $f === '..' || $f === 'node_modules' || $f === 'vendor' || $f === '.git') {
            continue;
        }
        $latest = max($latest, (int) @filemtime($path . DIRECTORY_SEPARATOR . $f));
    }
    return $latest;
}
