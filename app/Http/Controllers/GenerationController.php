<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGenerationRequest;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PricingCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function create(PixelforgeClient $client): Response
    {
        try {
            $pricing = $client->getPricing();
            $rates = is_array($pricing['rates'] ?? null) ? $pricing['rates'] : config('pixelforge.rates');
        } catch (\Throwable) {
            $rates = config('pixelforge.rates');
        }

        return Inertia::render('Generate', [
            'rates' => $rates,
            'sizes' => config('pixelforge.sizes'),
            'qualities' => config('pixelforge.qualities'),
            'tokens' => config('pixelforge.tokens'),
            'markup' => config('pixelforge.markup'),
        ]);
    }

    public function index(Request $request): Response
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $generations = $user->generations()
            ->latest()
            ->get();

        return Inertia::render('Generations/Index', [
            'generations' => $generations,
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
}
