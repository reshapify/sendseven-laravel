<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Facades;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Reshapify\SendSeven\Client;
use Reshapify\SendSeven\Laravel\ClientFactory;
use Reshapify\SendSeven\Laravel\ConnectRedirect;
use Reshapify\SendSeven\Laravel\ContactResolver;
use Reshapify\SendSeven\Laravel\Webhooks\WebhookSecretResolver;
use Reshapify\SendSeven\Onboarding\ConnectLink;
use Reshapify\SendSeven\Testing\Fake;
use RuntimeException;
use SensitiveParameter;

/**
 * The configured SendSeven client. Every resource is here, e.g.
 * SendSeven::messages()->send(...); the same Client can be injected.
 *
 * @mixin Client
 *
 * @see Client
 */
final class SendSeven extends Facade
{
    /**
     * A client for another token, e.g. a customer's own, with the same
     * configuration (retries, shared rate limit, HTTP client). Pass a base
     * URI only if this token belongs to a different SendSeven environment.
     */
    public static function forToken(#[SensitiveParameter] string $token, ?string $tenantId = null, ?string $baseUri = null): Client
    {
        $factory = self::getFacadeApplication()?->make(ClientFactory::class);

        if (! $factory instanceof ClientFactory) {
            throw new RuntimeException('The SendSeven service provider is not registered.');
        }

        return $factory->make($token, $tenantId, $baseUri);
    }

    /**
     * The client for messages someone is waiting on, such as a login code:
     * a short timeout and no retries (sendseven.fast), so a slow API fails
     * fast and the app can offer another way in. Faked along with the default.
     */
    public static function fast(): Client
    {
        $client = self::getFacadeApplication()?->make(ClientFactory::FAST);

        if (! $client instanceof Client) {
            throw new RuntimeException('The SendSeven service provider is not registered.');
        }

        return $client;
    }

    /**
     * The ID of the contact with this phone number, created if there is none.
     * Remembered for sendseven.contacts.cache_for_seconds. Pass the client
     * and token when sending through SendSeven::forToken().
     */
    public static function contactIdForPhone(string $phone, ?string $name = null, ?Client $client = null, #[SensitiveParameter] ?string $token = null): string
    {
        $contacts = self::getFacadeApplication()?->make(ContactResolver::class);

        if (! $contacts instanceof ContactResolver) {
            throw new RuntimeException('The SendSeven service provider is not registered.');
        }

        return $contacts->idForPhone($phone, $name, $client ?? self::client(), $token);
    }

    /**
     * Create a connect link and return a redirect to it (Inertia-aware).
     * Store $redirect->link->id to match the channel.created webhook later.
     */
    public static function connect(ConnectLink $link, ?string $idempotencyKey = null): ConnectRedirect
    {
        return new ConnectRedirect(self::client()->connectLinks()->create($link, $idempotencyKey));
    }

    /**
     * Resolve each webhook delivery's signing secret, for apps with an
     * endpoint per customer. Return null for an unknown endpoint (404).
     *
     *     SendSeven::resolveWebhookSecretUsing(
     *         fn (Request $request) => Workspace::find($request->route('workspace'))?->sendseven_webhook_secret,
     *     );
     *
     * @param  Closure(Request): ?string  $resolver
     */
    public static function resolveWebhookSecretUsing(Closure $resolver): void
    {
        $secrets = self::getFacadeApplication()?->make(WebhookSecretResolver::class);

        if ($secrets instanceof WebhookSecretResolver) {
            $secrets->using($resolver);
        }
    }

    /**
     * Replace the client, everywhere it's resolved, with one that sends
     * nothing: it answers from $responses and records every request.
     *
     *     $fake = SendSeven::fake(['POST /messages' => ['id' => 'msg_1', ...]]);
     *     // ... run the code under test ...
     *     $fake->assertSent('POST /messages');
     *
     * @param  array<string|int, mixed>  $responses  see Fake::respond()
     */
    public static function fake(array $responses = []): Fake
    {
        $fake = new Fake($responses);

        self::swap($fake->client);
        self::getFacadeApplication()?->instance(ClientFactory::FAST, $fake->client);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }

    private static function client(): Client
    {
        $client = self::getFacadeRoot();

        if (! $client instanceof Client) {
            throw new RuntimeException('The SendSeven service provider is not registered.');
        }

        return $client;
    }
}
