<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGenerationRequest;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PricingCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function create(PixelforgeClient $client, PricingCalculator $calculator): Response
    {
        $rates = $this->currentRates($client);

        $defaultSize = '1024x1024';
        $defaultQuality = 'medium';

        $initialEstimate = $calculator->estimate('', $defaultSize, $defaultQuality, 0, $rates);
        $initialMaxEstimate = $calculator->maxEstimate('', $defaultSize, $defaultQuality, 0, $rates);

        return Inertia::render('Generate', [
            'rates' => $rates,
            'sizes' => config('pixelforge.sizes'),
            'qualities' => config('pixelforge.qualities'),
            'tokens' => config('pixelforge.tokens'),
            'markup' => config('pixelforge.markup'),
            'limits' => config('pixelforge.limits'),
            'initialEstimate' => $initialEstimate,
            'initialMaxEstimate' => $initialMaxEstimate,
        ]);
    }

    public function estimate(
        Request $request,
        PixelforgeClient $client,
        PricingCalculator $calculator,
    ): JsonResponse {
        /** @var array<int, string> $allowedSizes */
        $allowedSizes = (array) config('pixelforge.sizes', ['1024x1024', '1536x1024', '1024x1536']);
        /** @var array<int, string> $allowedQualities */
        $allowedQualities = (array) config('pixelforge.qualities', ['low', 'medium', 'high']);

        $prompt = is_string($request->input('prompt')) ? $request->input('prompt') : '';
        $size = is_string($request->input('size')) && in_array($request->input('size'), $allowedSizes, true)
            ? $request->input('size')
            : '1024x1024';
        $quality = is_string($request->input('quality')) && in_array($request->input('quality'), $allowedQualities, true)
            ? $request->input('quality')
            : 'medium';

        $referenceImages = is_array($request->input('reference_images'))
            ? array_values(array_filter(
                $request->input('reference_images'),
                static fn (mixed $url): bool => is_string($url) && trim($url) !== ''
            ))
            : [];

        $referenceCount = min(
            count($referenceImages),
            (int) config('pixelforge.limits.max_reference_images', 4)
        );

        $rates = $this->currentRates($client);

        return response()->json([
            'estimate' => $calculator->estimate($prompt, $size, $quality, $referenceCount, $rates),
            'max_estimate' => $calculator->maxEstimate($prompt, $size, $quality, $referenceCount, $rates),
        ]);
    }

    public function index(Request $request): Response
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $generations = $user->generations()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        $hasUnfinished = $user->generations()
            ->whereIn('status', [Generation::STATUS_QUEUED, Generation::STATUS_PROCESSING])
            ->exists();

        return Inertia::render('Generations/Index', [
            'generations' => $generations,
            'hasUnfinished' => $hasUnfinished,
        ]);
    }

    public function store(
        StoreGenerationRequest $request,
        PricingCalculator $calculator,
    ): RedirectResponse {
        /** @var array{prompt: string, size: string, quality: string, reference_images?: array<int, string>|null} $validated */
        $validated = $request->validated();

        /** @var \App\Models\User $user */
        $user = $request->user();

        $referenceImages = array_values($validated['reference_images'] ?? []);

        /** @var array<string, mixed>|null $cachedPricing */
        $cachedPricing = Cache::get(PixelforgeClient::PRICING_CACHE_KEY);
        /** @var array<string, string>|null $rates */
        $rates = is_array($cachedPricing['rates'] ?? null) ? $cachedPricing['rates'] : null;

        $estimate = $calculator->estimate(
            $validated['prompt'],
            $validated['size'],
            $validated['quality'],
            count($referenceImages),
            $rates,
        );

        $generation = Generation::query()->create([
            'user_id' => $user->id,
            'prompt' => $validated['prompt'],
            'size' => $validated['size'],
            'quality' => $validated['quality'],
            'reference_urls' => $referenceImages,
            'status' => Generation::STATUS_QUEUED,
            'estimated_cost' => $estimate['user_price'],
        ]);

        SubmitGenerationJob::dispatch($generation);

        return redirect()->route('generations.index');
    }

    /**
     * @return array<string, string>
     */
    protected function currentRates(PixelforgeClient $client): array
    {
        /** @var array<string, mixed>|null $cachedPricing */
        $cachedPricing = Cache::get(PixelforgeClient::PRICING_CACHE_KEY);

        if (is_array($cachedPricing['rates'] ?? null)) {
            /** @var array<string, string> $cachedRates */
            $cachedRates = $cachedPricing['rates'];

            return $cachedRates;
        }

        try {
            $pricing = $client->getPricing();
            if (is_array($pricing['rates'] ?? null)) {
                /** @var array<string, string> $liveRates */
                $liveRates = $pricing['rates'];

                return $liveRates;
            }
        } catch (\Throwable) {
            // Fall back to configured default rates if the pricing endpoint is unreachable
        }

        /** @var array<string, string> $fallback */
        $fallback = (array) config('pixelforge.rates');

        return $fallback;
    }
}
