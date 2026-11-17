<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\WebhookSignatureVerifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        WebhookSignatureVerifier $verifier,
    ): Response|JsonResponse {
        $rawBody = $request->getContent();

        if (! $verifier->verify($rawBody, $request->header('Pixelforge-Signature'))) {
            return response()->json([
                'message' => 'Invalid Pixelforge signature.',
            ], 400);
        }

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return response()->json([
                'message' => 'Invalid JSON payload.',
            ], 400);
        }

        $eventId = is_string($payload['id'] ?? null)
            ? $payload['id']
            : (string) $request->header('Pixelforge-Event-Id', '');

        $eventType = is_string($payload['type'] ?? null)
            ? $payload['type']
            : (string) $request->header('Pixelforge-Event-Type', '');

        if ($eventId === '' || $eventType === '') {
            return response()->json([
                'message' => 'Missing webhook event id or type.',
            ], 400);
        }

        if (WebhookEvent::query()->where('event_id', $eventId)->exists()) {
            return response()->noContent();
        }

        $providerId = is_string($payload['data']['object']['id'] ?? null)
            ? $payload['data']['object']['id']
            : null;

        try {
            $webhookEvent = WebhookEvent::query()->create([
                'event_id' => $eventId,
                'type' => $eventType,
                'generation_provider_id' => $providerId,
                'payload' => $payload,
                'received_at' => now(),
                'processed_at' => $eventType === 'ping' ? now() : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->noContent();
        }

        if ($eventType === 'ping') {
            return response()->noContent();
        }

        ProcessWebhookEvent::dispatch($webhookEvent);

        return response()->noContent();
    }
}
