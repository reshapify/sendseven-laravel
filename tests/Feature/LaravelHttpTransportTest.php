<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Reshapify\SendSeven\Client;
use Reshapify\SendSeven\Exceptions\TransportFailed;
use Reshapify\SendSeven\Http\FilePart;
use Reshapify\SendSeven\Http\Request;
use Reshapify\SendSeven\Laravel\Facades\SendSeven;
use Reshapify\SendSeven\Laravel\LaravelHttpTransport;

it("sends SDK requests through Laravel's HTTP client, so Http::fake sees them", function (): void {
    config()->set('sendseven.retries.max_attempts', 1);
    Http::fake(['api.sendseven.com/api/v1/tenants/me/retention*' => Http::response(['retention_days' => 30, 'is_default' => true], 200, ['X-Request-ID' => 'req_1'])]);

    $response = app(Client::class)->request(Reshapify\SendSeven\Http\Method::Get, '/tenants/me/retention', query: ['expand' => 'all']);

    expect($response->status)->toBe(200)
        ->and($response->header('x-request-id'))->toBe('req_1');

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.sendseven.com/api/v1/tenants/me/retention?expand=all'
        && $request->hasHeader('Authorization', 'Bearer s7_api_test'));
});

it('sends JSON bodies with an idempotency key', function (): void {
    Http::fake(['*/messages' => Http::response(['id' => 'msg_1', 'direction' => 'outbound', 'message_type' => 'text', 'status' => 'queued', 'created_at' => '2026-10-01T09:00:00Z'], 201)]);

    SendSeven::messages()->send(to: '+4915112345678', channelId: 'ch_1', text: 'Hi');

    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
        && $request['to'] === '+4915112345678'
        && $request->hasHeader('Idempotency-Key'));
});

it('uploads files as multipart', function (): void {
    Http::fake(['*' => Http::response(['ok' => true])]);

    (new LaravelHttpTransport('https://s7.test/api/v1'))->send(new Request(
        Reshapify\SendSeven\Http\Method::Post,
        '/attachments/upload',
        multipart: ['file' => FilePart::fromContents('%PDF', 'receipt.pdf', 'application/pdf'), 'public' => true],
    ));

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://s7.test/api/v1/attachments/upload'
        && $request->isMultipart()
        && $request->hasFile('file', '%PDF', 'receipt.pdf'));
});

it('uses a per-token base URI', function (): void {
    Http::fake(['*' => Http::response([])]);

    SendSeven::forToken('s7_api_customer', baseUri: 'https://s7.test/api/v1')->request(Reshapify\SendSeven\Http\Method::Get, '/channels');

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://s7.test/api/v1/channels'
        && $request->hasHeader('Authorization', 'Bearer s7_api_customer'));
});

it('lets Http::preventStrayRequests catch unfaked SendSeven calls', function (): void {
    Http::preventStrayRequests();

    app(Client::class)->request(Reshapify\SendSeven\Http\Method::Get, '/channels');
})->throws(RuntimeException::class);

it('reports connection failures as TransportFailed', function (): void {
    config()->set('sendseven.retries.max_attempts', 1);
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    app(Client::class)->request(Reshapify\SendSeven\Http\Method::Get, '/channels');
})->throws(TransportFailed::class);
