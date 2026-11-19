<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertCircle,
    CreditCard,
    ImagePlus,
    Loader2,
    Sparkles,
    Trash2,
    Wallet,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatUsd, parseDecimalToScaledBigInt } from '@/lib/money';

type TokenBreakdown = {
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
    total_tokens: number;
};

type EstimateResult = {
    tokens: TokenBreakdown;
    provider_cost: string;
    user_price: string;
};

type Props = {
    rates: {
        input_text_tokens: string;
        input_image_tokens: string;
        output_tokens: string;
        reasoning_tokens: string;
    };
    sizes: string[];
    qualities: string[];
    tokens: {
        characters_per_text_token: number;
        text_token_variance_percent: number;
        per_reference_image: number;
        output: Record<string, Record<string, number>>;
        reasoning: Record<string, { min: number; max: number }>;
    };
    markup: string;
    limits?: {
        max_concurrent?: number;
        max_prompt_length?: number;
        max_reference_images?: number;
    };
    creditPurchaseAmount: string;
    initialEstimate: EstimateResult;
    initialMaxEstimate: EstimateResult;
};

const props = defineProps<Props>();
const page = usePage();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Generate',
                href: '/generate',
            },
        ],
    },
});

const maxPromptLength = computed(() => props.limits?.max_prompt_length ?? 4000);
const maxReferenceImages = computed(
    () => props.limits?.max_reference_images ?? 4,
);

type GenerationFormData = {
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
};

const createForm = useForm;
const genForm = createForm<GenerationFormData>({
    prompt: '',
    size: props.sizes.includes('1024x1024')
        ? '1024x1024'
        : (props.sizes[0] ?? '1024x1024'),
    quality: props.qualities.includes('medium')
        ? 'medium'
        : (props.qualities[0] ?? 'medium'),
    reference_images: [],
});

const referenceImages = computed(() => genForm.reference_images);
const canAddReferenceImage = computed(
    () => genForm.reference_images.length < maxReferenceImages.value,
);
const promptCharacterCount = computed(() => [...genForm.prompt].length);

const activeReferenceImageCount = computed(
    () =>
        genForm.reference_images.filter(
            (url) => typeof url === 'string' && url.trim() !== '',
        ).length,
);

const currentBalance = computed(
    () => page.props.auth?.balance ?? '0.000000000000',
);
const flashStatus = computed(() => page.props.flash?.status ?? null);
const purchaseForm = useForm({});

function buyCredits(): void {
    purchaseForm.post('/credits/purchase', {
        preserveScroll: true,
    });
}

function formatDisplayUsd(decimalString: string, minDecimals = 6): string {
    return formatUsd(decimalString, minDecimals);
}

/**
 * Exact client-side calculation using server-provided rates, token tables, and markup (no floats).
 */
