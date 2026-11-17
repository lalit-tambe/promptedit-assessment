<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('reference_images'))) {
            $filtered = array_values(array_filter(
                $this->input('reference_images'),
                static fn (mixed $url): bool => is_string($url) ? trim($url) !== '' : $url !== null
            ));

            $this->merge([
                'reference_images' => $filtered,
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var array<int, string> $sizes */
        $sizes = (array) config('pixelforge.sizes', ['1024x1024', '1536x1024', '1024x1536']);

        /** @var array<int, string> $qualities */
        $qualities = (array) config('pixelforge.qualities', ['low', 'medium', 'high']);

        $maxPromptLength = (int) config('pixelforge.limits.max_prompt_length', 4000);
        $maxReferenceImages = (int) config('pixelforge.limits.max_reference_images', 4);

        return [
            'prompt' => ['required', 'string', "max:{$maxPromptLength}"],
            'size' => ['required', 'string', Rule::in($sizes)],
            'quality' => ['required', 'string', Rule::in($qualities)],
            'reference_images' => ['nullable', 'array', "max:{$maxReferenceImages}"],
            'reference_images.*' => ['required', 'string', 'url:http,https'],
        ];
    }
}
