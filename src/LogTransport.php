<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel;

use Psr\Log\LoggerInterface;
use Reshapify\SendSeven\Http\Method;
use Reshapify\SendSeven\Http\Request;
use Reshapify\SendSeven\Http\Response;
use Reshapify\SendSeven\Http\Transport;

/**
 * The "log" driver: writes each request to the log instead of sending it,
 * and answers like SendSeven would, with the request's fields, an ID and a
 * pending status for writes, and an empty page for reads.
 */
final readonly class LogTransport implements Transport
{
    public function __construct(private LoggerInterface $logger) {}

    public function send(Request $request): Response
    {
        $this->logger->info("SendSeven (log driver): {$request->method->value} {$request->path}", [
            'query' => $request->query,
            'body' => $request->body,
        ]);

        if ($request->method === Method::Get) {
            return Response::json(['items' => [], 'pagination' => ['total' => 0, 'page' => 1, 'page_size' => 0, 'total_pages' => 0, 'has_next' => false, 'has_prev' => false]]);
        }

        return Response::json([
            ...$request->body ?? [],
            'id' => 'log_'.bin2hex(random_bytes(8)),
            'status' => 'pending',
        ]);
    }
}
