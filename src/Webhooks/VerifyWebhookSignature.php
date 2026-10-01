<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Webhooks;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Reshapify\SendSeven\Webhooks\Events\Event;
use Reshapify\SendSeven\Webhooks\InvalidSignature;
use Reshapify\SendSeven\Webhooks\Webhook;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards a SendSeven webhook route:
 *
 * 1. answers the unsigned verification challenge, which activates the endpoint;
 * 2. rejects deliveries with a bad or stale signature (403), or for an
 *    endpoint the secret resolver doesn't recognise (404);
 * 3. hands the verified, typed event to the route as $request->attributes 'sendseven.event'.
 *
 * Use it on your own controller, or let Route::sendSevenWebhooks() do it all.
 */
final readonly class VerifyWebhookSignature
{
    public const string EVENT_ATTRIBUTE = 'sendseven.event';

    public function __construct(private WebhookSecretResolver $secrets, private Config $config) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $body = $request->getContent();
        $headers = $this->headers($request);

        if (Webhook::isVerificationChallenge($body, $headers)) {
            return new JsonResponse(Webhook::challengeResponse($body));
        }

        $secret = $this->secrets->resolve($request);

        if ($secret === null) {
            abort(404);
        }

        try {
            $event = Webhook::constructEvent($body, $headers, $secret, $this->tolerance(), $this->authorization());
        } catch (InvalidSignature $invalidSignature) {
            abort(403, $invalidSignature->getMessage());
        }

        $request->attributes->set(self::EVENT_ATTRIBUTE, $event);

        return $next($request);
    }

    public static function event(Request $request): Event
    {
        $event = $request->attributes->get(self::EVENT_ATTRIBUTE);

        if (! $event instanceof Event) {
            throw new LogicException('No verified SendSeven event on this request. Add the '.self::class.' middleware to the route.');
        }

        return $event;
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $headers = [];

        foreach (array_keys($request->headers->all()) as $name) {
            $headers[$name] = (string) $request->headers->get($name);
        }

        return $headers;
    }

    private function tolerance(): int
    {
        $tolerance = $this->config->get('sendseven.webhooks.tolerance_seconds', 300);

        return is_numeric($tolerance) ? (int) $tolerance : 300;
    }

    private function authorization(): ?string
    {
        $authorization = $this->config->get('sendseven.webhooks.authorization');

        return is_string($authorization) && $authorization !== '' ? $authorization : null;
    }
}
