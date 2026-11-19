<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientBalanceException;
use App\Http\Requests\StoreGenerationRequest;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Services\CreditBalanceService;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PricingCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function dashboard(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $allSucceededCharges = $user->generations()
            ->where('status', Generation::STATUS_SUCCEEDED)
            ->pluck('charged_cost');

        $totalCharged = '0.000000000000';
        foreach ($allSucceededCharges as $charge) {
            if ($charge !== null && is_numeric((string) $charge)) {
                $totalCharged = bcadd($totalCharged, (string) $charge, 12);
            }
        }

        $stats = [
            'total' => $user->generations()->count(),
            'succeeded' => $user->generations()->where('status', Generation::STATUS_SUCCEEDED)->count(),
            'in_progress' => $user->generations()->whereIn('status', [Generation::STATUS_QUEUED, Generation::STATUS_PROCESSING])->count(),
            'failed' => $user->generations()->where('status', Generation::STATUS_FAILED)->count(),
            'total_charged' => $totalCharged,
        ];

        $recentGenerations = $user->generations()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(6)
            ->get();

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'recentGenerations' => $recentGenerations,
            'hasUnfinished' => $stats['in_progress'] > 0,
        ]);
    }

    public function create(PixelforgeClient $client, PricingCalculator $calculator): Response
    {
        $rates = $client->getCurrentRates();

        return Inertia::render('Generate', [
            'rates' => $rates,
            'sizes' => config('pixelforge.sizes'),
            'qualities' => config('pixelforge.qualities'),
            'tokens' => config('pixelforge.tokens'),
            'markup' => config('pixelforge.markup'),
            'limits' => config('pixelforge.limits'),
            'creditPurchaseAmount' => (string) config('pixelforge.credit_purchase_amount', '5.000000000000'),
            'initialEstimate' => $calculator->estimate('', '1024x1024', 'medium', 0, $rates),
            'initialMaxEstimate' => $calculator->maxEstimate('', '1024x1024', 'medium', 0, $rates),
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

        $rates = $client->getCurrentRates();

        return response()->json([
            'estimate' => $calculator->estimate($prompt, $size, $quality, $referenceCount, $rates),
            'max_estimate' => $calculator->maxEstimate($prompt, $size, $quality, $referenceCount, $rates),
        ]);
    }

    public function index(Request $request): Response
    {
        /** @var User $user */
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
        PixelforgeClient $client,
        PricingCalculator $calculator,
        CreditBalanceService $balanceService,
    ): RedirectResponse {
        /** @var array{prompt: string, size: string, quality: string, reference_images?: array<int, string>|null} $validated */
        $validated = $request->validated();

        /** @var User $user */
        $user = $request->user();

        $referenceImages = array_values($validated['reference_images'] ?? []);
        $rates = $client->getCurrentRates(fetchIfUncached: false);

        $estimate = $calculator->estimate(
            $validated['prompt'],
            $validated['size'],
            $validated['quality'],
            count($referenceImages),
            $rates,
        );

        try {
            $generation = $balanceService->createGeneration($user, [
                'prompt' => $validated['prompt'],
                'size' => $validated['size'],
                'quality' => $validated['quality'],
                'reference_urls' => $referenceImages,
                'estimated_cost' => $estimate['user_price'],
            ]);
        } catch (InsufficientBalanceException $e) {
            return back()->withErrors([
                'balance' => $e->getMessage(),
            ]);
        }

        SubmitGenerationJob::dispatch($generation);

        return redirect()->route('generations.index');
    }
}
