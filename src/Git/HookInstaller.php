<?php

declare(strict_types=1);

namespace InfluxIT\BuildAssets\Git;

use RuntimeException;

final class HookInstaller
{
    private const START = '# >>> influxit/build-assets >>>';
    private const END = '# <<< influxit/build-assets <<<';

    public function __construct(private readonly GitManager $git) {}

    public function install(): void
    {
        $directory = $this->git->hooksDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create Git hooks directory.');
        }
        foreach (['post-merge', 'post-rewrite'] as $name) {
            $path = $directory . '/' . $name;
            if (is_link($path)) { throw new RuntimeException("Refusing to edit symlink hook: $path"); }
            $content = is_file($path) ? (string) file_get_contents($path) : "#!/bin/sh\n";
            if (str_contains($content, self::START)) { continue; }
            if (!str_starts_with($content, '#!/bin/sh') && !str_starts_with($content, '#!/usr/bin/env sh') && !str_starts_with($content, '#!/bin/bash')) {
                throw new RuntimeException("Hook $name is not a supported shell script; cannot append safely.");
            }
            $snippet = "\n" . self::START . "\n"
                . "if [ -f artisan ]; then\n"
                . "  php artisan build:extract || exit \$?\n"
                . "elif [ -f vendor/bin/build-assets ]; then\n"
                . "  php vendor/bin/build-assets extract || exit \$?\n"
                . "fi\n" . self::END . "\n";
            if (file_put_contents($path, rtrim($content) . "\n" . $snippet) === false) {
                throw new RuntimeException("Cannot install hook: $name");
            }
            chmod($path, 0755);
        }
    }

    public function remove(): void
    {
        $directory = $this->git->hooksDirectory();
        foreach (['post-merge', 'post-rewrite'] as $name) {
            $path = $directory . '/' . $name;
            if (!is_file($path) || is_link($path)) { continue; }
            $content = (string) file_get_contents($path);
            $start = strpos($content, self::START);
            if ($start === false) { continue; }
            $end = strpos($content, self::END, $start);
            if ($end === false) { throw new RuntimeException("Malformed managed hook: $name"); }
            $new = substr($content, 0, $start) . substr($content, $end + strlen(self::END));
            if (trim($new) === '#!/bin/sh') { unlink($path); }
            elseif (file_put_contents($path, rtrim($new) . "\n") === false) {
                throw new RuntimeException("Cannot remove hook: $name");
            }
        }
    }
}
