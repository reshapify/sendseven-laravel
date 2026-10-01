<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Exceptions;

use Reshapify\SendSeven\Exceptions\SendSevenException;
use RuntimeException;

/**
 * The shared request budget is spent, so the request wasn't sent. In a queued
 * job, release it: $this->release($exception->retryAfter()).
 */
final class RateLimitExceeded extends RuntimeException implements SendSevenException
{
    public function __construct(private readonly int $retryAfter, string $reason)
    {
        parent::__construct("{$reason} Retry in {$retryAfter} seconds; in a queued job, release it with \$this->release(\$exception->retryAfter()).");
    }

    /**
     * Seconds until there is capacity again.
     */
    public function retryAfter(): int
    {
        return $this->retryAfter;
    }
}
