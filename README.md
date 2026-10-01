# SendSeven for Laravel

> Unofficial. Not affiliated with or endorsed by SendSeven GmbH or Laravel Holdings.

Laravel integration for [`reshapify/sendseven`](https://github.com/reshapify/sendseven-php), the typed SDK for the [SendSeven](https://sendseven.com) messaging API (WhatsApp, SMS, email, Telegram, Messenger, Instagram, RCS, browser push).

- **Configured client:** the `SendSeven` facade, or inject `Reshapify\SendSeven\Client`.
- **Webhooks in one line:** `Route::sendSevenWebhooks()` answers SendSeven's verification challenge, verifies signatures, ignores redeliveries and dispatches typed Laravel events.
- **Customer onboarding:** `SendSeven::connect()` sends customers to SendSeven's connect page (WhatsApp Embedded Signup, Instagram, Messenger, Telegram…) and tells you when their channel arrives.
- **One rate limit for every process:** web requests, queue workers and the scheduler share the token's 100-requests-a-minute budget through your cache.
- **`php artisan sendseven:doctor`** checks the token, the plan, tenancy and the webhook endpoint against the live API.
- **Fakes** for the API and signed webhook deliveries.

## Install

```bash
composer require reshapify/sendseven-laravel
php artisan vendor:publish --tag=sendseven-config   # optional
```

Composer may ask whether to trust `php-http/discovery`, a plugin of the core SDK. Laravel already ships an HTTP client, so answering "no" is fine.

```dotenv
SENDSEVEN_API_TOKEN=s7_api_...
```

## Send

```php
use Reshapify\SendSeven\Laravel\Facades\SendSeven;

SendSeven::messages()->send(to: '+4915112345678', channelId: $channelId, text: 'Your order has shipped.');
```

Every SendSeven endpoint is available; see the [SDK docs](https://github.com/reshapify/sendseven-php#readme). For a customer's own token use `SendSeven::forToken($token)`; for a sub-account with a partner token use `SendSeven::forTenant($tenantId)`.

## Receive webhooks

```php
// routes/web.php (CSRF is skipped for this route; deliveries are signed instead)
Route::sendSevenWebhooks();   // POST /webhooks/sendseven, named sendseven.webhooks
```

```bash
php artisan sendseven:webhooks:register   # creates the endpoint and prints SENDSEVEN_WEBHOOK_SECRET
php artisan sendseven:doctor              # confirms SendSeven verified it
```

Listen for the SDK's own event classes:

```php
use Reshapify\SendSeven\Webhooks\Events\MessageReceived;

final class StoreInboundMessage implements ShouldQueue
{
    public function handle(MessageReceived $event): void
    {
        Inbox::store(from: $event->message->fromId, text: $event->message->text);
    }
}
```

| Event | When |
|---|---|
| `Reshapify\SendSeven\Webhooks\Events\MessageReceived` | Someone messaged you |
| `…\MessageStatusUpdated` | Sent, delivered, read or failed (`failed()`, `message->errorCode()`) |
| `…\MessageReactionChanged`, `ChannelEvent`, `ContactEvent`, `ConversationEvent`, `UnknownEvent` | The rest |
| `Reshapify\SendSeven\Laravel\Events\ChannelConnected` | A channel was created, e.g. through a connect link |
| `Reshapify\SendSeven\Laravel\Events\WebhookReceived` | Every delivery, with the route parameters |

Each event ID is handled once (configurable: `webhooks.deduplicate_for_seconds`).

**An endpoint per customer?** Put a parameter in the URL and resolve its secret:

```php
Route::sendSevenWebhooks('webhooks/sendseven/{workspace}');

// AppServiceProvider::boot()
SendSeven::resolveWebhookSecretUsing(
    fn (Request $request) => Workspace::find($request->route('workspace'))?->sendseven_webhook_secret,
);
```

Returning `null` answers 404. To use your own controller instead, add the `Reshapify\SendSeven\Laravel\Webhooks\VerifyWebhookSignature` middleware and read the event with `VerifyWebhookSignature::event($request)`.

## Let customers connect their channels

```php
use Reshapify\SendSeven\Enums\ChannelType;
use Reshapify\SendSeven\Onboarding\ConnectLink;
use Reshapify\SendSeven\Onboarding\WhatsAppMode;

public function store(Workspace $workspace)
{
    $connection = SendSeven::connect(
        ConnectLink::for(ChannelType::WhatsApp)
            ->modes(whatsapp: [WhatsAppMode::Classic])
            ->brandedAs(config('app.name'))
            ->redirectTo(route('channels.index'))
            ->singleUse(),
    );

    $workspace->update(['sendseven_connect_link_id' => $connection->link->id]);

    return $connection;   // a redirect; Inertia requests get a full-page visit
}
```

```php
public function handle(ChannelConnected $event): void
{
    Workspace::firstWhere('sendseven_connect_link_id', $event->connectLinkId())?->channels()->create([...]);
}
```

SendSeven runs Meta's Embedded Signup on its connect page; the SDK's [onboarding guide](https://github.com/reshapify/sendseven-php/blob/main/docs/guides/onboarding-channels.md) covers modes, delegation and what customers see.

## Rate limits

SendSeven allows 100 standard requests a minute per token. The add-on counts every request in your cache (set `SENDSEVEN_RATE_LIMIT_STORE` to a store all processes share, like `redis`), keeps 10% headroom, and pauses everyone when SendSeven answers 429. When the minute's budget is spent it throws `RateLimitExceeded` instead of sending:

```php
public function handle(): void
{
    try {
        SendSeven::messages()->send(...);
    } catch (RateLimitExceeded $exception) {
        $this->release($exception->retryAfter());
    }
}
```

With a partner token, `rate_limit.tenant_share` stops one busy tenant from starving the others.

## Testing

SendSeven traffic goes through Laravel's HTTP client, so `Http::fake()`, `Http::preventStrayRequests()` and `Http::assertSent()` already cover it. Set `SENDSEVEN_MAX_ATTEMPTS=1` in `phpunit.xml` so faked 5xx responses aren't retried.

Or script SendSeven's responses by endpoint:

```php
$fake = SendSeven::fake([
    'POST /messages' => ['id' => 'msg_1', 'direction' => 'outbound', 'message_type' => 'text', 'status' => 'queued', 'created_at' => '2026-10-01T09:00:00Z'],
]);

$this->post('/orders/1/ship');

$fake->assertSent('POST /messages', fn ($request) => $request->body['to'] === '+4915112345678');
```

Webhooks, signed as SendSeven signs them:

```php
use Reshapify\SendSeven\Laravel\Testing\InteractsWithSendSevenWebhooks;

$this->postSendSevenWebhook('/webhooks/sendseven', $this->sendSevenWebhookPayload('message.received', [
    'message' => ['id' => 'msg_1', 'direction' => 'inbound', 'message_type' => 'text', 'text' => 'Hi', 'from_id' => '+4915112345678'],
]))->assertOk();
```

## Commands

| Command | Does |
|---|---|
| `sendseven:doctor` | Checks the token, plan, tenancy, webhook secret and endpoint, and the rate-limit store |
| `sendseven:webhooks:register {url?}` | Creates the endpoint, prints its secret, confirms verification |
| `sendseven:sms-prices {country?}` | SMS prices per segment |

## For AI agents

With [Laravel Boost](https://github.com/laravel/boost), `php artisan boost:install` picks up this package's guidelines and its `sendseven-development` skill. Without it, point your agent at `vendor/reshapify/sendseven-laravel/resources/boost/skills/sendseven-development/SKILL.md`.

## Security

Report vulnerabilities privately; see [SECURITY.md](SECURITY.md).

## License

MIT. Unofficial: not affiliated with or endorsed by SendSeven GmbH or Laravel Holdings. "SendSeven" and "Laravel" are trademarks of their owners and are used only to say what this package works with.
