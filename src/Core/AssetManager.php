<?php

declare(strict_types=1);

namespace InfluxIT\BuildAssets\Core;

use InfluxIT\BuildAssets\Git\GitManager;
use RuntimeException;

final class AssetManager
{
    public readonly ArchiveManager $archives;
    public readonly Extractor $extractor;

    public function __construct(public readonly Configuration $config)
    {
        $this->archives = new ArchiveManager($config);
        $this->extractor = new Extractor($config, $this->archives);
    }

    public function pack(bool $compile = false): array
    {
        if ($compile) { $this->compile(); }
        return $this->archives->pack();
    }

    public function extract(bool $force = false): string
    {
        return $this->extractor->extract($force);
    }

    /**
     * @param list<string>|null $extractCommand Command that runs "extract" in a new PHP process.
     *        After a pull, this process still holds the pre-pull code and configuration,
     *        so extraction is handed to a fresh process when a command is supplied.
     */
    public function deploy(bool $pull = false, ?array $extractCommand = null): string
    {
        if (!$pull) { return $this->extract(); }
        (new GitManager($this->config->root))->pull();
        if ($extractCommand === null || $extractCommand === [] || !function_exists('proc_open')) {
            return $this->extract();
        }
        return $this->extractInFreshProcess($extractCommand);
    }

    /** @param list<string> $command */
    private function extractInFreshProcess(array $command): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Unable to start extraction process.'); }
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            $detail = trim((string) $errors) !== '' ? trim((string) $errors) : trim((string) $output);
            throw new RuntimeException("Extraction failed after pull (exit code $exit): $detail");
        }
        return preg_match('/Assets (extracted|unchanged)/', (string) $output, $match) === 1 ? $match[1] : 'extracted';
    }

    public function status(): array
    {
        $manifest = $this->archives->verify();
        return [
            'archive' => $this->config->values['archive'],
            'destination' => $this->config->values['destination'],
            'files' => $manifest['files'],
            'sha256' => $manifest['sha256'],
            'deployed' => $this->extractor->isCurrent($manifest),
        ];
    }

    private function compile(): void
    {
        $command = $this->config->option('compile', 'command');
        if (!is_string($command) || trim($command) === '') {
            throw new RuntimeException('Compile command not configured.');
        }
        $previous = getcwd();
        if (!chdir($this->config->root)) { throw new RuntimeException('Unable to change working directory.'); }
        try {
            passthru($command, $exit);
            if ($exit !== 0) { throw new RuntimeException("Frontend build failed (exit code $exit)."); }
        } finally { if ($previous !== false) { chdir($previous); } }
    }
}
