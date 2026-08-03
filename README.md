# CZBD Courier Checker

A Laravel package that checks a customer's delivery history — success count, cancel count, and success ratio — across the major courier services operating in Bangladesh, using a single phone number. Built for e-commerce merchants who want to flag likely-fraudulent or high-cancel-rate customers before dispatching a COD (cash-on-delivery) order.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/devrkb21/czbd-courier.svg?style=flat-square)](https://packagist.org/packages/devrkb21/czbd-courier)
[![Total Downloads](https://img.shields.io/packagist/dt/devrkb21/czbd-courier.svg?style=flat-square)](https://packagist.org/packages/devrkb21/czbd-courier)
[![License](https://img.shields.io/packagist/l/devrkb21/czbd-courier.svg?style=flat-square)](https://packagist.org/packages/devrkb21/czbd-courier)

## Table of Contents

- [What It Does](#what-it-does)
- [How It Works](#how-it-works)
- [Supported Couriers](#supported-couriers)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Environment Configuration](#environment-configuration)
- [Multi-Account Failover Pools](#multi-account-failover-pools)
- [Proxy Routing](#proxy-routing)
- [Usage](#usage)
- [Response Shape](#response-shape)
- [Error Handling](#error-handling)
- [Performance: Caching and Rate Limiting](#performance-caching-and-rate-limiting)
- [Testing](#testing)
- [Security Notes](#security-notes)
- [Known Limitations](#known-limitations)
- [Troubleshooting](#troubleshooting)
- [License](#license)
- [Maintainer](#maintainer)

## What It Does

- Queries **Steadfast, Pathao, RedX, Paperfly, and Carrybee** with a single Bangladeshi mobile number.
- Returns per-courier delivered/cancelled counts and a success ratio for each.
- Builds an aggregate summary (combined success/cancel counts and ratios) across all five couriers.
- Supports **per-courier account failover pools** — configure multiple login credentials per courier, and the package automatically tries the next account if one fails, errors, or gets rate limited, in order, until one succeeds or the pool is exhausted.
- **Caches each account's authenticated session/token** so repeat calls skip the login round-trip entirely (see [Performance](#performance-caching-and-rate-limiting)).
- Supports optional global or per-courier HTTP proxy routing (useful if you're calling from an IP that a courier's site doesn't like, or need geographic routing).
- Never lets one broken/unconfigured/rate-limited courier take down the whole result — every courier fails independently and reports its own error.

## How It Works

```
CourierChecker (Facade)
        │
        ▼
CourierCheckerManager::check($phone)
        │
        ├── SteadfastService::getDeliveryStats($phone)
        ├── PathaoService::getDeliveryStats($phone)
        ├── RedxService::getDeliveryStats($phone)
        ├── PaperflyService::getDeliveryStats($phone)
        └── CarrybeeService::getDeliveryStats($phone)
```

- **`CourierCheckerManager`** is the orchestrator. It calls all five courier services **one at a time**, catches any exception a service throws, logs it, and always returns a complete result array — one key per courier, plus an `aggregate` summary. A single courier failing (bad credentials, network error, unexpected response) never stops the others from running. (Concurrent/parallel courier fetching was evaluated during development and intentionally not shipped — see [Performance](#performance-caching-and-rate-limiting) for why.)
- **Each courier service** (`SteadfastService`, `PathaoService`, `RedxService`, `PaperflyService`, `CarrybeeService`) implements the shared `CourierServiceInterface`, so they're interchangeable and independently usable/testable. The interface exposes both:
  - `getDeliveryStats(string $phoneNumber): array` — the normal, blocking call every example in this README uses.
  - `getDeliveryStatsAsync(string $phoneNumber): PromiseInterface` — an internal building block (`getDeliveryStats()` is literally `getDeliveryStatsAsync()->wait()`). It's there for advanced use cases and possible future work, but the package itself does not currently run couriers concurrently — treat it as an implementation detail, not a documented concurrency API.
- **`CourierCheckerServiceProvider`** wires everything into Laravel's container. Critically, it resolves each courier service defensively: if a courier's credentials are missing or invalid, that courier is swapped for a lightweight `UnavailableCourierService` stand-in that just reports "not configured" — it does **not** throw and break the whole package just because you haven't set up all five couriers. You can configure only the couriers you actually use.
- **`CourierChecker`** is the Laravel facade for `CourierCheckerManager`, registered under the container key `courier-checker`.

### Integration method per courier

Not all five couriers are integrated the same way, because most don't offer a public "check this phone number's fraud history" API — the package logs in as your merchant account and calls the same endpoints their own merchant dashboard uses:

| Courier | Integration method | Session caching |
|---|---|---|
| **Steadfast** | Logs into the merchant web portal (`steadfast.com.bd`), scraping a CSRF token from the login page HTML, then calls the dashboard's internal `getbyphone` endpoint. Session cookies are maintained via a real Guzzle cookie jar so redirects (e.g. an interstitial page mid-login) don't lose state. | Cookies cached ~50 min per account |
| **Pathao** | Uses Pathao's merchant web login endpoint (`merchant.pathao.com/api/v1/login`) to obtain a Bearer token, then calls the internal customer-success endpoint. | Token cached, TTL follows Pathao's own `expires_in` when present, else ~50 min |
| **RedX** | Uses RedX's merchant web login endpoint to obtain a Bearer access token, then calls the internal customer success/return-rate endpoint. | Token cached ~50 min per account |
| **Paperfly** | Uses Paperfly's Merchant Reactor API login endpoint to obtain a token, then calls their "Smart Check" search endpoint and derives success/cancel counts from the returned record statuses. | Token cached ~55 min per account |
| **Carrybee** | Drives Carrybee's NextAuth-based login flow (CSRF → credentials callback → session) to obtain a Bearer token + business ID, then calls the merchant API's customer lookup endpoint. | Token + business ID cached ~55 min per account |

Because these are internal/merchant-dashboard endpoints rather than documented public APIs, **they can change without notice** — see [Known Limitations](#known-limitations).

## Supported Couriers

- Steadfast
- Pathao
- RedX
- Paperfly
- Carrybee

## Requirements

- PHP: `^8.2`
- Laravel: `^8.0|^9.0|^10.0|^11.0|^12.0|^13.0`
- Guzzle: `^7.8`
- `guzzlehttp/promises: ^2.0` (installed automatically — pinned explicitly so the package's internal async plumbing doesn't silently resolve an older, incompatible promises version)

## Installation

```bash
composer require devrkb21/czbd-courier
```

Laravel auto-discovers the service provider (`Czbd\CourierChecker\CourierCheckerServiceProvider`) and the `CourierChecker` facade alias via the package's `composer.json` — no manual registration needed.

Publish the config file (optional — see below):

```bash
php artisan vendor:publish --provider="Czbd\\CourierChecker\\CourierCheckerServiceProvider" --tag="courier-checker-config"
```

This creates `config/courier-checker.php` in your application. You can also use Laravel's generic `config` tag:

```bash
php artisan vendor:publish --tag="config"
```

Publishing the config is optional — the package ships with sane defaults (all values pulled from `env()`), so you only need to publish it if you want to inspect or customize the structure.

## Quick Start

The fastest path from zero to a working check:

1. `composer require devrkb21/czbd-courier`
2. Add credentials for at least **one** courier to your `.env` (see [Environment Configuration](#environment-configuration) — you don't need all five):
   ```dotenv
   PATHAO_USERS="you@example.com"
   PATHAO_PASSWORDS="your_pathao_password"
   ```
3. Call it:
   ```php
   use CourierChecker;

   $report = CourierChecker::check('01712345678');
   ```
4. `$report` always has a key for every courier plus `aggregate` — couriers you didn't configure just report `{"error": "... is not configured", ...}` instead of breaking anything. See [Response Shape](#response-shape).

## Environment Configuration

Add credentials to your application's `.env` file. **Every courier takes exactly two env vars** — one for the credential list, one for the matching secret list. There is no separate "primary account" variable; the first entry in each list is simply tried first.

```dotenv
# Pathao (Pathao merchant login: email + password)
PATHAO_USERS="primary@example.com,fallback1@example.com,fallback2@example.com"
PATHAO_PASSWORDS="primary_pass,pass1,pass2"

# Steadfast (Steadfast merchant login: email + password)
STEADFAST_USERS="primary@example.com,fallback1@example.com,fallback2@example.com"
STEADFAST_PASSWORDS="primary_pass,pass1,pass2"

# RedX (local 11-digit mobile format, no +88 / country code prefix)
REDX_PHONES="017XXXXXXXX,018XXXXXXXX"
REDX_PASSWORDS="primary_pass,pass1"

# Paperfly (merchant username + password)
PAPERFLY_USERS="primary_user,fallback_user_1,fallback_user_2"
PAPERFLY_PASSWORDS="primary_pass,pass1,pass2"

# Carrybee (local 11-digit mobile format, no +88 / country code prefix)
CARRYBEE_PHONES="017XXXXXXXX,018XXXXXXXX"
CARRYBEE_PASSWORDS="primary_pass,pass1"

# Proxy flags (strict yes/no — any other value throws an exception)
COURIER_PROXY_ALL="no"
COURIER_PROXY_PATHAO="no"
COURIER_PROXY_STEADFAST="no"
COURIER_PROXY_REDX="no"
COURIER_PROXY_PAPERFLY="no"
COURIER_PROXY_CARRYBEE="no"

# Shared proxy endpoint (used by any courier with its flag — or COURIER_PROXY_ALL — set to "yes")
COURIER_PROXY_ADDRESS="http://127.0.0.1:8080"
```

You only need to configure the couriers you actually want to query. Any courier left blank simply reports `{"error": "<courier> is not configured", "message": "..."}` in the result instead of breaking the whole `check()` call — see [Error Handling](#error-handling).

Quoting note: wrap every value in double quotes in `.env`, especially passwords that contain `#`, spaces, or other special characters — an unquoted `#` is treated as a comment start by most dotenv parsers and will silently truncate the value.

## Multi-Account Failover Pools

Every courier accepts a **single comma- or newline-separated list per field** (e.g. `STEADFAST_USERS` / `STEADFAST_PASSWORDS`). This is how you configure multiple accounts per courier for redundancy and to avoid rate limits:

```dotenv
STEADFAST_USERS="acct1@example.com,acct2@example.com,acct3@example.com"
STEADFAST_PASSWORDS="pass1,pass2,pass3"
```

**Rules:**

- All lists for a courier must be **index-aligned and the same length**. 3 users requires exactly 3 passwords (`acct1` ↔ `pass1`, `acct2` ↔ `pass2`, etc.). A mismatch throws `InvalidArgumentException` when that courier's service is resolved from the container.
- Values can be separated by commas, newlines, or a mix, and surrounding whitespace is trimmed automatically.
- Exact-duplicate account rows (same value across every field) are silently deduplicated — no error, just one pool entry.
- **How the pool is used**: `getDeliveryStats()` on every courier service iterates the pool **in the order you listed it**, calling the courier for account #1 first. If that call returns a clean result (no `error` key present and the response actually contains the expected data fields), it's returned immediately and no other accounts are touched. If it errors — bad credentials, network failure, rate limit, or an unexpected/malformed response body — the next account in the pool is tried, and so on until one succeeds or the pool is exhausted (in which case the *last* error encountered is returned).
- This failover is **per-call, not persistent** — every fresh `check()` (or `getDeliveryStats()`) call starts again from account #1. There's no "skip this account for the next hour" memory between calls (see [Performance](#performance-caching-and-rate-limiting) for why that matters).
- Single-account setups are perfectly valid — just provide one value per list.

## Proxy Routing

- `COURIER_PROXY_ADDRESS` is the shared proxy endpoint (e.g. `http://127.0.0.1:8080` or an authenticated proxy URL) applied to both HTTP and HTTPS traffic for whichever couriers have proxying enabled.
- `COURIER_PROXY_ALL=yes` routes **every** courier's requests through that proxy.
- `COURIER_PROXY_{COURIER}=yes` (e.g. `COURIER_PROXY_STEADFAST=yes`) routes just that one courier through the proxy, independent of the others.
- Flags accept **only** `yes` or `no` (case-insensitive, trimmed) — anything else throws `InvalidArgumentException` immediately when that courier's service is resolved. An empty/missing value is treated as `no`.
- If `COURIER_PROXY_ADDRESS` is empty, no proxying happens regardless of the flags.

## Usage

### Using the facade

```php
use CourierChecker;

$report = CourierChecker::check('01712345678');
```

### Using dependency injection

```php
use Czbd\CourierChecker\CourierCheckerManager;

class OrderController
{
    public function show(CourierCheckerManager $manager)
    {
        return $manager->check('01712345678');
    }
}
```

### Using individual courier service classes

Useful if you only care about one courier, or want to bypass the aggregation:

```php
use Czbd\CourierChecker\Services\PathaoService;
use Czbd\CourierChecker\Services\SteadfastService;
use Czbd\CourierChecker\Services\RedxService;
use Czbd\CourierChecker\Services\PaperflyService;
use Czbd\CourierChecker\Services\CarrybeeService;

$result = app(PathaoService::class)->getDeliveryStats('01712345678');
```

Every service implements `Czbd\CourierChecker\Contracts\CourierServiceInterface`, so you can type-hint the interface and swap implementations (e.g. in tests) freely.

### A practical example: gating COD orders

```php
use CourierChecker;

class CheckoutController
{
    public function placeOrder(Request $request)
    {
        $report = CourierChecker::check($request->input('phone'));

        $ratio = $report['aggregate']['success_ratio'];
        $totalOrders = $report['aggregate']['total_deliveries'];

        // Only apply the rule once there's enough history to judge -
        // a brand-new customer with 0 orders isn't "risky", just unknown.
        if ($totalOrders >= 3 && $ratio < 50.0) {
            return back()->withErrors([
                'phone' => 'This number has a high cancellation rate. COD is unavailable; please pay online.',
            ]);
        }

        // ...proceed with order creation
    }
}
```

### Phone number format

Always use the **local 11-digit format**: `01[3-9]XXXXXXXX` (e.g. `01712345678`). Do **not** include `+88` or `88` — the package adds the country code internally wherever a specific courier's API requires it. Any other format is rejected before any HTTP request is made (see [Error Handling](#error-handling)).

## Response Shape

`CourierChecker::check()` / `CourierCheckerManager::check()` always returns all five courier keys plus an `aggregate` block, regardless of which couriers succeeded:

```php
[
    'steadfast' => [
        'success' => 10,
        'cancel' => 2,
        'total' => 12,
        'success_ratio' => 83.33,
    ],
    'pathao' => [
        'success' => 5,
        'cancel' => 1,
        'total' => 6,
        'success_ratio' => 83.33,
        'customer_rating' => 'excellent_customer', // Pathao-only, may be null
    ],
    'redx' => [
        'success' => 20,
        'cancel' => 5,
        'total' => 25,
        'success_ratio' => 80.0,
    ],
    'paperfly' => [
        'success' => 3,
        'cancel' => 1,
        'total' => 4, // total record count from Paperfly, may exceed success+cancel if some records are unclassified
        'success_ratio' => 75.0,
    ],
    'carrybee' => [
        'success' => 8,
        'cancel' => 2,
        'total' => 10,
        'success_ratio' => 80.0,
    ],
    'aggregate' => [
        'total_success' => 46,
        'total_cancel' => 11,
        'total_deliveries' => 57,
        'success_ratio' => 80.7,
        'cancel_ratio' => 19.3,
    ],
]
```

Any courier that failed reports an `error` array instead (see below) in place of its success/cancel/total block, and is simply excluded from the `aggregate` totals (only couriers that returned numeric `success`/`cancel` values are counted). Field reference:

| Field | Meaning |
|---|---|
| `success` | Number of delivered/successful orders for this phone number at this courier |
| `cancel` | Number of cancelled/returned/failed orders |
| `total` | Total order count considered (courier-specific — see per-courier notes above for Paperfly) |
| `success_ratio` | `success / total * 100`, rounded to 2 decimals; `0` if `total` is `0` |
| `customer_rating` | Pathao only — Pathao's own customer classification (e.g. `excellent_customer`, `new_customer`), or `null` |
| `aggregate.total_deliveries` | Sum of `success + cancel` across every courier that returned real data |

## Error Handling

Errors happen at two levels — a per-courier `getDeliveryStats()` call never throws; it always returns an array, either real data or an error description:

```php
// Invalid input, courier auth failure, unexpected/malformed response, etc.
[
    'error' => 'Failed to authenticate with Pathao',
    'status' => 401, // present when caused by an HTTP response
]

// Courier not configured at all (missing credentials)
[
    'error' => 'pathao is not configured',
    'message' => 'No valid credentials configured for Pathao. Set at least one account.',
]
```

At the `CourierCheckerManager::check()` level, if a service somehow throws an uncaught exception anyway, it's caught, logged via `Log::error()` (message, phone number, and stack trace), and converted into:

```php
[
    'error' => 'Service unavailable or failed to process',
    'message' => '<exception message>',
]
```

**Validation:** every phone number is validated against `^01[3-9][0-9]{8}$` before any network call is made. An invalid phone number produces an `error` result for that courier (or, if you call an individual service directly, the same `error` array) rather than throwing — this keeps the aggregation loop uninterrupted even on bad input.

**How to detect an error in your own code:**

```php
foreach (['steadfast', 'pathao', 'redx', 'paperfly', 'carrybee'] as $courier) {
    if (isset($report[$courier]['error'])) {
        // handle/log the per-courier failure
    }
}
```

## Performance: Caching and Rate Limiting

### Session/token caching (the main speed lever)

Every one of the five services caches its authenticated session per account using Laravel's default cache store, so repeat calls skip the login round-trip entirely instead of re-authenticating on every single `check()`:

| Service | What's cached | TTL |
|---|---|---|
| Steadfast | Session cookies (as a plain array, rebuilt into a Guzzle cookie jar on reuse) | ~50 min |
| Pathao | Bearer access token | Follows Pathao's own `expires_in` when present, else ~50 min |
| RedX | Bearer access token | ~50 min |
| Paperfly | Access token | ~55 min |
| Carrybee | Bearer access token + business ID | ~55 min |

Cache keys are namespaced per courier and per account (an MD5 hash of the account's username/phone), so different accounts in the same pool never collide. If a cached session turns out to be stale — the courier redirects back to a login page, or returns a `401` — it's evicted automatically and the account falls back to a fresh login on the very next attempt (not the next `check()` call — the fallback happens within the same request, so you still get a real result, just without the cache-hit speed benefit for that one call).

**In practice**: a cold call (no cache yet, or every account's cache expired) authenticates from scratch for every courier — this is the slow path. A warm call (recent cache present) reduces most couriers to a single HTTP request each. In testing, this took a full `check()` from roughly 18 seconds cold to roughly 8 seconds warm.

### Why couriers are fetched one at a time, not concurrently

An earlier version of this package attempted to run all five couriers concurrently using Guzzle's async/promise API. It was reverted after testing showed it was **unreliable, not a real improvement**: Guzzle's promise-settling mechanism (`EachPromise`) resolves a batch of promises through an internal sequential `wait()` loop, which only gives genuine parallelism for flat, single-step requests (exactly what Laravel's `Http::pool()` is designed for). It does **not** reliably parallelize the kind of multi-step, stateful flows every courier here uses (login → fetch, with account failover) — in testing, the "concurrent" version was sometimes no faster than sequential and occasionally slower. Sequential-but-cached turned out to be the reliable, correct choice. If you're extending this package and are tempted to reach for `getDeliveryStatsAsync()` to parallelize things yourself, be aware of this limitation first.

### Rate limiting

**There is no built-in throttling** — no delay between account attempts, no backoff, no cross-call cooldown tracking beyond the session caching described above. What exists is purely reactive, and it works the same way across all five couriers:

- Any HTTP-level rate limit response (e.g. a `429`) fails the `->successful()` check and is treated exactly like any other error — the pool moves to the next configured account for that courier.
- Every courier service additionally validates that a `200 OK` response actually contains the *expected* data fields before treating it as a real success. Some couriers respond `200 OK` with a throttled/unexpected body instead of a proper `429` — without this check, that would silently look like "0 deliveries" and stop the failover early. With it, a malformed `200` is correctly treated as a failed attempt and the pool advances to the next account.
- Within a single `check()` call, a courier with N pooled accounts can fire up to N attempts back-to-back if earlier ones fail — there's no pacing between them.
- Across separate `check()` calls, nothing in the package throttles call frequency — that's the calling application's responsibility. Hammering a courier repeatedly (e.g. in a test loop, or in production with high traffic) can and will get an account temporarily blocked by that courier's own abuse/bot detection — this has been observed in practice against more than one of the five couriers during development.
- There's no persistent memory of "this account is currently rate-limited" between calls — every fresh `check()` retries every account from position #1 (though a still-valid cached session will usually let a working account skip straight past the point where it would have been rate-limited on the login step).

**Practical takeaway:** the more accounts you configure per courier, the more resilient you are to any single account hitting a courier's rate limit or bot detection — since the pool automatically moves to the next one on any failure. A courier with only one configured account has nowhere to fail over to. Session caching also indirectly reduces how often you hit rate limits at all, since it means far fewer login attempts overall.

## Testing

From the package root:

```bash
composer install
composer test
```

Tests use `Http::fake()` mocked HTTP responses throughout — live courier credentials are **not** required to run the test suite, and no real network requests are made.

## Security Notes

- Do not expose raw courier credentials (usernames, passwords, tokens) in application logs, error responses, or frontend output.
- Keep `.env` out of version control — courier credentials are real merchant account logins.
- Prefer least-privilege / dedicated merchant accounts for courier integrations rather than your primary business account, where the courier allows creating sub-accounts.
- Because most integrations here authenticate as a full merchant session (not a scoped read-only API token), treat these credentials with the same care as your courier dashboard login — anyone with them can access your full merchant panel, not just fraud-check data.
- Cached sessions/tokens (see [Performance](#performance-caching-and-rate-limiting)) are stored via **your application's configured cache store** (`config/cache.php`). These are live, usable credentials for the duration of their TTL — make sure whatever cache backend you use in production (Redis, Memcached, database, etc.) is not publicly accessible and isn't shared with untrusted processes.

## Known Limitations

- **Unofficial/internal endpoints can change without notice.** Because most couriers don't expose a public "check this phone number" API, this package calls the same internal endpoints their merchant web dashboards use. If a courier changes their frontend (URL paths, response shape, auth flow), the corresponding service in this package can break until updated. This has already happened once during development (Carrybee moved its lookup endpoint from `/fraud-check/{phone}` to `/customers/{phone}` with a different phone format).
- **No built-in rate limiting/backoff** beyond account failover and session caching — see [Performance](#performance-caching-and-rate-limiting).
- **No concurrent courier fetching** — all five are queried sequentially, on purpose, after concurrency was found unreliable for this package's multi-step flows. See [Performance](#performance-caching-and-rate-limiting).
- **Redirect-following authentication is fragile in general.** Steadfast's login flow, for example, can bounce through an intermediate page before landing on the real session; the package validates the final response shape (not just HTTP status) specifically to catch this, but any courier could introduce a new intermediate step that needs similar handling.
- **Carrybee-specific:** the currently confirmed working phone lookup format is `+8801XXXXXXXXX` (URL-encoded) against `/api/v2/businesses/{businessId}/customers/{phone}`. A phone number Carrybee has no record of returns `404`, which is reported as a normal error, not a crash.
- **Paperfly's `total`** reflects Paperfly's own `totalRecords` count from their Smart Check search, which can exceed `success + cancel` if some returned records have a status the package doesn't recognize as either.
- **Some courier accounts can be flaky independent of this package.** During development, one Steadfast account intermittently required a full re-login even with a valid cached session, most likely due to that courier's own bot-detection reacting to automated traffic patterns — not something this package can fully control. Configuring multiple accounts (see [Multi-Account Failover Pools](#multi-account-failover-pools)) is the mitigation.

## Troubleshooting

- **"X account lists are misaligned for field Y"** — your `*_USERS`/`*_PASSWORDS` (or `*_PHONES`/`*_PASSWORDS`) lists for that courier don't have the same number of comma-separated entries. Count them carefully, especially for trailing commas.
- **"`<courier>` is not configured"** — that courier's env vars are empty or missing. This is expected and non-fatal if you don't intend to use that courier; every other courier still runs normally.
- **A courier that used to work now always errors** — check [Known Limitations](#known-limitations); the courier's internal endpoint or response format may have changed and the corresponding service needs updating.
- **Getting rate-limited constantly on one courier** — add more accounts to that courier's `*_USERS`/`*_PASSWORDS` lists so the pool has somewhere to fail over to (see [Multi-Account Failover Pools](#multi-account-failover-pools)).
- **Steadfast/Carrybee/Pathao returning all zeros for an account you know has history** — this usually means authentication silently failed (e.g. redirected to a login page instead of real data) rather than the account genuinely having no history. The package validates response shape specifically to catch and report this as an error instead of a false zero, but if you're extending the package yourself, watch for this failure mode.
- **A `check()` call is much slower than expected every single time (never speeds up on repeat calls)** — session caching relies on your application's cache store actually persisting between requests. If `config('cache.default')` is set to `array` (the default in some test/local setups), nothing survives past the current PHP process/request, so every call looks "cold." Use a persistent driver (`file`, `redis`, `database`, etc.) in any environment where you want the caching benefit.
- **Slow first request, faster after that** — this is expected. The first `check()` for a given account has to authenticate from scratch for every courier; subsequent calls within the cache TTL reuse those sessions. See [Performance](#performance-caching-and-rate-limiting).

## License

This package is licensed under GPL-3.0-only. See `LICENSE.md`.

## Maintainer

- Author: Rakib Uddin
- GitHub: https://github.com/devrkb21
- Repository: https://github.com/devrkb21/czbd-courier
- Support/Docs: https://coderzonebd.com/contact
