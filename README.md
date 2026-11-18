# PromptEdit — Pixelforge Image Generation App

PromptEdit is a Laravel, Vue 3, and Inertia web application where signed-in users generate images through the simulated Pixelforge AI provider. It shows an upfront price estimate before submitting, processes image generation asynchronously through background queues and signed webhooks, charges the exact token-based amount only when a generation succeeds, and records `$0` for failed jobs.

---

## 1. How to Run It Locally

### Requirements

- PHP 8.3+ with `bcmath`, `sqlite3`, `mbstring`, `xml`, `curl`, and `zip`
- Composer 2
- Node.js 20+
- A tunnel tool (`cloudflared` or `ngrok`) for receiving webhooks locally

### Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
```

Add your local Pixelforge credentials to `.env`:

```dotenv
PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1
PIXELFORGE_API_KEY=pf_your_local_api_key
PIXELFORGE_WEBHOOK_SECRET=whsec_your_local_webhook_secret
QUEUE_CONNECTION=database
DB_CONNECTION=sqlite
```

### Start the Local Processes

Run these in separate terminals:

```bash
# 1. Web server
php artisan serve

# 2. Queue worker (submits generations & processes webhooks)
php artisan queue:work

# 3. Scheduler (polls Pixelforge every minute for missed webhooks)
php artisan schedule:work

