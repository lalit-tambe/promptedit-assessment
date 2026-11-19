<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
        'pixelforge.api_key' => 'pf_test_key',
    ]);
});

it('redirects guests to login when visiting /admin/report', function () {
    $this->get('/admin/report')->assertRedirect('/login');
});

it('returns 403 forbidden for non-admin users visiting /admin/report', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get('/admin/report')
        ->assertForbidden();
});

it('groups succeeded generations by UTC completed day and user, excludes failed generations, and matches GET /v1/usage', function () {
    $admin = User::factory()->create(['name' => 'Admin User', 'is_admin' => true]);
    $alice = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
    $bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);

    // Created on Nov 17 UTC, completed on Nov 18 UTC -> must group under 2026-11-18!
    Generation::query()->create([
        'user_id' => $alice->id,
        'prompt' => 'Cross-midnight image',
        'size' => '1536x1024',
        'quality' => 'high',
        'status' => Generation::STATUS_SUCCEEDED,
        'provider_id' => 'gen_1',
        'estimated_cost' => '0.380000000000',
        'provider_cost' => '0.305510000',
        'charged_cost' => '0.381887500000',
        'created_at' => Carbon::parse('2026-11-17 23:55:00', 'UTC'),
        'finished_at' => Carbon::parse('2026-11-18 00:05:00', 'UTC'),
    ]);

    // Second succeeded generation for Alice on Nov 18 UTC
    Generation::query()->create([
        'user_id' => $alice->id,
        'prompt' => 'Second Alice image',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => Generation::STATUS_SUCCEEDED,
        'provider_id' => 'gen_2',
        'estimated_cost' => '0.070000000000',
        'provider_cost' => '0.063784800',
        'charged_cost' => '0.079731000000',
        'created_at' => Carbon::parse('2026-11-18 10:00:00', 'UTC'),
        'finished_at' => Carbon::parse('2026-11-18 10:01:00', 'UTC'),
    ]);

    // Succeeded generation for Bob on Nov 18 UTC
    Generation::query()->create([
        'user_id' => $bob->id,
        'prompt' => 'Bob image on Nov 18',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => Generation::STATUS_SUCCEEDED,
        'provider_id' => 'gen_3',
        'estimated_cost' => '0.050000000000',
        'provider_cost' => '0.054634400',
        'charged_cost' => '0.068293000000',
        'created_at' => Carbon::parse('2026-11-18 12:00:00', 'UTC'),
        'finished_at' => Carbon::parse('2026-11-18 12:02:00', 'UTC'),
    ]);

    // Succeeded generation for Bob on Nov 19 UTC (using non-UTC offset that converts to Nov 19 UTC)
    Generation::query()->create([
        'user_id' => $bob->id,
        'prompt' => 'Bob image on Nov 19',
        'size' => '1536x1024',
        'quality' => 'high',
        'status' => Generation::STATUS_SUCCEEDED,
        'provider_id' => 'gen_4',
        'estimated_cost' => '0.400000000000',
        'provider_cost' => '0.205589600',
        'charged_cost' => '0.256987000000',
        'created_at' => Carbon::parse('2026-11-19 04:00:00', 'UTC'),
        'finished_at' => Carbon::parse('2026-11-19 06:30:00+05:30'), // 01:00:00 UTC on Nov 19
    ]);

    // Failed, queued, and processing generations must NOT be included
    Generation::query()->create([
        'user_id' => $alice->id,
        'prompt' => 'Failed image',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => Generation::STATUS_FAILED,
        'provider_id' => 'gen_failed',
        'estimated_cost' => '0.080000000000',
        'provider_cost' => '0.000000000',
        'charged_cost' => '0.000000000000',
        'finished_at' => Carbon::parse('2026-11-18 15:00:00', 'UTC'),
    ]);

    Generation::query()->create([
        'user_id' => $bob->id,
        'prompt' => 'Still processing',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => Generation::STATUS_PROCESSING,
        'provider_id' => 'gen_processing',
        'estimated_cost' => '0.080000000000',
    ]);

    // Nov 18 provider_cost sum: 0.305510000 + 0.063784800 + 0.054634400 = 0.423929200
    // Nov 19 provider_cost sum: 0.205589600
    // Grand total provider_cost: 0.629518800
    // Grand total charged_cost:  0.381887500000 + 0.079731000000 + 0.068293000000 + 0.256987000000 = 0.786898500000
    // Grand total profit:        0.786898500000 - 0.629518800 = 0.157379700000
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/usage*' => Http::response([
            'data' => [
                ['date' => '2026-11-18', 'cost_usd' => '0.423929200'],
                ['date' => '2026-11-19', 'cost_usd' => '0.205589600'],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($admin)->get('/admin/report');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Report')
            ->has('rows', 3)
            // Row 0: 2026-11-19 Bob
            ->where('rows.0.date', '2026-11-19')
            ->where('rows.0.user_id', $bob->id)
            ->where('rows.0.generations_count', 1)
            ->where('rows.0.provider_cost', '0.205589600')
            ->where('rows.0.charged_cost', '0.256987000000')
            ->where('rows.0.profit', '0.051397400000')
            // Row 1: 2026-11-18 Alice (2 generations summed)
            ->where('rows.1.date', '2026-11-18')
            ->where('rows.1.user_id', $alice->id)
            ->where('rows.1.generations_count', 2)
            ->where('rows.1.provider_cost', '0.369294800')
            ->where('rows.1.charged_cost', '0.461618500000')
            ->where('rows.1.profit', '0.092323700000')
            // Row 2: 2026-11-18 Bob (1 generation)
            ->where('rows.2.date', '2026-11-18')
            ->where('rows.2.user_id', $bob->id)
            ->where('rows.2.generations_count', 1)
            ->where('rows.2.provider_cost', '0.054634400')
            ->where('rows.2.charged_cost', '0.068293000000')
            ->where('rows.2.profit', '0.013658600000')
            // Daily totals & GET /v1/usage match
            ->has('daily_totals', 2)
            ->where('daily_totals.0.date', '2026-11-19')
            ->where('daily_totals.0.provider_cost', '0.205589600')
            ->where('daily_totals.0.pixelforge_cost_usd', '0.205589600')
            ->where('daily_totals.0.matches_pixelforge', true)
            ->where('daily_totals.1.date', '2026-11-18')
            ->where('daily_totals.1.provider_cost', '0.423929200')
            ->where('daily_totals.1.pixelforge_cost_usd', '0.423929200')
            ->where('daily_totals.1.matches_pixelforge', true)
            // Grand total row
            ->where('totals.generations_count', 4)
            ->where('totals.provider_cost', '0.629518800')
            ->where('totals.charged_cost', '0.786898500000')
            ->where('totals.profit', '0.157379700000')
            ->where('usage_check.fetched', true)
            ->where('usage_check.all_days_match', true)
            ->where('usage_check.total_pixelforge_cost_usd', '0.629518800')
        );
});

