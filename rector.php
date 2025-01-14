<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/messages',
    ])
    ->withRootFiles()
    // uncomment to reach your current PHP version
    // ->withPhpSets()
    ->withTypeCoverageLevel(70)
    ->withDeadCodeLevel(70)
    ->withCodeQualityLevel(70);
