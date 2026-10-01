<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Reshapify\SendSeven\Client;
use Reshapify\SendSeven\Laravel\Commands\DoctorCommand;
use Reshapify\SendSeven\Laravel\Commands\RegisterWebhookCommand;
use Reshapify\SendSeven\Laravel\Commands\SmsPricesCommand;
use Reshapify\SendSeven\Laravel\Commands\Webhooks\ActivateWebhookCommand;
use Reshapify\SendSeven\Laravel\Commands\Webhooks\DeleteWebhookCommand;
use Reshapify\SendSeven\Laravel\Commands\Webhooks\ListWebhooksCommand;
use Reshapify\SendSeven\Laravel\Commands\Webhooks\RetryWebhookDeliveryCommand;
use Reshapify\SendSeven\Laravel\Commands\Webhooks\RotateWebhookSecretCommand;
use Reshapify\SendSeven\Laravel\Commands\Webhooks\ShowWebhookCommand;
use Reshapify\SendSeven\Laravel\Commands\Webhooks\TestWebhookCommand;
use Reshapify\SendSeven\Laravel\Commands\Webhooks\UpdateWebhookCommand;
use Reshapify\SendSeven\Laravel\Commands\Webhooks\WebhookDeliveriesCommand;
use Reshapify\SendSeven\Laravel\Webhooks\VerifyWebhookSignature;
use Reshapify\SendSeven\Laravel\Webhooks\WebhookController;
use Reshapify\SendSeven\Laravel\Webhooks\WebhookSecretResolver;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class SendSevenServiceProvider extends PackageServiceProvider
{
    /**
     * CSRF middleware across Laravel versions; webhooks are signed instead.
     */
    private const array CSRF_MIDDLEWARE = [
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    ];

    public function configurePackage(Package $package): void
    {
        $package
            ->name('sendseven')
            ->hasConfigFile()
            ->hasCommands(
                DoctorCommand::class,
                SmsPricesCommand::class,
                RegisterWebhookCommand::class,
                ListWebhooksCommand::class,
                ShowWebhookCommand::class,
                WebhookDeliveriesCommand::class,
                RetryWebhookDeliveryCommand::class,
                TestWebhookCommand::class,
                ActivateWebhookCommand::class,
                UpdateWebhookCommand::class,
                RotateWebhookSecretCommand::class,
                DeleteWebhookCommand::class,
            );
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(ClientFactory::class);
        $this->app->singleton(WebhookSecretResolver::class);
        $this->app->scoped(Client::class, static fn (Application $app): Client => $app->make(ClientFactory::class)->make());
    }

    public function packageBooted(): void
    {
        /*
         * Route::sendSevenWebhooks('webhooks/sendseven') registers a POST route
         * that answers the challenge, verifies signatures and dispatches events.
         * Add {parameters} for an endpoint per customer, with
         * SendSeven::resolveWebhookSecretUsing().
         */
        $csrf = self::CSRF_MIDDLEWARE;

        Router::macro('sendSevenWebhooks', fn (string $uri = 'webhooks/sendseven'): Route => Router::post($uri, WebhookController::class)
            ->middleware(VerifyWebhookSignature::class)
            ->withoutMiddleware($csrf)
            ->name('sendseven.webhooks'));
    }
}
