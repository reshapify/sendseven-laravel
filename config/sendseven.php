<?php

declare(strict_types=1);

use Reshapify\SendSeven\SendSeven;

return [

    /*
    | The API token (SendSeven → Settings → API Tokens). It belongs to one
    | tenant, and everything the client does happens there. Apps that give
    | each customer their own token use SendSeven::forToken() instead.
    */
    'token' => env('SENDSEVEN_API_TOKEN'),

    /*
    | Act on another tenant (sub-account) by default. Leave empty unless the
    | token is a partner token: it needs multi-tenant management on the plan
    | and the tenants:manage grant.
    */
    'tenant_id' => env('SENDSEVEN_TENANT_ID'),

    'base_uri' => env('SENDSEVEN_BASE_URI', SendSeven::BASE_URI),

    /*
    | Attempts per request, including the first. Rate limits, server errors
    | and network failures are retried; writes only with an idempotency key,
    | which the SDK adds automatically.
    */
    'retries' => [
        'max_attempts' => (int) env('SENDSEVEN_MAX_ATTEMPTS', 3),
    ],

    /*
    | SendSeven allows 100 standard requests a minute per token, shared by
    | every process using it. This keeps web requests, queue workers and
    | scheduled jobs inside one budget through the cache.
    */
    'rate_limit' => [
        'enabled' => (bool) env('SENDSEVEN_RATE_LIMIT', true),

        // Any cache store all your processes share (redis, database…); null is the default store.
        'store' => env('SENDSEVEN_RATE_LIMIT_STORE'),

        'per_minute' => 100,

        // Fraction of the budget to use, leaving room for calls made elsewhere.
        'headroom' => 0.9,

        // With a partner token: the fraction one tenant may use, so a busy tenant slows down alone.
        'tenant_share' => 1.0,

        // How long a request may wait for capacity before RateLimitExceeded is thrown.
        // Keep it at 0 in web requests; queue jobs can catch the exception and release().
        'max_wait_seconds' => 0,
    ],

    'webhooks' => [

        /*
        | The endpoint's signing secret, shown once when it's created
        | (php artisan sendseven:webhooks:register). Apps with one endpoint per
        | customer resolve secrets with SendSeven::resolveWebhookSecretUsing().
        */
        'secret' => env('SENDSEVEN_WEBHOOK_SECRET'),

        // Optional: the static Authorization header the endpoint was registered with, checked as a second gate.
        'authorization' => env('SENDSEVEN_WEBHOOK_AUTHORIZATION'),

        // Reject deliveries whose timestamp is further than this from now (replay protection).
        'tolerance_seconds' => 300,

        // Ignore redeliveries of an event already handled, for this long. 0 turns it off.
        'deduplicate_for_seconds' => 86400,

        'cache_store' => env('SENDSEVEN_WEBHOOK_CACHE_STORE'),
    ],

];
