<?php

use App\Models\Generation;
use App\Models\User;

it('redirects guests to login when visiting /generations', function () {
    $this->get('/generations')->assertRedirect('/login');
});

it('shows only the signed-in user generations ordered newest first and never another user generations', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $aliceOlder = Generation::query()->create([
        'user_id' => $alice->id,
        'prompt' => 'Alice older prompt',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => Generation::STATUS_SUCCEEDED,
        'estimated_cost' => '0.020000000000',
        'provider_cost' => '0.015000000',
        'charged_cost' => '0.018750000000',
        'image_url' => 'https://pixelforge.promptedit.dev/images/alice_1.png',
    ]);
    Generation::query()->whereKey($aliceOlder->id)->update([
        'created_at' => now()->subMinutes(10),
    ]);

    $aliceNewer = Generation::query()->create([
        'user_id' => $alice->id,
        'prompt' => 'Alice newer failed prompt',
        'size' => '1536x1024',
        'quality' => 'high',
        'status' => Generation::STATUS_FAILED,
        'estimated_cost' => '0.400000000000',
        'provider_cost' => '0.000000000',
        'charged_cost' => '0.000000000000',
        'error_code' => 'content_policy_violation',
        'error_message' => 'Prompt was rejected.',
    ]);

    // Bob's generation must never appear for Alice
    Generation::query()->create([
        'user_id' => $bob->id,
        'prompt' => 'Bob secret prompt',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => Generation::STATUS_QUEUED,
        'estimated_cost' => '0.070000000000',
    ]);

    $response = $this->actingAs($alice)->get('/generations');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Generations/Index')
            ->where('hasUnfinished', false)
            ->has('generations.data', 2)
            ->where('generations.data.0.id', $aliceNewer->id)
            ->where('generations.data.0.prompt', 'Alice newer failed prompt')
            ->where('generations.data.0.status', 'failed')
            ->where('generations.data.0.charged_cost', '0.000000000000')
            ->where('generations.data.0.error_message', 'Prompt was rejected.')
            ->where('generations.data.1.id', $aliceOlder->id)
            ->where('generations.data.1.prompt', 'Alice older prompt')
            ->where('generations.data.1.status', 'succeeded')
            ->where('generations.data.1.charged_cost', '0.018750000000')
            ->where('generations.data.1.image_url', 'https://pixelforge.promptedit.dev/images/alice_1.png')
        );
});

it('sets hasUnfinished to true when the user has a queued or processing generation and paginates results', function () {
    $user = User::factory()->create();

    for ($i = 1; $i <= 12; $i++) {
        Generation::query()->create([
            'user_id' => $user->id,
            'prompt' => "Generation #{$i}",
            'size' => '1024x1024',
            'quality' => 'medium',
            'status' => $i === 12 ? Generation::STATUS_PROCESSING : Generation::STATUS_SUCCEEDED,
            'estimated_cost' => '0.070000000000',
            'charged_cost' => $i === 12 ? null : '0.062500000000',
        ]);
    }

    $response = $this->actingAs($user)->get('/generations');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Generations/Index')
            ->where('hasUnfinished', true)
            ->has('generations.data', 10)
            ->where('generations.total', 12)
            ->where('generations.last_page', 2)
            ->where('generations.data.0.status', 'processing')
            ->where('generations.data.0.charged_cost', null)
        );
});
