<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessUnionReturnDocblockRector;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/config', __DIR__.'/tests'])
    ->withPhpSets(php83: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    )
    // Generic docblocks carry type information PHP can't express.
    ->withSkip([
        RemoveUselessReturnTagRector::class,
        RemoveUselessParamTagRector::class,
        RemoveUselessUnionReturnDocblockRector::class,
    ]);
