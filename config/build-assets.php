<?php

declare(strict_types=1);

return [
    // All paths are relative to the host application's root directory.
    'source' => 'public/build',
    'destination' => 'public/build',
    'archive' => 'build.zip',
    'manifest' => 'build-manifest.json',
    'compile' => ['command' => 'npm run build'],
    'git' => ['auto_extract' => true],
    'extraction' => ['skip_unchanged' => true],
];
