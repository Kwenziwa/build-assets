<?php

declare(strict_types=1);

namespace InfluxIT\BuildAssets\Core;

use RuntimeException;
use ZipArchive;

final class Extractor
{
    public const MARKER = '.build-assets-state.json';

    public function __construct(private readonly Configuration $config, private readonly ArchiveManager $archives) {}

    public function extract(bool $force = false): string
    {
        $manifest = $this->archives->verify();
        $destination = $this->config->path('destination');
        $parent = dirname($destination);
        if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new RuntimeException('Unable to create destination parent.');
        }
        $lockPath = $this->config->root . '/.build-assets.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) { throw new RuntimeException('Unable to lock extraction.'); }
        try {
            if (!$force && $this->isCurrent($manifest)) { return 'unchanged'; }
            $stage = $parent . '/.build-stage-' . bin2hex(random_bytes(8));
            if (!mkdir($stage, 0775)) { throw new RuntimeException('Unable to create staging directory.'); }
            try {
                $this->extractInto($this->config->path('archive'), $stage, $manifest['files']);
                $marker = json_encode(['sha256' => $manifest['sha256']], JSON_THROW_ON_ERROR);
                if (file_put_contents($stage . '/' . self::MARKER, $marker) === false) {
                    throw new RuntimeException('Unable to save deployment marker.');
                }
                $backup = $parent . '/.build-backup-' . bin2hex(random_bytes(8));
                $hadOld = file_exists($destination) || is_link($destination);
                if ($hadOld) {
                    if (is_link($destination) || !is_dir($destination)) { throw new RuntimeException('Destination is not a regular directory.'); }
                    if (!rename($destination, $backup)) { throw new RuntimeException('Could not back up deployed assets.'); }
                }
                if (!rename($stage, $destination)) {
                    if ($hadOld && !rename($backup, $destination)) {
                        throw new RuntimeException("Swap failed and rollback failed. Recover assets from $backup");
                    }
                    throw new RuntimeException('Could not activate staged assets; previous assets restored.');
                }
                if ($hadOld) { $this->removeDirectory($backup); }
                return 'extracted';
            } finally {
                if (is_dir($stage)) { $this->removeDirectory($stage); }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function isCurrent(?array $manifest = null): bool
    {
        $manifest ??= $this->archives->verify();
        $destination = $this->config->path('destination');
        if (is_link($destination)) { return false; }
        $marker = $destination . '/' . self::MARKER;
        if (!is_file($marker) || is_link($marker)) { return false; }
        $state = json_decode((string) file_get_contents($marker), true);
        return is_array($state) && hash_equals($manifest['sha256'], (string) ($state['sha256'] ?? ''));
    }

    private function extractInto(string $archive, string $stage, int $expectedCount): void
    {
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) { throw new RuntimeException('Cannot open ZIP archive.'); }
        $files = 0;
        $totalSize = 0;
        $names = [];
        try {
            if ($zip->numFiles > 20000) { throw new RuntimeException('ZIP contains too many entries.'); }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) { throw new RuntimeException('Unable to inspect ZIP entry.'); }
                $name = $stat['name'];
                if (!is_string($name) || !$this->validEntryName($name)) {
                    throw new RuntimeException('Unsafe ZIP entry path.');
                }
                $directory = str_ends_with($name, '/');
                $normalized = rtrim($name, '/');
                if (isset($names[$normalized])) { throw new RuntimeException("Duplicate ZIP entry: $name"); }
                $names[$normalized] = true;
                $attributes = $zip->getExternalAttributesIndex($i, $opsys, $attrs);
                if ($attributes && $opsys === ZipArchive::OPSYS_UNIX) {
                    $mode = ($attrs >> 16) & 0170000;
                    if ($mode !== 0 && $mode !== 0100000 && $mode !== 0040000) {
                        throw new RuntimeException("Unsupported ZIP entry type: $name");
                    }
                }
                if (!$directory) {
                    $files++;
                    $totalSize += (int) ($stat['size'] ?? 0);
                    if ($totalSize > 1024 * 1024 * 1024) { throw new RuntimeException('Uncompressed ZIP size exceeds 1 GiB limit.'); }
                }
            }
            if ($files !== $expectedCount) { throw new RuntimeException('ZIP file count does not match manifest.'); }
            foreach (array_keys($names) as $name) {
                $segments = explode('/', $name);
                array_pop($segments);
                $prefix = '';
                foreach ($segments as $segment) {
                    $prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
                    if (isset($names[$prefix])) {
                        // An explicit directory is fine, but an entry cannot be both file and parent.
                        $index = $zip->locateName($prefix);
                        if ($index !== false) { throw new RuntimeException("File conflicts with directory: $prefix"); }
                    }
                }
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $target = $stage . '/' . rtrim($name, '/');
                if (str_ends_with($name, '/')) {
                    if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                        throw new RuntimeException("Cannot create directory: $name");
                    }
                    continue;
                }
                $dir = dirname($target);
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                    throw new RuntimeException("Cannot create directory for: $name");
                }
                $input = $zip->getStream($name);
                if ($input === false) { throw new RuntimeException("Cannot read ZIP entry: $name"); }
                $output = fopen($target, 'xb');
                if ($output === false) { fclose($input); throw new RuntimeException("Cannot create file: $name"); }
                try {
                    $copied = stream_copy_to_stream($input, $output, 1024 * 1024 * 1024 + 1);
                    if ($copied === false || $copied !== $zip->statIndex($i)['size']) {
                        throw new RuntimeException("Incomplete ZIP entry: $name");
                    }
                } finally { fclose($input); fclose($output); }
            }
        } finally { $zip->close(); }
    }

    private function validEntryName(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') ||
            str_starts_with($name, '/') || preg_match('~^[A-Za-z]:~', $name)) { return false; }
        foreach (explode('/', rtrim($name, '/')) as $part) {
            if ($part === '' || $part === '.' || $part === '..') { return false; }
        }
        return !str_starts_with($name, self::MARKER);
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); }
            else { unlink($entry->getPathname()); }
        }
        rmdir($directory);
    }
}
