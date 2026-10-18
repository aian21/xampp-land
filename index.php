<?php
require __DIR__ . '/lib/bootstrap.php';
$token = csrf_token();
$local = is_local_request();

// Versions for the About tab, read from the install itself.
$pmaVersion = preg_match("/VERSION = '([^']+)'/", (string) @file_get_contents(PMA_DIR . '/libraries/classes/Version.php'), $m) ? $m[1] : 'unknown';
$mysqlVersion = preg_match('/\sVer\s+(\S+)/', (string) @shell_exec('"' . MYSQL_DIR . '\\bin\\mysqld.exe" --version 2>NUL'), $m) ? $m[1] : 'unknown';
$apacheVersion = preg_match('#Apache/(\S+)#', $_SERVER['SERVER_SOFTWARE'] ?? '', $m) ? $m[1] : 'unknown';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>localhost</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="stylesheet" href="assets/app.css?v=11">
  <script>
    try { const t = localStorage.getItem('xl-theme'); if (t) document.documentElement.dataset.theme = t; } catch (e) {}
  </script>
</head>
<body>
  <header class="top">
    <div class="wrap top-inner">
      <a class="brand" href="/">
        
        <span>localhost<span class="muted">/htdocs</span></span>
      </a>
      <div class="status" id="status">
        <span class="pill"><i class="dot ok"></i>Apache</span>
        <span class="pill" id="st-mysql"><i class="dot"></i>MySQL</span>
        <span class="pill muted" id="st-php">PHP</span>
      </div>
      <nav class="links">
        <a href="/phpmyadmin/" target="_blank" rel="noopener">phpMyAdmin</a>
        <a href="/dashboard/phpinfo.php" target="_blank" rel="noopener">phpinfo</a>
        <button class="icon-btn" id="theme" title="Toggle theme" aria-label="Toggle theme">Theme</button>
      </nav>
    </div>
    <div class="wrap tabs" role="tablist">
      <button role="tab" class="tab active" data-tab="projects">Projects <span class="count" id="tab-count"></span></button>
      <button role="tab" class="tab" data-tab="doctor">phpMyAdmin Doctor <span class="count warn" id="tab-issues" hidden></span></button>
      <button role="tab" class="tab" data-tab="about">About</button>
    </div>
  </header>

  <main class="wrap">
    <!-- Projects -->
    <section id="panel-projects" class="panel">
      <div class="toolbar">
        <label class="search">
          
          <input id="q" type="search" placeholder="Search projects (press /)" autocomplete="off">
        </label>
        <select id="sort" aria-label="Sort">
          <option value="recent">Recently changed</option>
          <option value="name">Name A–Z</option>
          <option value="type">Type</option>
        </select>
        <div class="seg" id="view" role="group" aria-label="View">
          <button data-view="grid" class="on" title="Grid">Grid</button>
          <button data-view="list" title="List">List</button>
        </div>
      </div>
      <div class="chips" id="chips"></div>

      <div id="pinned-wrap" hidden>
        <h2 class="section-title">Pinned</h2>
        <div class="grid" id="pinned"></div>
      </div>
      <h2 class="section-title" id="all-title">All projects</h2>
      <div class="grid" id="grid"><div class="empty">Loading projects…</div></div>
      <div class="more" id="more" hidden>
        <span class="muted" id="more-count"></span>
        <button class="btn" id="more-btn">Show more</button>
        <button class="btn" id="more-all">Show all</button>
      </div>

      <details class="archives" id="archives-wrap" hidden>
        <summary>Archives in htdocs <span class="count" id="archives-count"></span></summary>
        <ul id="archives"></ul>
      </details>
      <label class="sys-toggle"><input type="checkbox" id="show-system"> Show XAMPP system folders</label>
    </section>

    <!-- Doctor -->
    <section id="panel-doctor" class="panel" hidden>
      <?php if (!$local): ?>
        <div class="notice fail">The phpMyAdmin doctor only works when you open this page from the XAMPP computer itself (http://localhost/).</div>
      <?php else: ?>
      <div class="doctor-head">
        <div>
          <h1>phpMyAdmin Doctor</h1>
          <p class="muted">Checks MySQL, your phpMyAdmin config and the page itself, then fixes what it can. Every config change is backed up first.</p>
        </div>
        <div class="row">
          <button class="btn" id="run">Run checks again</button>
          <a class="btn primary" href="/phpmyadmin/" target="_blank" rel="noopener">Open phpMyAdmin</a>
        </div>
      </div>

      <div id="summary" class="summary"></div>
      <ul class="checks" id="checks"><li class="empty">Running checks…</li></ul>

      <div class="cards2">
        <form class="card" id="cred-form" autocomplete="off">
          <h3>Connection settings</h3>
          <p class="muted small">Fixes <code>#1045 Access denied</code>. Credentials are tested before they're saved to <code>config.inc.php</code>.</p>
          <div class="seg wide" id="auth-seg">
            <button type="button" data-auth="config" class="on">Auto login</button>
            <button type="button" data-auth="cookie">Login screen</button>
          </div>
          <p class="muted small" id="auth-help"></p>
          <div class="fields">
            <label>User <input name="user" value="root"></label>
            <label class="pw-field">Password <input name="password" type="password" placeholder="(empty)"></label>
            <label>Host <input name="host" value="127.0.0.1"></label>
            <label>Port <input name="port" value="3306" inputmode="numeric"></label>
          </div>
          <div class="row">
            <button type="button" class="btn" id="test">Test connection</button>
            <button type="submit" class="btn primary">Save to phpMyAdmin</button>
          </div>
          <div class="form-msg" id="cred-msg"></div>
        </form>

        <div class="card">
          <h3>Other tools</h3>
          <div class="tool">
            <div><strong>Start MySQL</strong><p class="muted small">Same as pressing Start in the XAMPP Control Panel.</p></div>
            <button class="btn" data-fix="start_mysql">Start</button>
          </div>
          <div class="tool">
            <div><strong>Reset phpMyAdmin config</strong><p class="muted small">Writes the stock XAMPP config (root, no password).</p></div>
            <button class="btn" data-fix="reset_config">Reset</button>
          </div>
          <div class="tool">
            <div><strong>Repair MySQL from backup</strong><p class="muted small">For “MySQL shutdown unexpectedly”. Keeps your databases, resets user accounts. MySQL must be stopped.</p></div>
            <button class="btn danger" data-fix="restore_mysql_data">Repair</button>
          </div>
          <div class="tool" id="backups-tool" hidden>
            <div><strong>Config backups</strong><p class="muted small">Roll back a change made here.</p></div>
            <select id="backups"></select>
            <button class="btn" id="restore-backup">Restore</button>
          </div>
        </div>
      </div>

      <details class="card log" id="log-wrap">
        <summary>MySQL error log <span class="muted small">(last start attempt)</span></summary>
        <pre id="log"></pre>
      </details>
      <?php endif; ?>
    </section>

    <!-- About -->
    <section id="panel-about" class="panel" hidden>
      <div class="about">
        <div class="about-main">
          <h1>About this page</h1>
          <p>A replacement for the default XAMPP dashboard. It lists every project in <code>htdocs</code> so you can open them in one click, and checks and repairs phpMyAdmin when it stops working.</p>

          <h3>What it does</h3>
          <dl class="about-list">
            <dt>Projects</dt>
            <dd>Scans <code><?= htmlspecialchars(HTDOCS) ?></code> and detects what each folder is (PHP, Laravel, React, Node and others), its git branch and when it last changed. Search, filter, sort, pin, and open a project in your code editor.</dd>
            <dt>phpMyAdmin Doctor</dt>
            <dd>Checks MySQL, the phpMyAdmin config and the page itself, and fixes common problems: wrong password, port mismatch, stopped server, broken config, crashed MySQL data. Config changes are backed up first.</dd>
          </dl>

          <h3>Keyboard</h3>
          <dl class="about-keys">
            <dt><kbd>/</kbd></dt><dd>Search projects</dd>
            <dt><kbd>Enter</kbd></dt><dd>Open the first result</dd>
            <dt><kbd>Esc</kbd></dt><dd>Clear the search</dd>
          </dl>

          <h3>Going back to the XAMPP dashboard</h3>
          <p class="muted">Run <code>uninstall.bat</code> in this app's folder. It puts back the original <code>htdocs\index.php</code>, which was saved as <code>index.php.xampp-original</code>. The old dashboard is still at <a href="/dashboard/">/dashboard/</a>.</p>
        </div>

        <aside class="about-side">
          <div class="card credit">
            <span class="credit-label">Created by</span>
            <span class="credit-name">Aian</span>
          </div>
          <div class="card">
            <h3>This server</h3>
            <table class="about-table">
              <tr><th>Apache</th><td><?= htmlspecialchars($apacheVersion) ?></td></tr>
              <tr><th>PHP</th><td><?= PHP_VERSION ?></td></tr>
              <tr><th>MySQL</th><td><?= htmlspecialchars($mysqlVersion) ?></td></tr>
              <tr><th>phpMyAdmin</th><td><?= htmlspecialchars($pmaVersion) ?></td></tr>
              <tr><th>XAMPP</th><td><code><?= htmlspecialchars(XAMPP_ROOT) ?></code></td></tr>
            </table>
          </div>
        </aside>
      </div>
    </section>
  </main>

  <div class="toast" id="toast" role="status" aria-live="polite"></div>

  <dialog class="modal" id="warn" aria-labelledby="warn-title">
    <form method="dialog">
      <div class="modal-band">Critical operation</div>
      <div class="modal-body">
        <h2 id="warn-title"></h2>
        <p class="modal-lead" id="warn-lead"></p>
        <ul class="modal-points" id="warn-points"></ul>
        <div class="modal-files" id="warn-files"></div>
        <label class="modal-typed" id="warn-typed" hidden>
          <span>Type <b id="warn-word"></b> to confirm</span>
          <input id="warn-input" autocomplete="off" spellcheck="false">
        </label>
      </div>
      <div class="modal-foot">
        <button class="btn" value="cancel" id="warn-cancel">Cancel</button>
        <button class="btn danger-solid" value="ok" id="warn-ok">Continue</button>
      </div>
    </form>
  </dialog>

  <dialog class="modal plain" id="editor-modal" aria-labelledby="editor-title">
    <form method="dialog">
      <div class="modal-body">
        <h2 id="editor-title"></h2>
        <p class="modal-lead">Editors found on this computer.</p>
        <div class="editor-list" id="editor-list"></div>
      </div>
      <div class="modal-foot">
        <button class="btn" value="cancel">Cancel</button>
      </div>
    </form>
  </dialog>

  <script>window.XL =<?= json_encode([
      'csrf'    => $token,
      'local'   => $local,
      'paths'   => $local ? ['pmaConfig' => PMA_CONFIG, 'mysqlData' => MYSQL_DATA, 'mysqlBackup' => MYSQL_BACKUP, 'mysqld' => MYSQL_DIR . '\\bin\\mysqld.exe'] : null,
  ], JSON_UNESCAPED_SLASHES) ?>;</script>
  <script src="assets/app.js?v=12"></script>
</body>
</html>
