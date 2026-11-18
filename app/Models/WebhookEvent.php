<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $generation_provider_id
 * @property array<string, mixed> $payload
 * @property CarbonInterface $received_at
 * @property CarbonInterface|null $processed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'event_id',
    'type',
    'generation_provider_id',
    'payload',
    'received_at',
    'processed_at',
])]
class WebhookEvent extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
