<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->string('size', 32)->default('1024x1024');
            $table->string('quality', 32)->default('medium');
            $table->json('reference_urls')->nullable();
            $table->string('status', 32)->default('queued')->index();
            $table->string('provider_id')->nullable()->unique();

            // Exact decimal columns for money (no floats anywhere)
            $table->decimal('estimated_cost', 20, 12);
            $table->decimal('provider_cost', 18, 9)->nullable();
            $table->decimal('charged_cost', 20, 12)->nullable();

            // Token counts returned by Pixelforge upon completion
            $table->unsignedInteger('input_text_tokens')->nullable();
            $table->unsignedInteger('input_image_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->json('rates')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
