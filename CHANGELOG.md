# Changelog

All notable changes to `reshapify/laravel-sendseven` are documented here.

## Unreleased

- `SendSeven` facade and a container-bound `Client` built from `config/sendseven.php`; `SendSeven::forToken()` for per-customer tokens.
- `Route::sendSevenWebhooks()`: the verification challenge, signature and Authorization checks, replay protection, deduplication, typed events, per-endpoint secrets.
- `SendSeven::connect()` and the `ChannelConnected` event for customer channel onboarding.
- A cache-backed rate limiter that shares a token's budget between processes and tenants.
- `sendseven:doctor`, `sendseven:webhooks:register` and `sendseven:sms-prices`.
- `SendSeven::fake()` and `InteractsWithSendSevenWebhooks` for tests.
- Laravel Boost guideline and skill.
