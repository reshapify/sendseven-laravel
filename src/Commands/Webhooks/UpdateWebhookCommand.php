<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Commands\Webhooks;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Reshapify\SendSeven\Client;

final class UpdateWebhookCommand extends Command
{
    use InteractsWithWebhookEndpoints;

    protected $signature = 'sendseven:webhooks:update
        {id? : The endpoint ID; defaults to the one behind the sendseven.webhooks route}
        {--url= : A new URL; SendSeven re-verifies it with a challenge}
        {--event=* : Replace the subscribed events}
        {--name= : A new label}';

    protected $description = 'Change a SendSeven webhook endpoint';

    public function handle(Client $sendseven, Router $router): int
    {
        $url = $this->filled('url');
        $name = $this->filled('name');
        $events = array_values(array_filter(Arr::wrap($this->option('event')), is_string(...)));

        if ($url === null && $name === null && $events === []) {
            $this->components->error('Nothing to change: pass --url, --event or --name.');

            return self::FAILURE;
        }

        return $this->attempt(function () use ($sendseven, $router, $url, $name, $events): int {
            $endpoint = $this->endpoint($sendseven, $router, $this->argument('id'));

            if (! $endpoint instanceof \Reshapify\SendSeven\Data\WebhookEndpoint) {
                return self::FAILURE;
            }

            $endpoint = $sendseven->webhooks()->updateEndpoint(
                webhookId: $endpoint->id,
                name: $name,
                url: $url,
                subscribedEvents: $events === [] ? null : $events,
            );

            $this->components->info("Updated {$endpoint->id}: {$endpoint->url}, ".strip_tags($this->state($endpoint)).'.');

            return self::SUCCESS;
        });
    }

    private function filled(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
