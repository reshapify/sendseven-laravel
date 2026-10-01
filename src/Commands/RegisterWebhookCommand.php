<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Reshapify\SendSeven\Client;
use Reshapify\SendSeven\Exceptions\SendSevenException;
use Reshapify\SendSeven\Webhooks\EventType;

/**
 * Creates the webhook endpoint in SendSeven and prints its signing secret.
 * SendSeven verifies the URL straight away with a challenge, which the
 * webhook route answers, so the app must be reachable at that URL.
 */
final class RegisterWebhookCommand extends Command
{
    protected $signature = 'sendseven:webhooks:register
        {url? : The public URL; defaults to the sendseven.webhooks route}
        {--name= : A label shown in SendSeven}
        {--event=* : Events to subscribe to; defaults to messages and channels}
        {--authorization= : A static Authorization header SendSeven should send as a second check}';

    protected $description = 'Register the SendSeven webhook endpoint and print its signing secret';

    public function handle(Client $sendseven, Router $router): int
    {
        $url = $this->url($router);

        if ($url === null) {
            $this->components->error('Give the URL, or add Route::sendSevenWebhooks() to your routes (without parameters) first.');

            return self::FAILURE;
        }

        if (! str_starts_with($url, 'https://')) {
            $this->components->warn("{$url} isn't HTTPS. SendSeven needs a public HTTPS URL to deliver to; locally, use a tunnel (e.g. Herd share, Expose or ngrok) and pass its URL.");
        }

        try {
            foreach ($sendseven->webhooks()->listEndpoints() as $endpoint) {
                if (rtrim($endpoint->url, '/') === rtrim($url, '/')) {
                    $this->components->info("An endpoint for {$url} already exists ({$endpoint->id}, ".($endpoint->isVerified ? 'verified' : 'not verified').'). Its secret was shown when it was created; to get a new one, regenerate it in SendSeven.');

                    return self::SUCCESS;
                }
            }

            $authorization = $this->option('authorization');
            $created = $sendseven->webhooks()->createEndpoint(
                name: $this->labelFor($url),
                url: $url,
                subscribedEvents: $this->events(),
                authorizationHeader: is_string($authorization) && $authorization !== '' ? $authorization : null,
            );
            $verification = $sendseven->webhooks()->verifyEndpoint($created->webhookId);
        } catch (SendSevenException $sendSevenException) {
            $this->components->error($sendSevenException->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Registered {$url} ({$created->webhookId}).");
        $this->line('Add this to .env. It is shown only once:');
        $this->newLine();
        $this->line("SENDSEVEN_WEBHOOK_SECRET={$created->secretKey}");
        $this->newLine();

        if ($verification->isVerified) {
            $this->components->info('SendSeven verified the endpoint.');
        } else {
            $this->components->warn("Not verified yet: {$verification->message} Check the URL is public and the route answers, then run php artisan sendseven:doctor.");
        }

        return self::SUCCESS;
    }

    private function url(Router $router): ?string
    {
        $url = $this->argument('url');

        if (is_string($url) && $url !== '') {
            return $url;
        }

        $router->getRoutes()->refreshNameLookups();
        $route = $router->getRoutes()->getByName('sendseven.webhooks');

        return $route === null || $route->parameterNames() !== [] ? null : url($route->uri());
    }

    /**
     * @return list<string>
     */
    private function events(): array
    {
        $events = array_values(array_filter(Arr::wrap($this->option('event')), is_string(...)));

        if ($events !== []) {
            return $events;
        }

        return array_map(
            static fn (EventType $type): string => $type->value,
            [...EventType::messaging(), EventType::MessageReaction, EventType::ChannelCreated, EventType::ChannelUpdated, EventType::ChannelDeleted],
        );
    }

    private function labelFor(string $url): string
    {
        $name = $this->option('name');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : $url;
    }
}
