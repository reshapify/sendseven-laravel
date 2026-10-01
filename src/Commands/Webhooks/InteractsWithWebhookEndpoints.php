<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Commands\Webhooks;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Reshapify\SendSeven\Client;
use Reshapify\SendSeven\Data\WebhookEndpoint;
use Reshapify\SendSeven\Exceptions\SendSevenException;

/**
 * What the webhook commands share: finding the endpoint to act on, describing
 * its state, and turning SendSeven's refusals into a failed exit code.
 *
 * @mixin Command
 */
trait InteractsWithWebhookEndpoints
{
    /**
     * The endpoint with this ID; otherwise the one behind
     * Route::sendSevenWebhooks(), or the only one there is.
     */
    private function endpoint(Client $sendseven, Router $router, mixed $id): ?WebhookEndpoint
    {
        if (is_string($id) && $id !== '') {
            return $sendseven->webhooks()->getEndpoint($id);
        }

        $endpoints = $sendseven->webhooks()->listEndpoints();
        $router->getRoutes()->refreshNameLookups();
        $route = $router->getRoutes()->getByName('sendseven.webhooks');

        if ($route !== null && $route->parameterNames() === []) {
            foreach ($endpoints as $endpoint) {
                if (rtrim($endpoint->url, '/') === rtrim(url($route->uri()), '/')) {
                    return $endpoint;
                }
            }
        }

        if (count($endpoints) === 1) {
            return $endpoints[0];
        }

        $this->components->error($endpoints === []
            ? 'There are no webhook endpoints. Create one with php artisan sendseven:webhooks:register.'
            : 'There are several webhook endpoints; pass the ID of one. php artisan sendseven:webhooks:list shows them.');

        return null;
    }

    private function state(WebhookEndpoint $endpoint): string
    {
        return match (true) {
            $endpoint->suspendedAt !== null => '<fg=red>suspended</> after '.($endpoint->consecutiveFailures ?? 'repeated').' failures',
            ! $endpoint->isVerified => '<fg=yellow>unverified</>',
            ! $endpoint->isActive => '<fg=yellow>inactive</>',
            default => '<fg=green>active</>',
        };
    }

    /**
     * An argument or option as text: '' when it isn't a string. Larastan types
     * command input differently across versions; this reads the same under all.
     */
    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * Runs the command body, reporting a SendSeven refusal as a failure.
     *
     * @param  Closure(): int  $body
     */
    private function attempt(Closure $body): int
    {
        try {
            return $body();
        } catch (SendSevenException $sendSevenException) {
            $this->components->error($sendSevenException->getMessage());

            return Command::FAILURE;
        }
    }
}
