<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PricingCalculator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PollUnfinishedGenerationsJob implements ShouldQueue
{
    use Queueable;

    public const STALE_MINUTES = 2;

    public function handle(PixelforgeClient $client, PricingCalculator $calculator): void
    {
        $cutoff = now()->subMinutes(self::STALE_MINUTES);

        $staleGenerations = Generation::query()
            ->whereIn('status', [Generation::STATUS_QUEUED, Generation::STATUS_PROCESSING])
            ->whereNotNull('provider_id')
            ->where('updated_at', '<=', $cutoff)
            ->get();

        foreach ($staleGenerations as $generation) {
            if ($generation->provider_id === null || $generation->provider_id === '') {
                continue;
            }

            try {
                $remoteGeneration = $client->getGeneration($generation->provider_id);

                DB::transaction(function () use ($generation, $remoteGeneration, $calculator): void {
                    $locked = Generation::query()->lockForUpdate()->find($generation->id);

                    if ($locked === null) {
                        return;
                    }

                    ProcessWebhookEvent::applyGenerationUpdate($locked, $remoteGeneration, $calculator);
                });
            } catch (\Throwable $e) {
                Log::warning('Failed to poll unfinished Pixelforge generation.', [
                    'generation_id' => $generation->id,
                    'provider_id' => $generation->provider_id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