function computeFromServerConfig(isMax: boolean): EstimateResult {
    const chars = promptCharacterCount.value;
    const charsPerToken = Math.max(
        1,
        props.tokens.characters_per_text_token || 4,
    );
    const baseTextTokens = Math.floor(
        (chars + charsPerToken - 1) / charsPerToken,
    );

    const variance = props.tokens.text_token_variance_percent || 15;
    const textTokens = isMax
        ? Math.floor((baseTextTokens * (100 + variance) + 99) / 100)
        : baseTextTokens;

    const imageTokens =
        activeReferenceImageCount.value *
        (props.tokens.per_reference_image || 765);

    const outputTokens =
        props.tokens.output?.[genForm.quality]?.[genForm.size] ?? 1100;

    const reasoningRange = props.tokens.reasoning?.[genForm.quality] ?? {
        min: 200,
        max: 2000,
    };
    const reasoningTokens = isMax
        ? reasoningRange.max
        : Math.floor((reasoningRange.min + reasoningRange.max) / 2);

    // Rates per 1M tokens in scale-9 nanodollars (1 USD = 1_000_000_000 nanodollars)
    // Since rates are per 1,000,000 tokens, 1 token at rate R per 1M costs R * 1000 nanodollars
    const textRateNano = parseDecimalToScaledBigInt(
        props.rates.input_text_tokens,
        3,
    );
    const imageRateNano = parseDecimalToScaledBigInt(
        props.rates.input_image_tokens,
        3,
    );
    const outputRateNano = parseDecimalToScaledBigInt(
        props.rates.output_tokens,
        3,
    );
    const reasoningRateNano = parseDecimalToScaledBigInt(
        props.rates.reasoning_tokens,
        3,
    );

    const providerNano =
        BigInt(textTokens) * textRateNano +
        BigInt(imageTokens) * imageRateNano +
        BigInt(outputTokens) * outputRateNano +
        BigInt(reasoningTokens) * reasoningRateNano;

    // markup scaled by 1000 (e.g. '1.25' -> 1250n) -> userPrice in scale-12 picodollars
    const markupMilli = parseDecimalToScaledBigInt(props.markup, 3);
    const userPico = providerNano * markupMilli;

    const providerWhole = providerNano / 1_000_000_000n;
    const providerFrac = (providerNano % 1_000_000_000n)
        .toString()
        .padStart(9, '0');

    const userWhole = userPico / 1_000_000_000_000n;
    const userFrac = (userPico % 1_000_000_000_000n)
        .toString()
        .padStart(12, '0');

    return {
        tokens: {
            input_text_tokens: textTokens,
            input_image_tokens: imageTokens,
            output_tokens: outputTokens,
            reasoning_tokens: reasoningTokens,
            total_tokens:
                textTokens + imageTokens + outputTokens + reasoningTokens,
        },
        provider_cost: `${providerWhole}.${providerFrac}`,
        user_price: `${userWhole}.${userFrac}`,
    };
}

const serverEstimate = ref<EstimateResult>(props.initialEstimate);
const serverMaxEstimate = ref<EstimateResult>(props.initialMaxEstimate);
let debounceTimer: ReturnType<typeof setTimeout> | null = null;

const hasInsufficientBalance = computed(
    () =>
        parseDecimalToScaledBigInt(currentBalance.value, 12) <
        parseDecimalToScaledBigInt(serverEstimate.value.user_price, 12),
);

const balanceError = computed(() => {
    const errors = genForm.errors as Record<string, string | undefined>;
    return errors.balance;
});

watch(
    () => [
        genForm.prompt,
        genForm.size,
        genForm.quality,
        ...genForm.reference_images,
    ],
    () => {
        // Immediate reactive update using server-provided pricing configuration
        serverEstimate.value = computeFromServerConfig(false);
        serverMaxEstimate.value = computeFromServerConfig(true);

        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }

        debounceTimer = setTimeout(async () => {
            try {
                const xsrfCookie = document.cookie
                    .split('; ')
                    .find((row) => row.startsWith('XSRF-TOKEN='))
                    ?.split('=')[1];

                const response = await fetch('/generations/estimate', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        ...(xsrfCookie
                            ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrfCookie) }
                            : {}),
                    },
                    body: JSON.stringify({
                        prompt: genForm.prompt,
                        size: genForm.size,
                        quality: genForm.quality,
                        reference_images: genForm.reference_images,
                    }),
                });

                if (response.ok) {
                    const data = (await response.json()) as {
                        estimate?: EstimateResult;
                        max_estimate?: EstimateResult;
                    };
                    if (data.estimate) {
                        serverEstimate.value = data.estimate;
                    }
                    if (data.max_estimate) {
                        serverMaxEstimate.value = data.max_estimate;
                    }
                }
            } catch {
                // Local calculation from server props is already active
            }
        }, 180);
    },
    { deep: true },
);

function addReferenceImage(): void {
    if (genForm.reference_images.length < maxReferenceImages.value) {
        genForm.reference_images.push('');
    }
}

function removeReferenceImage(index: number): void {
    genForm.reference_images.splice(index, 1);
}

function getReferenceImageError(index: number): string | undefined {
    const errors = genForm.errors as Record<string, string | undefined>;
    return errors[`reference_images.${index}`];
}

