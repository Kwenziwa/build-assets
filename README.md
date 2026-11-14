# Influx IT Build Assets

[![Latest version](https://img.shields.io/packagist/v/influxit/build-assets)](https://packagist.org/packages/influxit/build-assets)
[![Tests](https://github.com/Kwenziwa/build-assets/actions/workflows/tests.yml/badge.svg)](https://github.com/Kwenziwa/build-assets/actions/workflows/tests.yml)
[![PHP version](https://img.shields.io/packagist/php-v/influxit/build-assets)](https://packagist.org/packages/influxit/build-assets)
[![License](https://img.shields.io/packagist/l/influxit/build-assets)](LICENSE)

Build your frontend once, commit it as a ZIP, and deploy it on servers that don't have Node.js.

Works with Laravel (Artisan commands) and plain PHP (a small CLI).

> **Status:** early version (v0.x). Test it in your own project before relying on it in production.

## How it works

```
Your machine                          Server
------------                          ------
npm run build                         git pull
pack  ->  build.zip                   extract  ->  public/build
          build-manifest.json
git commit + push
```

1. **Pack** zips your compiled assets (default `public/build`) into `build.zip` and writes `build-manifest.json` with a SHA-256 checksum.
2. You commit and push those two files. The build folder itself stays out of Git.
3. **Extract** on the server checks the checksum, unpacks the ZIP into a temporary folder, and swaps it into place.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start: Laravel](#quick-start-laravel)
- [Quick start: plain PHP](#quick-start-plain-php)
- [Commands](#commands)
- [Configuration](#configuration)
- [Deploying on the server](#deploying-on-the-server)
- [Security](#security)
- [Limitations](#limitations)
- [Troubleshooting](#troubleshooting)
- [Tests](#tests)

## Requirements

- PHP 8.2 or newer with the `zip` extension
- Composer
- Git, if you use the Git hooks or `deploy --pull`
- `php` available in `PATH` on the server, if you use the Git hooks

## Installation

```bash
composer require influxit/build-assets
```

Install it under `require`, not `require-dev`. Production servers that run `composer install --no-dev` would otherwise have no extract command.

## Quick start: Laravel

Laravel discovers the service provider automatically.

**1. Ignore the build folder and lock file** in your application's `.gitignore`:

```gitignore
/public/build/
/.build-assets.lock
```

**2. On your machine: build, pack, commit.**

```bash
npm run build
php artisan build:assets

git add build.zip build-manifest.json
git commit -m "Update compiled assets"
git push
```

**3. On the server: pull and extract.**

```bash
php artisan build:deploy --pull
```

To change the defaults, publish the config file (optional):

```bash
php artisan vendor:publish --tag=build-assets-config
```

## Quick start: plain PHP

**1. Create `build-assets.php`** in your application root. Copy `examples/build-assets.php` as a starting point. Without this file the defaults are used.

**2. Ignore the build folder and lock file** in `.gitignore`:

```gitignore
/public/build/
/.build-assets.lock
```

**3. On your machine: build, pack, commit.**

```bash
npm run build
vendor/bin/build-assets pack

git add build.zip build-manifest.json
git commit -m "Update compiled assets"
git push
```

**4. On the server: pull and extract.**

```bash
vendor/bin/build-assets deploy --pull
```

## Commands

| What it does | Laravel | Plain PHP |
| --- | --- | --- |
| Zip the existing build | `php artisan build:assets` | `vendor/bin/build-assets pack` |
| Run the frontend build, then zip | `php artisan build:assets --compile` | `vendor/bin/build-assets pack --compile` |
| Verify and extract the ZIP | `php artisan build:extract` | `vendor/bin/build-assets extract` |
| Extract even if already up to date | `php artisan build:extract --force` | `vendor/bin/build-assets extract --force` |
| Check the ZIP and deployment state | `php artisan build:status` | `vendor/bin/build-assets status` |
| Pull with Git, then extract | `php artisan build:deploy --pull` | `vendor/bin/build-assets deploy --pull` |
| Install Git hooks | `php artisan build:hooks install` | `vendor/bin/build-assets hooks install` |
| Remove Git hooks | `php artisan build:hooks remove` | `vendor/bin/build-assets hooks remove` |

Plain PHP global options:

| Option | Meaning |
| --- | --- |
| `--root=/path/to/app` | Application root. Default: current directory. |
| `--config=/path/to/file.php` | Config file. Default: `build-assets.php` in the root. A file that does not exist is an error. |
| `--help` | List the commands. |

## Configuration

Defaults, from `config/build-assets.php`:

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

| Key | Meaning |
| --- | --- |
| `source` | Folder that `pack` zips. |
| `destination` | Folder that `extract` replaces. |
| `archive` | Where the ZIP is written. |
| `manifest` | Where the checksum file is written. |
| `compile.command` | Command run by `pack --compile`. Use only commands you trust. |
| `git.auto_extract` | Reserved. Not used yet. |
| `extraction.skip_unchanged` | Reserved. Not used yet. |

Rules for paths:

- All paths are relative to the application root. Absolute paths, `..` and symlinks are rejected.
- The archive and manifest must not be inside the source or destination folder.

Things to know:

- **The destination folder is replaced as a whole on every extract.** Do not keep uploads or other files in it.
- Extract writes a marker file, `.build-assets-state.json`, in the destination. It records which build is deployed, and `pack` leaves it out of the ZIP.
- Extract skips the work when the deployed build already matches the ZIP. Use `--force` to extract anyway.
- Packing an unchanged build gives the same ZIP and checksum, so there is nothing new to commit. (Different zlib versions on different machines can still compress differently.)

## Deploying on the server

Pick **one** of these. Using both is safe, because an unchanged build is skipped.

### Option 1: deploy command (recommended)

```bash
php artisan build:deploy --pull
# or
vendor/bin/build-assets deploy --pull
```

What it does:

1. Refuses to continue if tracked files have uncommitted changes. Untracked files are ignored.
2. Runs `git pull --ff-only`.
3. Verifies and extracts the ZIP in a fresh PHP process, so config and code changed by the pull are used.

### Option 2: Git hooks

Install once per clone:

```bash
php artisan build:hooks install
# or
vendor/bin/build-assets hooks install
```

After that, a normal `git pull` extracts the assets.

- The hooks are `post-merge` (merge pulls) and `post-rewrite` (rebases).
- If an `artisan` file exists, the hook runs `php artisan build:extract`. Otherwise it runs `php vendor/bin/build-assets extract`.
- Hooks are local to each clone. Git does not copy them with the repository.
- If the hook fails, the pull has already happened and is not undone.
- Only install hooks in repositories you trust.

## Security

- **Checksum:** the ZIP's SHA-256 must match the manifest before anything is extracted. This detects corruption. It is not a signature: someone who can change both files in the repository can pass the check.
- **Safe extraction:** entries with path traversal (`../`), absolute paths, symlinks or duplicate names are rejected.
- **Size limits:** at most 20,000 entries and 1 GiB uncompressed.
- **Staged swap:** files are unpacked to a temporary folder first, then swapped in. If the swap fails, the previous assets are restored.
- **Locking:** `.build-assets.lock` stops two extractions from running at the same time.
- **No surprise commands:** extract never runs npm or Git commits. The compile command only runs with `--compile`.

## Limitations

- **No rollback history.** The previous assets are deleted after a successful swap.
- **Short gap during the swap.** The destination folder is briefly missing between the two renames.
- **Caches.** With aggressive opcode or file caches, clear them as part of your deployment.
- **Reserved settings.** `git.auto_extract` and `extraction.skip_unchanged` have no effect yet.

## Troubleshooting

| Message | What to do |
| --- | --- |
| `Build directory missing or unsafe` | Run your frontend build first, or check `source` in the config. |
| `Manifest missing. Run pack first.` | Run `pack` and commit both `build.zip` and `build-manifest.json`. |
| `Manifest invalid or does not match configuration` | The `archive` or `destination` setting changed since the last pack. Pack again. |
| `Archive checksum mismatch. Refusing to extract.` | The ZIP and manifest are from different packs, or the ZIP is damaged. Pack again and commit both files together. |
| `Git working tree has uncommitted changes; refusing to pull.` | Commit, stash or discard the changes on the server, then deploy again. |
| `Config file not found` | The `--config` path is wrong. |

## Tests

```bash
composer install
vendor/bin/phpunit
```

The tests need Git and the `zip` extension.

## License

MIT, see `LICENSE`.
