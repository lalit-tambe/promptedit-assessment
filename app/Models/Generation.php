<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $idempotency_key
 * @property int $user_id
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property array<int, string>|null $reference_urls
 * @property string $status
 * @property string|null $provider_id
 * @property string $estimated_cost
 * @property string|null $provider_cost
 * @property string|null $charged_cost
 * @property int|null $input_text_tokens
 * @property int|null $input_image_tokens
 * @property int|null $output_tokens
 * @property int|null $reasoning_tokens
 * @property int|null $total_tokens
 * @property array<string, string>|null $rates
 * @property string|null $image_url
 * @property Carbon|null $image_url_expires_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int $retry_count
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'idempotency_key',
    'user_id',
    'prompt',
    'size',
    'quality',
    'reference_urls',
    'status',
    'provider_id',
    'estimated_cost',
    'provider_cost',
    'charged_cost',
    'input_text_tokens',
    'input_image_tokens',
    'output_tokens',
    'reasoning_tokens',
    'total_tokens',
    'rates',
    'image_url',
    'image_url_expires_at',
    'error_code',
    'error_message',
    'retry_count',
    'started_at',
    'finished_at',
])]
class Generation extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_PROCESSING,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
    ];

    public const FINAL_STATUSES = [
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
    ];

    protected static function booted(): void
    {
        static::creating(function (Generation $generation): void {
            if (empty($generation->idempotency_key)) {
                $generation->idempotency_key = (string) Str::uuid();
            }

            if (empty($generation->status)) {
                $generation->status = self::STATUS_QUEUED;
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reference_urls' => 'array',
            'rates' => 'array',
            'estimated_cost' => 'decimal:12',
            'provider_cost' => 'decimal:9',
            'charged_cost' => 'decimal:12',
            'input_text_tokens' => 'integer',
            'input_image_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'total_tokens' => 'integer',
            'retry_count' => 'integer',
            'image_url_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL_STATUSES, true);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasOne<CreditLedger, $this>
     */
    public function chargeLedgerEntry(): HasOne
    {
        return $this->hasOne(CreditLedger::class)->where('type', CreditLedger::TYPE_CHARGE);
    }
}
