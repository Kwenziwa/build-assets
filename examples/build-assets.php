<?php

declare(strict_types=1);

// Copy to the root of your vanilla PHP application as build-assets.php.
return [
    'source' => 'public/build',
    'destination' => 'public/build',
    'archive' => 'build.zip',
    'manifest' => 'build-manifest.json',
    'compile' => ['command' => 'npm run build'],
];
