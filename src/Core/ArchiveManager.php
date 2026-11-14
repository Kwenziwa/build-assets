<?php

declare(strict_types=1);

namespace InfluxIT\BuildAssets\Core;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final class ArchiveManager
{
    /** 2000-01-01 00:00:00 UTC, applied to every entry so archives are reproducible. */
    private const FIXED_MTIME = 946684800;

    public function __construct(private readonly Configuration $config) {}

    /** @return array<string, mixed> */
    public function pack(): array
    {
        $source = $this->config->path('source');
        if (!is_dir($source) || is_link($source)) {
            throw new RuntimeException("Build directory missing or unsafe: $source. Run your frontend build first.");
        }
        $archive = $this->config->path('archive');
        $manifestFile = $this->config->path('manifest');
        $this->makeParent($archive);
        $this->makeParent($manifestFile);
        $temp = tempnam(dirname($archive), '.assets-');
        if ($temp === false) { throw new RuntimeException('Unable to create temporary archive.'); }
        $zip = new ZipArchive();
        $files = 0;
        try {
            if ($zip->open($temp, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
                throw new RuntimeException('Unable to open ZIP for writing.');
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            // Collect first, then add in sorted order with fixed metadata, so the same
            // build output always produces a byte-identical archive (and checksum).
            $entries = [];
            foreach ($iterator as $item) {
                $absolute = $item->getPathname();
                if ($item->isLink()) { throw new RuntimeException("Symlinks are not allowed in assets: $absolute"); }
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($source) + 1));
                // The deployment marker is written by extract; it is never part of the build.
                if ($relative === Extractor::MARKER) { continue; }
                if ($item->isDir()) { $entries[$relative] = null; }
                elseif ($item->isFile()) { $entries[$relative] = $absolute; }
                else { throw new RuntimeException("Unsupported file type: $relative"); }
            }
            ksort($entries, SORT_STRING);
            $previousZone = getenv('TZ');
            putenv('TZ=UTC'); // ZIP stores local time; pin the zone so timestamps do not vary per machine.
            try {
                foreach ($entries as $relative => $absolute) {
                    $relative = (string) $relative;
                    if ($absolute === null) {
                        if (!$zip->addEmptyDir($relative)) { throw new RuntimeException("Cannot add directory: $relative"); }
                        $name = $relative . '/';
                        $mode = 0040755;
                    } else {
                        if (!$zip->addFile($absolute, $relative)) { throw new RuntimeException("Cannot add file: $relative"); }
                        $name = $relative;
                        $mode = 0100644;
                        $files++;
                    }
                    if (!$zip->setMtimeName($name, self::FIXED_MTIME) ||
                        !$zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, $mode << 16)) {
                        throw new RuntimeException("Cannot normalise metadata for: $relative");
                    }
                }
                if (!$zip->close()) { throw new RuntimeException('Could not finish writing ZIP.'); }
            } finally {
                putenv($previousZone === false ? 'TZ' : 'TZ=' . $previousZone);
            }
            if ($files === 0) { throw new RuntimeException('Build directory has no files.'); }
            $sha = hash_file('sha256', $temp);
            if ($sha === false) { throw new RuntimeException('Could not checksum ZIP.'); }
            $manifest = [
                'schema_version' => 1,
                'archive' => $this->config->values['archive'],
                'source' => $this->config->values['source'],
                'destination' => $this->config->values['destination'],
                'sha256' => $sha,
                'files' => $files,
            ];
            $tempManifest = tempnam(dirname($manifestFile), '.manifest-');
            if ($tempManifest === false) { throw new RuntimeException('Unable to create temporary manifest.'); }
            try {
                if (file_put_contents($tempManifest, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false) {
                    throw new RuntimeException('Could not save manifest.');
                }
                // tempnam() creates 0600 files; make them readable like normal project files.
                chmod($temp, 0644);
                chmod($tempManifest, 0644);
                if (!rename($temp, $archive)) { throw new RuntimeException('Unable to replace archive.'); }
                if (!rename($tempManifest, $manifestFile)) { throw new RuntimeException('Unable to replace manifest.'); }
            } finally { if (is_file($tempManifest)) { unlink($tempManifest); } }
            return $manifest;
        } finally {
            if (is_file($temp)) { unlink($temp); }
        }
    }

    public function manifest(): array
    {
        $file = $this->config->path('manifest');
        if (!is_file($file)) { throw new RuntimeException('Manifest missing. Run pack first.'); }
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['schema_version'] ?? null) !== 1 ||
            !is_string($data['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/', $data['sha256']) ||
            ($data['archive'] ?? null) !== $this->config->values['archive'] ||
            ($data['destination'] ?? null) !== $this->config->values['destination'] ||
            !is_int($data['files'] ?? null) || $data['files'] < 1) {
            throw new RuntimeException('Manifest invalid or does not match configuration.');
        }
        return $data;
    }

    public function verify(): array
    {
        $manifest = $this->manifest();
        $archive = $this->config->path('archive');
        if (!is_file($archive) || is_link($archive)) { throw new RuntimeException('Archive missing or unsafe.'); }
        $actual = hash_file('sha256', $archive);
        if ($actual === false || !hash_equals($manifest['sha256'], $actual)) {
            throw new RuntimeException('Archive checksum mismatch. Refusing to extract.');
        }
        return $manifest;
    }

    private function makeParent(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory: $dir");
        }
    }
}