# 4. Public webhook tunnel
cloudflared tunnel --url http://localhost:8000
```

Set your `local` API key's webhook URL in the Pixelforge dashboard to:

```text
https://<your-tunnel-url>/webhooks/pixelforge
```

### Verify Money & Run Tests

```bash
php artisan test
php artisan money:check
```

---

## 2. How Money Is Stored and Why

All money values are stored as **exact decimal strings** and calculated with PHP's `bcmath` extension (or `BigInt` on the frontend). **Floats are never used anywhere for money**, and **stored numbers are never rounded**.

- **`provider_cost` (`decimal(18, 9)`)**: Pixelforge's `GET /v1/usage` report returns `cost_usd` with 9 decimal places (1 token at `$1.00` per 1M tokens equals `$0.000001`, and reasoning rates have `.50`, which uses up to 9 decimal places). Storing all 9 decimal places with no rounding ensures our database totals match `GET /v1/usage` to the exact last digit.
- **`charged_cost` & `estimated_cost` (`decimal(20, 12)`)**: The user price is `provider_cost × 1.25`. Multiplying a 9-decimal number by `1.25` can produce up to 11 decimal places, so we store user prices and `credit_ledger.amount` with 12 decimal places.
- **Failed generations**: Always store `provider_cost = 0.000000000`, `charged_cost = 0.000000000000`, and create **no** charge entry in `credit_ledger`.
- **Display**: Numbers are formatted for readability on screen only; the underlying stored values are untouched.

---

## 3. How the Estimate and Final Price Are Worked Out

Pixelforge bills per 1,000,000 tokens across four token types:
$$\text{provider\_cost} = \frac{(t_{\text{text}} \times r_{\text{text}}) + (t_{\text{image}} \times r_{\text{image}}) + (t_{\text{out}} \times r_{\text{out}}) + (t_{\text{reason}} \times r_{\text{reason}})}{1{,}000{,}000}$$

$$\text{user\_price} = \text{provider\_cost} \times 1.25$$

- **Upfront Estimate (`PricingCalculator::estimate`)**:
    - Reads current rates from `GET /v1/pricing` (cached for 10 minutes, falling back to `config/pixelforge.php`).
    - **Input text tokens**: `ceil(characters / 4)` counting Unicode characters via `mb_strlen($prompt, 'UTF-8')` using integer division.
    - **Input image tokens**: `765 × referenceImageCount`.
    - **Output tokens**: Exact table lookup by `quality` and `size` (`280` to `6,450`).
    - **Reasoning tokens**: Midpoint of the quality's range (`low`: 250, `medium`: 1,100, `high`: 4,500).
- **Most It Could Cost (`PricingCalculator::maxEstimate`)**:
    - Uses estimated text tokens `+ 15%` (rounded up) and the maximum reasoning tokens for that quality (`low`: 500, `medium`: 2,000, `high`: 8,000).
- **Final Charge (`PricingCalculator::providerCost` & `userPrice`)**:
    - Uses the actual `usage` token counts and the fixed `rates` snapshot returned on the completed generation object.

---

## 4. How Duplicate and Out-of-Order Webhooks Are Handled

1. **Signature & Replay Check (`WebhookSignatureVerifier`)**:
    - Reads the raw request body before JSON re-encoding, parses `Pixelforge-Signature` (`t=<timestamp>,v1=<hex>`), verifies `hash_hmac('sha256', "{$t}.{$rawBody}", $secret)` using constant-time `hash_equals`, and rejects timestamps more than 300 seconds old or in the future with HTTP `400`.
2. **Event Deduplication (`webhook_events`)**:
    - Every incoming event's `id` (`evt_...`) is stored in a `UNIQUE` `event_id` column on `webhook_events`. If the same `event_id` arrives a second time, the controller immediately returns `204 No Content` and does nothing else.
3. **Early Webhook Arrival (Before `POST /v1/generations` Returns)**:
    - On creation, `SubmitGenerationJob` sends our database row ID and UUID in `metadata.generation_id` and `metadata.idempotency_key`. When a webhook arrives, `ProcessWebhookEvent` looks up the generation by `metadata.generation_id` first (falling back to `provider_id`), and saves `provider_id` if the create response hasn't saved it yet.
4. **Row Locking & Forward-Only Status Transitions**:
    - Updates run inside `DB::transaction()` with `Generation::query()->lockForUpdate()->find(...)`.
    - Statuses only move forward: `queued` → `processing` → `succeeded` or `failed`. Once a generation is `succeeded` or `failed`, late `processing` events are ignored.
5. **Single-Charge Guarantee (`credit_ledger`)**:
    - The `credit_ledger` table enforces a database-level `UNIQUE` index on `generation_id` (and `(generation_id, type)`), and uses `firstOrCreate` inside the locked transaction. Even if two processes race, a generation can never be charged twice.

---

## 5. How Missed Webhooks Are Handled

- `PollUnfinishedGenerationsJob` runs every minute via the Laravel scheduler (`routes/console.php`).
- It finds any generation where `status IN ('queued', 'processing')`, `provider_id IS NOT NULL`, and `updated_at` is more than 2 minutes ago.
- For each stale generation, it calls `GET /v1/generations/{provider_id}` and runs `ProcessWebhookEvent::applyGenerationUpdate()` inside a locked database transaction—reusing the exact same status and billing rules as the webhook handler.

---

## 6. Limits, Idempotency, and Retries

- **Idempotency**: Every `Generation` row gets a unique UUID (`idempotency_key`) when created. `SubmitGenerationJob` sends it in the `Idempotency-Key` header on `POST /v1/generations` and never re-sends a generation that already has a `provider_id`.
- **Concurrency Limit (3 at a time)**:
    - Before calling Pixelforge, `SubmitGenerationJob` counts how many generations currently have `provider_id IS NOT NULL` and `status IN ('queued', 'processing')`. If 3 are already in flight, it releases the job back to the queue (`5s`) so extra jobs wait in `queued`.
    - If Pixelforge returns `429 concurrency_limit_exceeded`, the job keeps the generation `queued` and retries after `Retry-After` seconds (`5s`).
- **Transient Errors (`429 rate_limit_exceeded` & `503 overloaded`)**:
    - Releases the job back to the queue using the `Retry-After` header with exponential backoff (`Retry-After × 2^(attempt - 1)`), capped at 3 retries before marking the generation `failed` with cost `0`.
- **Permanent Errors (`400 content_policy_violation`, `422 validation_failed`, `429 daily_limit_exceeded`, `401 invalid_api_key`)**:
    - Marks the generation `failed` immediately with a clear error message and sets `provider_cost` and `charged_cost` to `0`.

---

## 7. What We Would Improve With More Time

1. **Operator Reconciliation Dashboard (`/admin/report`)**: Add an admin page that groups daily UTC provider costs, user charges, and profit (`charged - provider_cost`) by user and day, and automatically compares them against `GET /v1/usage` in the UI.
2. **User Credit Balances & Top-Up Flow**: Enforce pre-submit balance checks against `credit_ledger` (`purchases - charges >= estimated_cost`) with a credit purchase UI and balance reservation for in-flight jobs.
3. **Permanent Image Storage**: Download succeeded images to S3 / Cloudflare R2 in a background job so images remain viewable after Pixelforge's 24-hour signed URL expires.
4. **WebSockets (Laravel Reverb)**: Push status transitions (`queued` → `processing` → `succeeded`/`failed`) over WebSockets instead of 3-second HTTP polling.
5. **Distributed Concurrency Lock**: Use a Redis semaphore across multiple queue worker processes to coordinate the 3-concurrent-generations limit without database count races under high throughput.
