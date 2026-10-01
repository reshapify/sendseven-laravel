<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Webhooks;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Reshapify\SendSeven\Laravel\Exceptions\MissingConfiguration;

/**
 * Finds the signing secret for a webhook delivery: the configured one, or,
 * for apps with an endpoint per customer, whatever the app's resolver says.
 */
final class WebhookSecretResolver
{
    /** @var (Closure(Request): ?string)|null */
    private ?Closure $resolver = null;

    public function __construct(private readonly Config $config) {}

    /**
     * @param  Closure(Request): ?string  $resolver  null when the endpoint is unknown (answered with 404)
     */
    public function using(Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    public function hasCustomResolver(): bool
    {
        return $this->resolver instanceof Closure;
    }

    /**
     * The secret, or null when the resolver doesn't recognise the endpoint.
     *
     * @throws MissingConfiguration when there's no resolver and no configured secret
     */
    public function resolve(Request $request): ?string
    {
        if ($this->resolver instanceof Closure) {
            $secret = ($this->resolver)($request);

            return is_string($secret) && $secret !== '' ? $secret : null;
        }

        $secret = $this->config->get('sendseven.webhooks.secret');

        if (! is_string($secret) || $secret === '') {
            throw MissingConfiguration::webhookSecret();
        }

        return $secret;
    }
}
