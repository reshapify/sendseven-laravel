<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Facades;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Reshapify\SendSeven\Client;
use Reshapify\SendSeven\Laravel\ClientFactory;
use Reshapify\SendSeven\Laravel\ConnectRedirect;
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
     * configuration (retries, shared rate limit, base URI).
     */
    public static function forToken(#[SensitiveParameter] string $token, ?string $tenantId = null): Client
    {
        $factory = self::getFacadeApplication()?->make(ClientFactory::class);

        if (! $factory instanceof ClientFactory) {
            throw new RuntimeException('The SendSeven service provider is not registered.');
        }

        return $factory->make($token, $tenantId);
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
