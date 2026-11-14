<?php

declare(strict_types=1);

namespace InfluxIT\BuildAssets\Git;

use RuntimeException;

final class GitManager
{
    public function __construct(private readonly string $root) {}

    public function run(array $args): string
    {
        $command = 'git -C ' . escapeshellarg($this->root);
        foreach ($args as $arg) { $command .= ' ' . escapeshellarg($arg); }
        $output = [];
        $status = 0;
        exec($command . ' 2>&1', $output, $status);
        if ($status !== 0) { throw new RuntimeException('Git failed: ' . implode("\n", $output)); }
        return trim(implode("\n", $output));
    }

    public function pull(): string
    {
        // Untracked files (such as .build-assets.lock) do not block a fast-forward;
        // Git itself refuses the pull if one of them would be overwritten.
        $status = $this->run(['status', '--porcelain', '--untracked-files=no']);
        if ($status !== '') { throw new RuntimeException('Git working tree has uncommitted changes; refusing to pull.'); }
        return $this->run(['pull', '--ff-only']);
    }

    public function hooksDirectory(): string
    {
        // --git-path follows worktrees, gitdir indirection and core.hooksPath.
        $relative = $this->run(['rev-parse', '--git-path', 'hooks']);
        if ($relative === '') { throw new RuntimeException('Cannot find Git hooks directory.'); }
        if (str_starts_with($relative, '/')) { return $relative; }
        return $this->root . '/' . $relative;
    }
}
