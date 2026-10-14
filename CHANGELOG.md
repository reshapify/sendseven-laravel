# Changelog

All notable changes to `reshapify/sendseven-laravel` are documented here.

## 0.3.0 - 2026-10-13

- Requires `reshapify/sendseven` ^0.2, which adds `sendText()`, `sendButtons()`, `sendList()`, `contacts()->findOrCreateByPhone()` and webhook message helpers (`images()`, `location()`, `typedText()`, broader `buttonReply()`).
- Fix: a delivery whose listener threw was remembered as handled, so SendSeven's redelivery was skipped as a duplicate and the event lost. The event is now forgotten when a listener throws.
- Octane: `ClientFactory` is scoped, and the webhook controller and secret resolver read config per request, so a worker never keeps the first request's configuration.

## 0.2.1 - 2026-10-01

- Static-analysis fix for newer Larastan; no behaviour change.

## 0.2.0 - 2026-10-01

- Webhook endpoint commands: `sendseven:webhooks:list`, `show`, `deliveries`, `retry`, `test`, `activate`, `update`, `rotate` and `delete`. Without an ID they act on the endpoint behind `Route::sendSevenWebhooks()`, or the only one there is.

## 0.1.1 - 2026-10-01

- `sendseven:doctor` no longer fails on a missing webhook secret when the app doesn't use `Route::sendSevenWebhooks()` and handles webhooks itself.

## 0.1.0 - 2026-10-01

- Requests go through Laravel's HTTP client, so `Http::fake()`, `Http::preventStrayRequests()` and Telescope see them.
- `SendSeven` facade and a container-bound `Client` built from `config/sendseven.php`; `SendSeven::forToken()` for per-customer tokens and base URIs.
- `Route::sendSevenWebhooks()`: the verification challenge, signature and Authorization checks, replay protection, deduplication, typed events, per-endpoint secrets.
- `SendSeven::connect()` and the `ChannelConnected` event for customer channel onboarding.
- A cache-backed rate limiter that shares a token's budget between processes and tenants.
- `sendseven:doctor`, `sendseven:webhooks:register` and `sendseven:sms-prices`.
- `SendSeven::fake()` and `InteractsWithSendSevenWebhooks` for tests.
- Laravel Boost guideline and skill.
