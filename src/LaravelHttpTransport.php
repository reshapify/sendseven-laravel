<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Reshapify\SendSeven\Exceptions\TransportFailed;
use Reshapify\SendSeven\Http\FilePart;
use Reshapify\SendSeven\Http\Request;
use Reshapify\SendSeven\Http\Response;
use Reshapify\SendSeven\Http\Transport;

/**
 * Sends the SDK's requests through Laravel's HTTP client, so Http::fake(),
 * Http::preventStrayRequests(), Http::assertSent() and tools like Telescope,
 * Pulse and Nightwatch see SendSeven traffic like any other.
 *
 * The facade is resolved per request, so fakes set up after the client was
 * built still apply.
 */
final readonly class LaravelHttpTransport implements Transport
{
    public function __construct(private string $baseUri, private int $timeoutSeconds = 30) {}

    public function send(Request $request): Response
    {
        $query = $request->queryString();
        $url = rtrim($this->baseUri, '/').'/'.ltrim($request->path, '/').($query === '' ? '' : '?'.$query);
        $pending = Http::withHeaders($request->headers)->timeout($this->timeoutSeconds);

        try {
            if ($request->multipart !== null) {
                $response = $pending->asMultipart()->send($request->method->value, $url, ['multipart' => $this->parts($request->multipart)]);
            } elseif ($request->body !== null) {
                $response = $pending->asJson()->send($request->method->value, $url, ['json' => $request->body]);
            } else {
                $response = $pending->send($request->method->value, $url);
            }
        } catch (ConnectionException $connectionException) {
            throw TransportFailed::for($request, $connectionException);
        }

        $headers = [];

        foreach (array_keys($response->headers()) as $name) {
            $headers[strtolower((string) $name)] = $response->header((string) $name);
        }

        return new Response($response->status(), $response->body(), $headers);
    }

    /**
     * @param  array<string, scalar|FilePart|list<scalar|FilePart>|null>  $fields
     * @return list<array{name: string, contents: string, filename?: string, headers?: array<string, string>}>
     */
    private function parts(array $fields): array
    {
        $parts = [];

        foreach ($fields as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if ($item === null) {
                    continue;
                }

                $parts[] = $item instanceof FilePart
                    ? ['name' => $name, 'contents' => $item->contents, 'filename' => $item->filename, 'headers' => ['Content-Type' => $item->contentType]]
                    : ['name' => $name, 'contents' => is_bool($item) ? ($item ? 'true' : 'false') : (string) $item];
            }
        }

        return $parts;
    }
}
