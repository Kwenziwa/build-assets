<?php

declare(strict_types=1);

namespace InfluxIT\BuildAssets\Core;

use InvalidArgumentException;

final class Configuration
{
    public readonly string $root;

    public function __construct(string $root, public readonly array $values)
    {
        if (!is_dir($root)) {
            throw new InvalidArgumentException("Project directory does not exist: {$root}");
        }
        // Resolve symlinks (e.g. a "current" release link) so path checks compare like with like.
        $this->root = realpath($root) ?: $root;
        foreach (['source', 'destination', 'archive', 'manifest'] as $key) {
            $this->path($key);
        }
        if ($this->path('archive') === $this->path('manifest')) {
            throw new InvalidArgumentException('Archive and manifest paths must differ.');
        }
        foreach (['archive', 'manifest'] as $name) {
            $file = $this->path($name);
            foreach (['source', 'destination'] as $directory) {
                $dir = $this->path($directory);
                if ($file === $dir || str_starts_with($file, $dir . DIRECTORY_SEPARATOR)) {
                    throw new InvalidArgumentException("$name must not live inside $directory.");
                }
            }
        }
    }

    public static function load(string $root, ?string $customFile = null): self
    {
        $root = realpath($root) ?: $root;
        $defaults = require dirname(__DIR__, 2) . '/config/build-assets.php';
        if ($customFile !== null && !is_file($customFile)) {
            throw new InvalidArgumentException("Config file not found: {$customFile}");
        }
        $file = $customFile ?? $root . '/build-assets.php';
        if (is_file($file)) {
            $local = require $file;
            if (!is_array($local)) {
                throw new InvalidArgumentException('Build assets config must return an array.');
            }
            $defaults = array_replace_recursive($defaults, $local);
        }
        return new self($root, $defaults);
    }

    public function path(string $key): string
    {
        $value = $this->values[$key] ?? null;
        if (!is_string($value) || $value === '' || str_contains($value, "\0")) {
            throw new InvalidArgumentException("Invalid configuration path: $key");
        }
        $relative = str_replace('\\', '/', $value);
        if (str_starts_with($relative, '/') || preg_match('~^[A-Za-z]:~', $relative)) {
            throw new InvalidArgumentException("Absolute paths are not allowed for $key");
        }
        $parts = explode('/', $relative);
        foreach ($parts as $part) {
            if ($part === '..' || $part === '' || $part === '.') {
                throw new InvalidArgumentException("Unsafe path in $key: $value");
            }
        }
        $root = rtrim($this->root, '/\\');
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $existing = $path;
        while (!file_exists($existing) && !is_link($existing)) {
            $parent = dirname($existing);
            if ($parent === $existing) { break; }
            $existing = $parent;
        }
        if (is_link($existing)) {
            throw new InvalidArgumentException("Symlink path is not allowed: $key");
        }
        $real = realpath($existing);
        if ($real !== false && $real !== $root && !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException("Path escapes project root: $key");
        }
        return $path;
    }

    public function option(string $section, string $key, mixed $default = null): mixed
    {
        return $this->values[$section][$key] ?? $default;
    }
}
