# Laravel Bubblewrap Guard

Security layer that forbids executing external commands without a bubblewrap sandbox. Designed for Laravel apps from 5 through 12 that need to process files (PDF, image, video, document) with mandatory `bubblewrap (bwrap)` isolation.

## Why use this

- Prevents RCE via unsafe `shell_exec/exec/system/passthru/proc_open`.
- Isolates filesystem, environment variables, and network for the child process.
- Compatible with Laravel 5.x to 12.x. Runtime code targets PHP 5.6+, but CI starts at PHP 7.x because the test suite uses anonymous classes (PHP 5.6 is not covered—use 7.x+ in production).
- Runs on Linux only (bubblewrap is a Linux-specific sandbox).

## Installation

```bash
composer require securerun/bubblewrap-sandbox
```

For Laravel >= 5.5, package auto-discovery already registers the provider and the `BubblewrapSandbox` alias (now pointing to the facade at `SecureRun\BubblewrapSandbox`).

For older versions, add manually in `config/app.php`:

```php
SecureRun\Sandbox\BubblewrapServiceProvider::class,
'BubblewrapSandbox' => SecureRun\BubblewrapSandbox::class,
```

Publish the configuration (optional):

```bash
php artisan vendor:publish --tag=sandbox-config
```

## Basic usage

```php
use SecureRun\BubblewrapSandboxRunner;

$runner = app(\SecureRun\BubblewrapSandboxRunner::class); // or the BubblewrapSandbox facade for static calls

// Command to run inside the sandbox
$command = array('gs', '-q', '-sDEVICE=png16m', '-o', '/tmp/out.png', '/tmp/in.pdf');

// Bind mounts for input/output (read-only by default)
$binds = array(
    array('from' => storage_path('uploads/in.pdf'), 'to' => '/tmp/in.pdf', 'read_only' => true),
    array('from' => storage_path('tmp'), 'to' => '/tmp', 'read_only' => false),
);

$process = $runner->run($command, $binds, '/tmp', null, 120);
$output = $process->getOutput();
```

Or via the Laravel facade (no `/Laravel` namespace anymore):

```php
use SecureRun\BubblewrapSandbox;

$process = BubblewrapSandbox::run(['ls', '-la']);
$output = $process->getOutput();
```

Note: `SecureRun\Sandbox\BubblewrapSandbox` remains as a backwards-compatible shim for apps that imported the old namespace. Prefer `SecureRun\BubblewrapSandbox` (or the `BubblewrapSandbox` alias).

## Documentation

- Quick usage guide: [docs/USING_SANDBOX.md](docs/USING_SANDBOX.md)

### Security rules enforced

- Every command is prefixed with `bwrap` and `--unshare-all --die-with-parent --new-session`.
- Default mounts: `/usr`, `/bin`, `/lib`, `/sbin`, `/etc/resolv.conf`, `/etc/ssl` as read-only (adds `/lib64` when the host has it); `/tmp` isolated and writable.
- Default binary points to `/usr/bin/bwrap` (adjust `config/sandbox.php` if `bwrap` lives elsewhere).
- PATH is limited (`/usr/bin:/bin:/usr/sbin:/sbin`).
- If `bwrap` is unavailable or not executable, a `BubblewrapUnavailableException` is thrown.

### Do not

- Do not call `shell_exec`, `exec`, `system`, `passthru`, `proc_open`, or raw `Symfony Process` for sensitive binaries. Always go through `BubblewrapSandbox`.
- Do not mount directories containing secrets (e.g., `/home`, `/var/www/.env`).

## Configuration

Edit `config/sandbox.php` after publishing:

- `binary`: path to `bwrap` (default `/usr/bin/bwrap`; use `bwrap` if it’s on PATH).
- `base_args`: default flags (avoid removing unshare/die-with-parent).
- `read_only_binds`: automatic read-only binds.
- `write_binds`: writable binds (default empty; `/tmp` is already a sandbox tmpfs).

## Quick examples

- **Image** with ImageMagick: `['convert', '/tmp/in.png', '-resize', '800x600', '/tmp/out.png']`.
- **Video** with FFmpeg: `['ffmpeg', '-i', '/tmp/in.mp4', '-vf', 'scale=1280:720', '/tmp/out.mp4']` plus binds for input/output paths.
- **PDF** with Ghostscript: use the basic usage example.

## Tests

- Requires PHP `ext-dom` enabled.
- Local run (single version):

  ```bash
  composer install --no-interaction --no-progress
  vendor/bin/phpunit
  ```

  On PHP 5.6–7.x, Composer will pull PHPUnit 5.7; on PHP 8.x it will use PHPUnit 9.6 (coverage is optional if `xdebug`/`pcov` are installed).
- Matrix via Docker:

  ```bash
  chmod +x tools/test-matrix.sh
  tools/test-matrix.sh
  ```

  The script spins up PHP containers and runs PHPUnit across multiple PHP/Laravel pairs. Adjust the `COMBOS` list to narrow versions. Note: the current test suite uses anonymous classes, so the PHP 5.6/Laravel 5.4 combo is commented out (PHP 5.6 lacks that feature).
