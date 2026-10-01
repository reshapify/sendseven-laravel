<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Exceptions;

use LogicException;
use Reshapify\SendSeven\Exceptions\SendSevenException;

final class MissingConfiguration extends LogicException implements SendSevenException
{
    public static function token(): self
    {
        return new self('No SendSeven API token is configured. Set SENDSEVEN_API_TOKEN in .env (SendSeven → Settings → API Tokens), or pass one: SendSeven::forToken($token).');
    }

    public static function webhookSecret(): self
    {
        return new self('No SendSeven webhook secret is configured. Set SENDSEVEN_WEBHOOK_SECRET in .env (php artisan sendseven:webhooks:register prints it), or resolve one per endpoint with SendSeven::resolveWebhookSecretUsing().');
    }
}
