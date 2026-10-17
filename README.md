# xampp-front

A replacement landing page for XAMPP on Windows. It lists every project in `htdocs` and fixes phpMyAdmin when it stops working.

Plain PHP, CSS and JavaScript. No build step, no dependencies, works offline.

## Features

**Projects**
- Lists every folder in `htdocs` and detects what it is: PHP, Laravel, CodeIgniter, WordPress, React, React Native, Vue, Angular, Svelte, Next.js, Node, HTML
- Shows git branch, last change, and tags such as Vite, Tailwind, TypeScript and `.env`
- Opens Laravel projects at `/public/` and JS projects at their built `dist/` or `build/` folder
- Search (`/`), filter by type, sort, grid or list view, pin favourites, copy folder path
- Lists `.zip` / `.rar` / `.7z` archives sitting in `htdocs`

**phpMyAdmin Doctor**
- Checks: mysqli extension, `config.inc.php`, whether MySQL is running (and what is using the port if it isn't), port mismatch, login (`#1045 Access denied`), control user, `blowfish_secret`, tmp folder, and what `/phpmyadmin/` actually returns
- Reads `mysql_error.log` to explain why MySQL failed to start
- One-click fixes: start MySQL, set credentials (tested before saving), switch between auto login and login screen, fix port, generate cookie secret, disable control user, reset config, repair MySQL from `mysql\backup` ("MySQL shutdown unexpectedly")
- Every config change is backed up as `config.inc.php.xf-<date>` and can be restored from the page
- Every change asks for confirmation in a warning dialog first

## Install

1. Clone into `htdocs`:

   ```
   cd C:\xampp\htdocs
   git clone https://github.com/aian21/xampp-front.git
   ```

2. Make it the landing page. Back up `C:\xampp\htdocs\index.php`, then replace its contents with:

   ```php
   <?php
   header('Location: /xampp-front/');
   exit;
   ```

3. Open http://localhost/

The folder must be named `xampp-front` and sit directly in `htdocs`, because the page loads its assets from `/xampp-front/`.

## Requirements

- XAMPP for Windows (tested with PHP 8.2, MariaDB 10.4, phpMyAdmin 5.2)
- PHP `mysqli` extension (enabled by default in XAMPP)

## Security

The Doctor reads and edits server config, so it only responds to requests from `localhost`. Fix requests also need a session CSRF token. The project list is visible to anyone who can reach your Apache server, just as `htdocs` already is.

Don't expose XAMPP to the internet.

## Restoring the XAMPP dashboard

Put back your original `htdocs\index.php`, or set it to redirect to `/dashboard/`. The XAMPP dashboard itself is untouched.

## Credits

Created by Aian.
