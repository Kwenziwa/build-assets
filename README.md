# Influx IT Build Assets

A framework-agnostic Composer package that packages compiled frontend assets into a ZIP, checks their integrity, and deploys them without Node.js on the server. It provides Laravel Artisan commands and a plain PHP CLI.

> **Status:** Initial implementation (v0.x). Do not publish as a stable 1.0 release until integration tests are run on a PHP environment with Composer, `ext-zip`, and Laravel.

## Requirements

- PHP 8.2 or newer with `ext-zip`
- Composer on systems installing the package
- Git for Git-based workflows and a shell with `php` available in `PATH` for Git hooks
- A compiled frontend asset folder (default: `public/build`)

## Installation

Once published to Packagist:

```bash
composer require influxit/build-assets
```

Before publishing, install it from a local path repository in the **application's** `composer.json`:

```json
{
  "repositories": [
    { "type": "path", "url": "../influxit-build-assets", "options": { "symlink": true } }
  ]
}
```

Then run `composer require influxit/build-assets:@dev`. The package's own name and version must be resolvable from the repository. The library is not published to Packagist by simply downloading its ZIP.

## Laravel quick start

Laravel discovers the service provider automatically. Optionally publish the configuration:

```bash
php artisan vendor:publish --tag=build-assets-config
npm run build
php artisan build:assets

git add build.zip build-manifest.json
git commit -m "Update compiled assets"
git push
```

On the server, use **one** of the following modes:

**Git hooks (install once per clone):**

```bash
php artisan build:hooks install
git pull
```

Merge-based pulls trigger `post-merge`; rebases may invoke `post-rewrite`. The hooks prefer `php artisan build:extract` when an `artisan` file is present; otherwise they run `php vendor/bin/build-assets extract`. This keeps Laravel configuration consistent with the hook. If your Laravel checkout lacks the package or is not bootstrapped when the hook runs, use the explicit deploy command instead. Git hooks do not automatically propagate with the repository, and an already successful pull cannot be rolled back by a failing hook.

**Explicit deployment (recommended for production):**

```bash
php artisan build:deploy --pull
```

This uses `git pull --ff-only`, refuses to pull when tracked files have uncommitted changes (untracked files are ignored), then verifies/extracts the archive in a fresh PHP process so configuration and code changed by the pull take effect. It is safe to run when Git hooks are also installed because unchanged assets are skipped. If the CLI package is installed under `require-dev` but production Composer runs with `--no-dev`, extraction will not be available. Install under normal `require` for production extraction.

## Plain PHP quick start

Create `build-assets.php` in your application root (see `config/build-assets.php` for defaults). Run:

```bash
npm run build
vendor/bin/build-assets pack

git add build.zip build-manifest.json
git commit -m "Update compiled assets"
git push
```

On your deployment target:

```bash
vendor/bin/build-assets hooks install  # one-time setup
# then either:
git pull
# or use the explicit alternative:
vendor/bin/build-assets deploy --pull
```

## Commands

| Laravel | Plain PHP | Purpose |
| --- | --- | --- |
| `php artisan build:assets` | `vendor/bin/build-assets pack` | Archive existing build assets |
| `php artisan build:assets --compile` | `vendor/bin/build-assets pack --compile` | Run configured frontend build then archive |
| `php artisan build:extract` | `vendor/bin/build-assets extract` | Verify and extract ZIP safely |
| `php artisan build:extract --force` | `vendor/bin/build-assets extract --force` | Re-extract even if current |
| `php artisan build:status` | `vendor/bin/build-assets status` | Verify archive and report deployment state |
| `php artisan build:hooks install` | `vendor/bin/build-assets hooks install` | Install Git hooks |
| `php artisan build:hooks remove` | `vendor/bin/build-assets hooks remove` | Remove managed Git hook blocks |
| `php artisan build:deploy --pull` | `vendor/bin/build-assets deploy --pull` | Fast-forward pull then extract |

Plain PHP global options: `--root=/path/to/app` and `--config=/absolute/path/to/file.php`. A `--config` file that does not exist is an error. Use `--help` to list commands.

## Configuration

Defaults are defined in `config/build-assets.php`:

```php
<?php
return [
    'source' => 'public/build',
    'destination' => 'public/build',
    'archive' => 'build.zip',
    'manifest' => 'build-manifest.json',
    'compile' => ['command' => 'npm run build'],
    'git' => ['auto_extract' => true],
    'extraction' => ['skip_unchanged' => true],
];
```

Paths are relative to the project root. **Important:** In this initial version, `git.auto_extract` and `extraction.skip_unchanged` are reserved configuration values; the hook always extracts if installed, and unchanged ZIPs are always skipped unless `--force` is used. Do not rely on these flags as toggles yet.

For Vite/Laravel, add the following to your application's `.gitignore`:

```gitignore
/public/build/
/.build-assets.lock
```

The archive and manifest are committed, and the compiled build directory is not. The destination directory is replaced as a whole during deployment: **do not keep user uploads or other unrelated data in it.** The package writes a `.build-assets-state.json` marker inside it; `pack` leaves that file out of the archive. If your app has another deployment marker with that name, choose a different build directory.

Archives are reproducible: entries are sorted and stored with fixed timestamps and permissions, so packing an unchanged build produces the same ZIP and checksum and nothing new to commit. (Different zlib versions can still compress differently across machines.)

## Security and limits

- SHA-256 checksum verification against the checked-in manifest (integrity, not a signature)
- Rejects archive traversal, absolute paths, symlink entries and duplicate names
- Extracts to staging and swaps directories; attempts to restore old assets on activation failure
- Locks concurrent extraction with `.build-assets.lock`
- Limits ZIP to 20,000 entries and 1 GiB total declared uncompressed size
- Does not automatically run Git commit/push or NPM during extraction
- Executes the configured compile command **only** when explicitly invoked with `--compile`; configure only trusted commands
- Git hooks change local checkout behavior. Only install them in repositories you trust

**Operational caveats:** The previous asset directory is removed after a successful swap. No persistent rollback history is retained. A successful Git pull that causes an unsuccessful hook cannot be undone by this package. On systems using aggressive opcode/file caches or where atomic renames are unavailable, use an appropriate maintenance/deployment strategy. The directory swap can briefly make the destination unavailable between renames.

## Tests

```bash
composer install
vendor/bin/phpunit
```

This source bundle includes unit/integration tests. Composer, ext-zip and PHPUnit are needed to run the full suite.

## License

MIT, see `LICENSE`.
