<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Commands\Webhooks;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Reshapify\SendSeven\Client;

/**
 * SendSeven suspends an endpoint after repeated failed deliveries, e.g. during
 * an outage. Once the app is answering again, this turns it back on.
 */
final class ActivateWebhookCommand extends Command
{
    use InteractsWithWebhookEndpoints;

    protected $signature = 'sendseven:webhooks:activate {id? : The endpoint ID; defaults to the one behind the sendseven.webhooks route}';

    protected $description = 'Re-activate a suspended or inactive SendSeven webhook endpoint';

    public function handle(Client $sendseven, Router $router): int
    {
        return $this->attempt(function () use ($sendseven, $router): int {
            $endpoint = $this->endpoint($sendseven, $router, $this->argument('id'));

            if (! $endpoint instanceof \Reshapify\SendSeven\Data\WebhookEndpoint) {
                return self::FAILURE;
            }

            if (! $endpoint->isVerified) {
                $verification = $sendseven->webhooks()->verifyEndpoint($endpoint->id);

                if (! $verification->isVerified) {
                    $this->components->error("SendSeven couldn't verify {$endpoint->url}: {$verification->message} Make sure the URL is public and answers the challenge, then try again.");

                    return self::FAILURE;
                }
            }

            $endpoint = $sendseven->webhooks()->updateEndpoint(webhookId: $endpoint->id, isActive: true);

            $this->components->info("{$endpoint->url} is ".strip_tags($this->state($endpoint)).'.');

            return self::SUCCESS;
        });
    }
}
