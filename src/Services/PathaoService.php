<?php

namespace Czbd\CourierChecker\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Czbd\CourierChecker\Helpers\CourierDataValidator;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;

use Czbd\CourierChecker\Contracts\CourierServiceInterface;

/**
 * Class PathaoService
 *
 * Handles API interactions with Pathao courier to fetch delivery statistics
 * for a specific customer phone number.
 *
 * @package Czbd\CourierChecker\Services
 */
readonly class PathaoService implements CourierServiceInterface
{
    /**
     * @var int Fallback cached token TTL in minutes, used when Pathao's
     *          login response doesn't include an expires_in value.
     */
    protected const DEFAULT_CACHE_MINUTES = 50;

    /**
     * @var array<int, array<string, string>> Pool of Pathao accounts used for failover.
     */
    protected array $accounts;

    /**
     * @var string|null Optional global/specific proxy for Pathao requests.
     */
    protected ?string $proxy;

    /**
     * PathaoService constructor.
     *
     * Validates configuration and initializes the API credentials.
     */
    public function __construct()
    {
        $this->accounts = CourierDataValidator::buildCredentialPool('Pathao', [
            'user' => (string) config('courier-checker.pathao.users'),
            'password' => (string) config('courier-checker.pathao.passwords'),
        ]);

        $this->proxy = CourierDataValidator::resolveCourierProxy('pathao');
    }

    /**
     * Build HTTP client with optional proxy options.
     */
    protected function httpClient(array $extraOptions = []): PendingRequest
    {
        $options = array_replace_recursive(
            CourierDataValidator::proxyOptions($this->proxy),
            $extraOptions
        );

        return Http::withOptions($options);
    }

    /**
     * Resolve per-account cache key for the cached access token.
     */
    protected function cacheKeyForUser(string $user): string
    {
        return 'pathao_access_token_' . md5($user);
    }

    /**
     * Fetch customer data for a phone number using an already-obtained access token.
     */
    protected function fetchDeliveryDataAsync(string $phoneNumber, string $accessToken): PromiseInterface
    {
        return $this->httpClient()
            ->async()
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $accessToken,
            ])
            ->post('https://merchant.pathao.com/api/v1/user/success', [
                'phone' => $phoneNumber,
            ])
            ->then(function ($responseAuth) {
                if (!$responseAuth->successful()) {
                    return ['error' => 'Failed to retrieve customer data from Pathao', 'status' => $responseAuth->status()];
                }

                $object = $responseAuth->json();

                // A rate-limited/throttled request (or an expired cached
                // token) can still come back as HTTP 200 with an unexpected
                // body instead of the real payload. Require the 'data' key
                // to actually be present so that case is treated as a
                // failed attempt instead of a false "0 success / 0 cancel".
                if (!is_array($object) || !array_key_exists('data', $object)) {
                    return ['error' => 'Unexpected response from Pathao (possibly rate limited)', 'status' => $responseAuth->status()];
                }

                $payload = (array)($object['data'] ?? []);
                $customer = (array)($payload['customer'] ?? []);
                $customerRating = $payload['customer_rating'] ?? null;

                if ($customerRating !== null) {
                    $customerRating = trim((string)$customerRating);
                    if ($customerRating === '') {
                        $customerRating = null;
                    }
                }

                $success = (int)($customer['successful_delivery'] ?? 0);
                $total = (int)($customer['total_delivery'] ?? 0);
                $cancel = max(0, $total - $success);
                $success_ratio = $total > 0 ? round(($success / $total) * 100, 2) : 0;

                return [
                    'success' => $success,
                    'cancel' => $cancel,
                    'total' => $total,
                    'success_ratio' => $success_ratio,
                    'customer_rating' => $customerRating,
                ];
            });
    }

    /**
     * Log in for an account, cache the resulting access token on success,
     * then fetch the delivery data.
     */
    protected function freshLoginAndFetchAsync(string $phoneNumber, array $account): PromiseInterface
    {
        return $this->httpClient()
            ->async()
            ->post('https://merchant.pathao.com/api/v1/login', [
                'username' => $account['user'],
                'password' => $account['password'],
            ])
            ->then(function ($response) use ($phoneNumber, $account) {
                if (!$response->successful()) {
                    return ['error' => 'Failed to authenticate with Pathao', 'status' => $response->status()];
                }

                $data = $response->json();
                $accessToken = trim($data['access_token'] ?? '');

                if (!$accessToken) {
                    return ['error' => 'No access token received from Pathao'];
                }

                // Respect Pathao's own expiry when provided (minus a 5 minute
                // safety buffer), otherwise fall back to a conservative TTL.
                $cacheMinutes = self::DEFAULT_CACHE_MINUTES;
                if (isset($data['expires_in']) && is_numeric($data['expires_in'])) {
                    $cacheMinutes = max(1, intdiv(max(60, (int)$data['expires_in'] - 300), 60));
                }

                return $this->fetchDeliveryDataAsync($phoneNumber, $accessToken)
                    ->then(function (array $result) use ($account, $accessToken, $cacheMinutes) {
                        if (!isset($result['error'])) {
                            Cache::put($this->cacheKeyForUser($account['user']), $accessToken, now()->addMinutes($cacheMinutes));
                        }

                        return $result;
                    });
            });
    }

    /**
     * Execute Pathao flow for a single account: try the cached access token
     * first (skipping the login round-trip entirely), and only fall back to
     * a fresh login if there's no cached token or it's no longer valid.
     */
    protected function attemptAccountAsync(string $phoneNumber, array $account): PromiseInterface
    {
        $cacheKey = $this->cacheKeyForUser($account['user']);
        $cachedToken = Cache::get($cacheKey);

        if (is_string($cachedToken) && $cachedToken !== '') {
            return $this->fetchDeliveryDataAsync($phoneNumber, $cachedToken)
                ->then(function (array $result) use ($phoneNumber, $account, $cacheKey) {
                    if (!isset($result['error'])) {
                        return $result;
                    }

                    // Cached token is no longer valid; evict it and fall
                    // back to a fresh login instead of failing outright.
                    Cache::forget($cacheKey);

                    return $this->freshLoginAndFetchAsync($phoneNumber, $account);
                });
        }

        return $this->freshLoginAndFetchAsync($phoneNumber, $account);
    }

    /**
     * Try each pooled account in order, stopping at the first clean success.
     */
    protected function tryAccountsAsync(string $phoneNumber, int $index, array $lastError): PromiseInterface
    {
        if (!isset($this->accounts[$index])) {
            return Create::promiseFor($lastError);
        }

        return $this->attemptAccountAsync($phoneNumber, $this->accounts[$index])
            ->then(function (array $result) use ($phoneNumber, $index) {
                if (!isset($result['error'])) {
                    return $result;
                }

                return $this->tryAccountsAsync($phoneNumber, $index + 1, $result);
            })
            ->otherwise(function ($reason) use ($phoneNumber, $index) {
                return $this->tryAccountsAsync($phoneNumber, $index + 1, [
                    'error' => 'An error occurred while processing Pathao request',
                    'message' => $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason,
                ]);
            });
    }

    /**
     * Fetch delivery statistics from Pathao for the given phone number,
     * without blocking. Lets CourierCheckerManager run every courier
     * concurrently instead of one after another.
     *
     * @param string $phoneNumber The Bangladeshi mobile number to check.
     * @return PromiseInterface<array>
     */
    public function getDeliveryStatsAsync(string $phoneNumber): PromiseInterface
    {
        try {
            CourierDataValidator::checkBdMobile($phoneNumber);
        } catch (\Exception $e) {
            return Create::promiseFor([
                'error' => 'An error occurred while processing Pathao request',
                'message' => $e->getMessage(),
            ]);
        }

        return $this->tryAccountsAsync($phoneNumber, 0, ['error' => 'Failed to retrieve customer data from Pathao']);
    }

    /**
     * Fetch delivery statistics from Pathao for the given phone number.
     *
     * Successful logins are cached (respecting Pathao's own token expiry
     * when provided) so repeat calls can skip the login round-trip entirely.
     *
     * @param string $phoneNumber The Bangladeshi mobile number to check.
     * @return array Contains 'success', 'cancel', 'total', 'success_ratio', and optional 'customer_rating'.
     *               In case of an error, returns an array with an 'error' key.
     */
    public function getDeliveryStats(string $phoneNumber): array
    {
        return $this->getDeliveryStatsAsync($phoneNumber)->wait();
    }
}
