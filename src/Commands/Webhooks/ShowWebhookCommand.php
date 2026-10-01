<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Commands\Webhooks;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Reshapify\SendSeven\Client;

final class ShowWebhookCommand extends Command
{
    use InteractsWithWebhookEndpoints;

    protected $signature = 'sendseven:webhooks:show {id? : The endpoint ID; defaults to the one behind the sendseven.webhooks route}';

    protected $description = 'Show a SendSeven webhook endpoint in detail';

    public function handle(Client $sendseven, Router $router): int
    {
        return $this->attempt(function () use ($sendseven, $router): int {
            $endpoint = $this->endpoint($sendseven, $router, $this->argument('id'));

            if (! $endpoint instanceof \Reshapify\SendSeven\Data\WebhookEndpoint) {
                return self::FAILURE;
            }

            $details = [
                'ID' => $endpoint->id,
                'Name' => $endpoint->name,
                'URL' => $endpoint->url,
                'State' => $this->state($endpoint),
                'Events' => implode(', ', $endpoint->subscribedEvents),
                'Authorization header' => $endpoint->hasAuthorizationHeader ? 'set' : 'none',
                'Last success' => $endpoint->lastSuccessAt ?? 'never',
                'Last failure' => $endpoint->lastFailureAt ?? 'never',
                'Last error' => $endpoint->lastError ?? '',
                'Next automatic reactivation' => $endpoint->nextReactivationAt ?? '',
                'Queued events' => (string) ($endpoint->queuedEventsCount ?? 0),
            ];

            foreach (array_filter($details, fn (string $value): bool => $value !== '') as $label => $value) {
                $this->components->twoColumnDetail($label, $value);
            }

            return self::SUCCESS;
        });
    }
}
