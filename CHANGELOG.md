# Changelog

All notable changes to `reshapify/sendseven-laravel` are documented here.

## 0.1.0 - 2026-10-01

- Requests go through Laravel's HTTP client, so `Http::fake()`, `Http::preventStrayRequests()` and Telescope see them.
- `SendSeven` facade and a container-bound `Client` built from `config/sendseven.php`; `SendSeven::forToken()` for per-customer tokens and base URIs.
- `Route::sendSevenWebhooks()`: the verification challenge, signature and Authorization checks, replay protection, deduplication, typed events, per-endpoint secrets.
- `SendSeven::connect()` and the `ChannelConnected` event for customer channel onboarding.
- A cache-backed rate limiter that shares a token's budget between processes and tenants.
- `sendseven:doctor`, `sendseven:webhooks:register` and `sendseven:sms-prices`.
- `SendSeven::fake()` and `InteractsWithSendSevenWebhooks` for tests.
- Laravel Boost guideline and skill.
