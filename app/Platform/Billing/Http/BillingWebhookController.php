<?php

declare(strict_types=1);

namespace App\Platform\Billing\Http;

use App\Platform\Billing\BillingWebhookHandler;
use App\Platform\Billing\ProcessorRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * POST /api/control-plane/billing/webhook?processor=stripe|chargebee|paystack  (admin host only, no CSRF, no session).
 * The signature is verified against the RAW body before the payload is even parsed, and before anything touches a tenant.
 */
final class BillingWebhookController
{
    public function __construct(private readonly ProcessorRegistry $processors, private readonly BillingWebhookHandler $handler)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $key = (string) $request->query('processor', '');
        $processor = in_array($key, ProcessorRegistry::KEYS, true) ? $this->processors->get($key) : null;
        $body = $request->getContent();

        // Unknown or unconfigured processors and bad signatures are indistinguishable on purpose.
        if ($processor === null || $body === '' || strlen($body) > 1_000_000 || ! $processor->verify($body, $request->headers->all())) {
            return response()->json('Invalid webhook signature', 400);
        }

        try {
            $event = $processor->parse($body);
        } catch (InvalidArgumentException) {
            return response()->json('Invalid webhook payload', 400);
        }
        if ($event === null) {
            return response()->json(['ignored' => true]);   // authentic, but not an event type we act on
        }

        $result = $this->handler->handle($event);

        return $result === null
            ? response()->json('No matching tenant found for this billing event', 404)
            : response()->json($result);
    }
}