function getReferencePlaceholder(index: number): string {
    return `https://example.com/reference-${index + 1}.png`;
}

function submit(): void {
    genForm.post('/generations');
}
</script>

<template>
    <Head title="Generate Image" />

    <div class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4 sm:p-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">
                Generate an Image
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Create an image with Pixelforge and preview your estimated price
                before submitting.
            </p>
        </div>

        <div
            class="flex flex-wrap items-center justify-between gap-4 rounded-xl border bg-card px-5 py-4 shadow-xs"
            data-testid="balance-bar"
        >
            <div class="flex items-center gap-3">
                <div
                    class="flex size-9 items-center justify-center rounded-lg bg-muted text-foreground"
                >
                    <Wallet class="size-4" />
                </div>
                <div>
                    <div class="text-xs text-muted-foreground">
                        Credit balance
                    </div>
                    <div
                        class="font-mono text-lg font-semibold tracking-tight text-foreground"
                        data-testid="generate-credit-balance"
                    >
                        {{ formatDisplayUsd(currentBalance, 2) }}
                    </div>
                </div>
            </div>

            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="purchaseForm.processing"
                data-testid="buy-credits-button"
                @click="buyCredits"
            >
                <Loader2
                    v-if="purchaseForm.processing"
                    class="mr-1.5 size-4 animate-spin"
                />
                <CreditCard v-else class="mr-1.5 size-4" />
                Buy credits (+{{
                    formatDisplayUsd(props.creditPurchaseAmount, 2)
                }})
            </Button>
        </div>

        <div
            v-if="flashStatus"
            class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300"
            data-testid="credit-purchase-status"
        >
            {{ flashStatus }}
        </div>

        <form
            class="flex flex-col gap-6 rounded-xl border bg-card p-6 shadow-xs"
            @submit.prevent="submit"
        >
            <!-- Prompt -->
            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="prompt">Prompt</Label>
                    <span
                        class="font-mono text-xs"
                        :class="
                            promptCharacterCount > maxPromptLength
                                ? 'font-semibold text-destructive'
                                : 'text-muted-foreground'
                        "
                        data-testid="character-counter"
                    >
                        {{ promptCharacterCount }} / {{ maxPromptLength }}
                    </span>
                </div>
                <textarea
                    id="prompt"
                    v-model="genForm.prompt"
                    rows="4"
                    :maxlength="maxPromptLength"
                    required
                    placeholder="A lighthouse on a cliff at dawn, oil painting..."
                    class="flex min-h-[110px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                ></textarea>
                <InputError :message="genForm.errors.prompt" />
            </div>

            <!-- Size & Quality -->
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="size">Size</Label>
                    <select
                        id="size"
                        v-model="genForm.size"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    >
                        <option
                            v-for="sizeOption in props.sizes"
                            :key="sizeOption"
                            :value="sizeOption"
                            class="bg-background text-foreground"
                        >
                            {{ sizeOption }}
                        </option>
                    </select>
                    <InputError :message="genForm.errors.size" />
                </div>

                <div class="grid gap-2">
                    <Label for="quality">Quality</Label>
                    <select
                        id="quality"
                        v-model="genForm.quality"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm capitalize shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    >
                        <option
                            v-for="qualityOption in props.qualities"
                            :key="qualityOption"
                            :value="qualityOption"
                            class="bg-background text-foreground capitalize"
                        >
                            {{ qualityOption }}
                        </option>
                    </select>
                    <InputError :message="genForm.errors.quality" />
                </div>
            </div>

            <!-- Reference Image URLs (up to 4) -->
            <div class="grid gap-3">
                <div class="flex items-center justify-between">
                    <div>
                        <Label>Reference Image URLs (optional)</Label>
                        <p class="text-xs text-muted-foreground">
                            Up to {{ maxReferenceImages }} http(s) URLs ({{
                                props.tokens.per_reference_image
                            }}
                            tokens each).
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="!canAddReferenceImage"
                        data-testid="add-reference-image"
                        @click="addReferenceImage"
                    >
                        <ImagePlus class="mr-1.5 size-4" />
                        Add URL ({{ genForm.reference_images.length }}/{{
                            maxReferenceImages
                        }})
                    </Button>
                </div>

                <div v-if="referenceImages.length" class="grid gap-3">
                    <div
                        v-for="(imageUrl, index) in referenceImages"
                        :key="index"
                        class="grid gap-1.5"
                    >
                        <div class="flex items-center gap-2">
                            <Input
                                v-model="genForm.reference_images[index]"
                                type="url"
                                :placeholder="getReferencePlaceholder(index)"
                                :aria-label="`Reference image URL ${index + 1}`"
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="shrink-0 text-muted-foreground hover:text-destructive"
                                :aria-label="`Remove reference image ${index + 1}`"
                                @click="removeReferenceImage(index)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                        <InputError :message="getReferenceImageError(index)" />
                    </div>
                </div>

                <InputError :message="genForm.errors.reference_images" />
            </div>

            <!-- Estimated Cost Box -->
            <div
                class="rounded-lg border bg-muted/40 p-4"
                data-testid="estimate-panel"
            >
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <div>
                        <span class="text-sm font-medium text-foreground">
                            Estimated cost
                        </span>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            The final price is known after the image finishes
                            generating.
                        </p>
                    </div>
                    <div class="text-right">
                        <div
                            class="font-mono text-2xl font-semibold tracking-tight text-foreground"
                            data-testid="estimated-cost-value"
                        >
                            {{ formatDisplayUsd(serverEstimate.user_price, 6) }}
                        </div>
                        <div
                            class="font-mono text-xs text-muted-foreground"
                            data-testid="max-estimated-cost-value"
                        >
                            Most it could cost:
                            {{
                                formatDisplayUsd(
                                    serverMaxEstimate.user_price,
                                    6,
                                )
                            }}
                        </div>
                    </div>
                </div>

                <div
                    class="mt-3 flex flex-wrap gap-x-4 gap-y-1 border-t pt-2.5 text-xs text-muted-foreground"
                >
                    <span>
                        Text: ~{{ serverEstimate.tokens.input_text_tokens }}
                        tokens
                    </span>
                    <span>
                        Images:
                        {{ serverEstimate.tokens.input_image_tokens }} tokens
                    </span>
                    <span>
                        Output:
                        {{ serverEstimate.tokens.output_tokens }} tokens
                    </span>
                    <span>
                        Reasoning: ~{{ serverEstimate.tokens.reasoning_tokens }}
                        tokens
                    </span>
                </div>
            </div>

            <!-- Insufficient Balance Warning -->
            <div
                v-if="balanceError || hasInsufficientBalance"
                class="flex items-start gap-2.5 rounded-lg border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                role="alert"
                data-testid="insufficient-balance-alert"
            >
                <AlertCircle class="mt-0.5 size-4 shrink-0" />
                <div>
                    <p v-if="balanceError">{{ balanceError }}</p>
                    <p v-else>
                        Insufficient credit balance. Your current balance ({{
                            formatDisplayUsd(currentBalance, 6)
                        }}) is lower than the estimated cost ({{
                            formatDisplayUsd(serverEstimate.user_price, 6)
                        }}). Click <strong>Buy credits</strong> above to add
                        funds before generating.
                    </p>
                </div>
            </div>

            <!-- Submit -->
            <div class="flex items-center justify-end gap-3">
                <Button
                    type="submit"
                    :disabled="
                        genForm.processing ||
                        hasInsufficientBalance ||
                        promptCharacterCount === 0 ||
                        promptCharacterCount > maxPromptLength
                    "
                    data-testid="submit-generation"
                >
                    <Loader2
                        v-if="genForm.processing"
                        class="mr-2 size-4 animate-spin"
                    />
                    <Sparkles v-else class="mr-2 size-4" />
                    {{
                        genForm.processing ? 'Submitting...' : 'Generate Image'
                    }}
                </Button>
            </div>
        </form>
    </div>
</template>
