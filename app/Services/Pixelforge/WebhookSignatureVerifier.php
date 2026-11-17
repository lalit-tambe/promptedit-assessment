<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Request;

class WebhookSignatureVerifier
{
    public const MAX_TIMESTAMP_DRIFT_SECONDS = 300;

    public function verifyRequest(Request $request, ?int $currentTime = null): bool
    {
        return $this->verify(
            $request->getContent(),
            $request->header('Pixelforge-Signature'),
            $currentTime,
        );
    }

    public function verify(string $rawBody, ?string $signatureHeader, ?int $currentTime = null): bool
    {
        if ($signatureHeader === null || trim($signatureHeader) === '') {
            return false;
        }

        $secret = (string) config('pixelforge.webhook_secret', '');

        if ($secret === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $segment) {
            [$key, $value] = array_pad(explode('=', trim($segment), 2), 2, '');
            if ($key !== '') {
                $parts[$key] = $value;
            }
        }

        $timestampRaw = $parts['t'] ?? '';
        $providedSignature = $parts['v1'] ?? '';

        if ($timestampRaw === '' || ! ctype_digit($timestampRaw) || $providedSignature === '') {
            return false;
        }

        $timestamp = (int) $timestampRaw;
        $now = $currentTime ?? time();

        if (abs($now - $timestamp) > self::MAX_TIMESTAMP_DRIFT_SECONDS) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);

        return hash_equals($expectedSignature, $providedSignature);
    }
}