it('still renders the admin report gracefully when GET /v1/usage fails', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Generation::query()->create([
        'user_id' => $admin->id,
        'prompt' => 'Single succeeded image',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => Generation::STATUS_SUCCEEDED,
        'provider_id' => 'gen_graceful',
        'estimated_cost' => '0.020000000000',
        'provider_cost' => '0.016000000',
        'charged_cost' => '0.020000000000',
        'finished_at' => Carbon::parse('2026-11-18 08:00:00', 'UTC'),
    ]);

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/usage*' => Http::response(['error' => ['message' => 'Service unavailable']], 503),
    ]);

    $this->actingAs($admin)
        ->get('/admin/report')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Report')
            ->where('totals.generations_count', 1)
            ->where('totals.provider_cost', '0.016000000')
            ->where('usage_check.fetched', false)
            ->where('usage_check.all_days_match', null)
        );
});

it('promotes a user to admin with php artisan user:make-admin email and fails for unknown email', function () {
    $user = User::factory()->create([
        'email' => 'operator@example.com',
        'is_admin' => false,
    ]);

    $this->artisan('user:make-admin', ['email' => 'operator@example.com'])
        ->expectsOutput('User [operator@example.com] is now an admin.')
        ->assertSuccessful();

    expect($user->refresh()->is_admin)->toBeTrue();

    // Running again is idempotent
    $this->artisan('user:make-admin', ['email' => 'operator@example.com'])
        ->expectsOutput('User [operator@example.com] is already an admin.')
        ->assertSuccessful();

    // Unknown email fails cleanly
    $this->artisan('user:make-admin', ['email' => 'missing@example.com'])
        ->expectsOutput('User with email [missing@example.com] not found.')
        ->assertFailed();
});
