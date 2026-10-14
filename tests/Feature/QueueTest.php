<?php

declare(strict_types=1);

use Reshapify\SendSeven\Exceptions\ErrorDetail;
use Reshapify\SendSeven\Exceptions\RateLimited;
use Reshapify\SendSeven\Http\Request;
use Reshapify\SendSeven\Http\Response;
use Reshapify\SendSeven\Laravel\Exceptions\RateLimitExceeded;
use Reshapify\SendSeven\Laravel\Queue\ReleaseWhenRateLimited;

function releasableJob(): object
{
    return new class
    {
        public ?int $releasedFor = null;

        public function release(int $delay = 0): void
        {
            $this->releasedFor = $delay;
        }
    };
}

it('releases a job when the shared budget is spent', function (): void {
    $job = releasableJob();

    (new ReleaseWhenRateLimited)->handle($job, fn () => throw new RateLimitExceeded(17, 'Budget spent.'));

    expect($job->releasedFor)->toBe(17);
});

it('releases a job when SendSeven answers 429', function (): void {
    $job = releasableJob();
    $response = Response::json(['error' => 'Too many requests'], 429, ['retry-after' => '42']);
    $exception = new RateLimited(Request::get('/messages'), $response, ErrorDetail::from($response));

    (new ReleaseWhenRateLimited)->handle($job, fn () => throw $exception);

    expect($job->releasedFor)->toBe(42);
});

it('lets other failures through', function (): void {
    (new ReleaseWhenRateLimited)->handle(releasableJob(), fn () => throw new RuntimeException('Boom'));
})->throws(RuntimeException::class, 'Boom');
