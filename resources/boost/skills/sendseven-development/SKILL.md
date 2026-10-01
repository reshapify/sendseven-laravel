---
name: sendseven-development
description: Use when sending messages through SendSeven (WhatsApp, SMS, email, Telegram, Messenger, Instagram, RCS, browser push), handling SendSeven webhooks, letting customers connect channels (WhatsApp Embedded Signup via connect links), working with SendSeven tenants, or testing any of these in a Laravel app using reshapify/laravel-sendseven.
---

# SendSeven in Laravel

## Find the method

Every endpoint is in `vendor/reshapify/sendseven/openapi/manifest.json` (search by path, operationId or summary): the call, its named parameters and the return type. Guides: `vendor/reshapify/sendseven/docs/guides/`. Read `vendor/reshapify/sendseven/docs/known-quirks.md` before trusting SendSeven's own spec.

## Sending

```php
use Reshapify\SendSeven\Laravel\Facades\SendSeven;

SendSeven::messages()->send(to: '+4915112345678', channelId: $channelId, text: 'Hi');
```

Or inject `Reshapify\SendSeven\Client`. For a customer's own token: `SendSeven::forToken($token)`. With a partner token: `SendSeven::forTenant($tenantId)`, which needs multi-tenant management on the plan; check with `SendSeven::capabilities()`.

The shared rate limit throws `RateLimitExceeded` when the minute's budget is spent. In queued jobs, catch it and `$this->release($exception->retryAfter())`.

## Webhooks

```php
// routes/web.php: answers the challenge, verifies signatures, ignores redeliveries
Route::sendSevenWebhooks();                       // or 'webhooks/sendseven/{workspace}' + a secret resolver

// AppServiceProvider::boot(), for an endpoint per customer
SendSeven::resolveWebhookSecretUsing(fn (Request $request) => Workspace::find($request->route('workspace'))?->sendseven_webhook_secret);
```

Listeners type-hint the SDK event they want; queue them if they do real work:

```php
use Reshapify\SendSeven\Webhooks\Events\MessageReceived;

final class StoreInboundMessage implements ShouldQueue
{
    public function handle(MessageReceived $event): void
    {
        $event->message->fromId;            // sender: phone number, or scoped ID on Telegram/Messenger/Instagram
        $event->message->text;
        $event->message->buttonReply()?->id;
        $event->message->attachment()?->downloadUrl();
    }
}
```

Other events: `MessageStatusUpdated` (`failed()`, `message->errorCode()`), `MessageReactionChanged`, `ChannelEvent`, `ContactEvent`, `ConversationEvent`, `UnknownEvent`. `Reshapify\SendSeven\Laravel\Events\WebhookReceived` carries every delivery plus the route parameters. `ChannelConnected` fires on `channel.created`.

Set up with `php artisan sendseven:webhooks:register` (prints `SENDSEVEN_WEBHOOK_SECRET`) and check everything with `php artisan sendseven:doctor`.

## Customers connecting channels

```php
use Reshapify\SendSeven\Enums\ChannelType;
use Reshapify\SendSeven\Onboarding\{ConnectLink, WhatsAppMode};

public function store(Workspace $workspace)
{
    $connection = SendSeven::connect(
        ConnectLink::for(ChannelType::WhatsApp)->modes(whatsapp: [WhatsAppMode::Classic])
            ->brandedAs(config('app.name'))->redirectTo(route('channels.index'))->singleUse(),
    );

    $workspace->update(['sendseven_connect_link_id' => $connection->link->id]);

    return $connection;   // redirects; Inertia requests get a full-page visit
}

// Listener
public function handle(ChannelConnected $event): void
{
    $workspace = Workspace::firstWhere('sendseven_connect_link_id', $event->connectLinkId());
}
```

This is how WhatsApp Embedded Signup works on SendSeven: never load Meta's SDK yourself. RCS (Germany only) and browser push aren't connected this way.

## Testing

```php
$fake = SendSeven::fake(['POST /messages' => ['id' => 'msg_1', 'direction' => 'outbound', 'message_type' => 'text', 'status' => 'queued', 'created_at' => '2026-10-01T09:00:00Z']]);
// ... exercise the app ...
$fake->assertSent('POST /messages', fn ($request) => $request->body['to'] === '+4915112345678');
```

```php
use Reshapify\SendSeven\Laravel\Testing\InteractsWithSendSevenWebhooks;   // in TestCase

$this->postSendSevenWebhook('/webhooks/sendseven', $this->sendSevenWebhookPayload('message.received', [
    'message' => ['id' => 'msg_1', 'direction' => 'inbound', 'message_type' => 'text', 'text' => 'Hi', 'from_id' => '+4915112345678'],
]))->assertOk();
```

An unscripted request throws `UnexpectedRequest`, which says how to script it.

## Errors

Catch `Reshapify\SendSeven\Exceptions\SendSevenException`. Messages say how to fix the problem: show them, don't swallow them.
