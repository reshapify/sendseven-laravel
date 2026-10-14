<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Queue;

use Closure;
use Reshapify\SendSeven\Exceptions\RateLimited;
use Reshapify\SendSeven\Laravel\Exceptions\RateLimitExceeded;

/**
 * Job middleware: when SendSeven's rate limit is hit (the shared budget is
 * spent, or the API answered 429 after retries), the job goes back on the
 * queue until there is capacity instead of failing.
 *
 *     public function middleware(): array
 *     {
 *         return [new ReleaseWhenRateLimited];
 *     }
 *
 * Releases count as attempts, so give such jobs room with $tries or retryUntil().
 */
final readonly class ReleaseWhenRateLimited
{
    public function __construct(private int $defaultDelaySeconds = 60) {}

    public function handle(object $job, Closure $next): mixed
    {
        try {
            return $next($job);
        } catch (RateLimitExceeded $exception) {
            $delay = $exception->retryAfter();
        } catch (RateLimited $exception) {
            $delay = $exception->retryAfter() ?? $this->defaultDelaySeconds;
        }

        if (! method_exists($job, 'release')) {
            throw $exception;
        }

        $job->release(max(1, $delay));

        return null;
    }
}
