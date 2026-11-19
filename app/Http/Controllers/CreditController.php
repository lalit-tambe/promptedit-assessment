<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CreditBalanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CreditController extends Controller
{
    public function store(Request $request, CreditBalanceService $balanceService): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $entry = $balanceService->purchaseCredits($user);

        return back()->with(
            'status',
            sprintf('Added %s in credits to your balance.', $balanceService->formatUsd((string) $entry->amount, 2))
        );
    }
}
