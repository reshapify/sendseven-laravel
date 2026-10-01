<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Reshapify\SendSeven\Http\Request;
use Reshapify\SendSeven\Http\Response;
use Reshapify\SendSeven\Laravel\CacheRateLimiter;
use Reshapify\SendSeven\Laravel\Exceptions\RateLimitExceeded;

function limiter(int $perMinute = 10, float $tenantShare = 1.0, int $maxWaitSeconds = 0): CacheRateLimiter
{
    return new CacheRateLimiter(Cache::store('array'), 'token', $perMinute, headroom: 1.0, tenantShare: $tenantShare, maxWaitSeconds: $maxWaitSeconds);
}

beforeEach(function (): void {
    $this->travelTo(now()->startOfMinute()->addSeconds(15));
});

it('lets requests through until the minute budget is spent', function (): void {
    $limiter = limiter(perMinute: 3);

    foreach (range(1, 3) as $ignored) {
        $limiter->acquire(Request::get('/contacts'));
    }

    expect(fn () => $limiter->acquire(Request::get('/contacts')))
        ->toThrow(function (RateLimitExceeded $exception): void {
            expect($exception->retryAfter())->toBe(45);
        });
});

it('shares the budget between limiter instances through the cache', function (): void {
    limiter(perMinute: 1)->acquire(Request::get('/contacts'));

    limiter(perMinute: 1)->acquire(Request::get('/contacts'));
})->throws(RateLimitExceeded::class);

it('starts a fresh budget each minute', function (): void {
    $limiter = limiter(perMinute: 1);
    $limiter->acquire(Request::get('/contacts'));

    $this->travel(1)->minute();

    $limiter->acquire(Request::get('/contacts'));
})->throwsNoExceptions();

it('keeps headroom for calls made elsewhere', function (): void {
    expect((new CacheRateLimiter(Cache::store('array'), 'token', perMinute: 100, headroom: 0.9))->budget())->toBe(90);
});

it('limits one tenant to its share so others keep capacity', function (): void {
    $limiter = limiter(perMinute: 4, tenantShare: 0.5);
    $acme = Request::get('/contacts')->withHeader('X-Tenant-ID', 'tenant_acme');

    $limiter->acquire($acme);
    $limiter->acquire($acme);

    expect(fn () => $limiter->acquire($acme))->toThrow(RateLimitExceeded::class, 'tenant_acme');

    $limiter->acquire(Request::get('/contacts')->withHeader('X-Tenant-ID', 'tenant_other'));
    $limiter->acquire(Request::get('/contacts'));
});

it('pauses everyone for as long as a 429 asks', function (): void {
    limiter()->observe(Request::get('/contacts'), new Response(429, '{}', ['retry-after' => '20']));

    expect(fn () => limiter()->acquire(Request::get('/contacts')))
        ->toThrow(function (RateLimitExceeded $exception): void {
            expect($exception->retryAfter())->toBe(20);
        });

    $this->travel(21)->seconds();

    limiter()->acquire(Request::get('/contacts'));
});

it('pauses until the reset when SendSeven reports nothing remaining', function (): void {
    limiter()->observe(Request::get('/contacts'), new Response(200, '{}', [
        'x-ratelimit-remaining' => '0',
        'x-ratelimit-reset' => (string) (now()->getTimestamp() + 30),
    ]));

    expect(fn () => limiter()->acquire(Request::get('/contacts')))
        ->toThrow(function (RateLimitExceeded $exception): void {
            expect($exception->retryAfter())->toBe(30);
        });
});

it('waits for capacity when allowed to', function (): void {
    Sleep::fake(syncWithCarbon: true);
    $limiter = limiter(perMinute: 1, maxWaitSeconds: 60);

    $limiter->acquire(Request::get('/contacts'));
    $limiter->acquire(Request::get('/contacts'));

    Sleep::assertSleptTimes(1);
    Sleep::assertSequence([Sleep::for(45)->seconds()]);
});
