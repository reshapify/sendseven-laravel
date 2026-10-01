<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel;

use Illuminate\Support\Sleep;
use Reshapify\SendSeven\Http\Sleeper;

/**
 * Waits through Laravel's Sleep, so Sleep::fake() keeps tests instant.
 */
final readonly class LaravelSleeper implements Sleeper
{
    public function sleep(int $milliseconds): void
    {
        Sleep::for(max(0, $milliseconds))->milliseconds();
    }
}
