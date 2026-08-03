<?php

namespace Czbd\CourierChecker\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Czbd\CourierChecker\Helpers\CourierDataValidator;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;

use Czbd\CourierChecker\Contracts\CourierServiceInterface;

/**
 * Class RedxService
 *
 * Handles API interactions with RedX courier to fetch delivery statistics.
 * Uses caching to store the access token and prevent hitting login rate limits.
 *
 * @package Czbd\CourierChecker\Services
 */
readonly class RedxService implements CourierServiceInterface
{
    /**
     * @var int The token expiration time in minutes.
     */
    protected int $cacheMinutes;

    /**
     * @var array<int, array<string, string>> Pool of RedX accounts used for failover.
     */
    protected array $accounts;

    /**
     * @var string|null Optional global/specific proxy for RedX requests.
     */
    protected ?string $proxy;

    /**
     * RedxService constructor.
     *
     * Validates configuration and prepares the authentication details.
     */
    public function __construct()
    {
        $this->cacheMinutes = 50;

        $this->accounts = CourierDataValidator::buildCredentialPool('Redx', [
            'phone' => (string) config('courier-checker.redx.phones'),
            'password' => (string) config('courier-checker.redx.passwords'),
        ]);

        foreach ($this->accounts as $account) {
            CourierDataValidator::checkBdMobile($account['phone']);
        }

        $this->proxy = CourierDataValidator::resolveCourierProxy('redx');
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
     * Resolve per-account cache key.
     */
    protected function cacheKeyForPhone(string $phone): string
    {
        return 'redx_access_token_' . md5($phone);
    }

    /**
     * Retrieve a valid RedX access token from the cache, or authenticate to get a new one.
     *
     * @return PromiseInterface<string|null>
     */
    protected function getAccessTokenAsync(array $account): PromiseInterface
    {
        $cacheKey = $this->cacheKeyForPhone($account['phone']);

        $token = Cache::get($cacheKey);
        if ($token) {
            return Create::promiseFor($token);
        }

        return $this->httpClient()
            ->async()
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                'Accept' => 'application/json, text/plain, */*',
            ])
            ->post('https://api.redx.com.bd/v4/auth/login', [
                'phone' => '88' . $account['phone'],
                'password' => $account['password'],
            ])
            ->then(function ($response) use ($cacheKey) {
                if (!$response->successful()) {
                    return null;
                }

                $token = $response->json('data.accessToken');
                if ($token) {
                    Cache::put($cacheKey, $token, now()->addMinutes($this->cacheMinutes));
                }

                return $token;
            });
    }

    /**
     * Execute the RedX flow for a single account.
     */
    protected function attemptAccountAsync(string $queryPhone, array $account): PromiseInterface
    {
        return $this->getAccessTokenAsync($account)->then(function (?string $accessToken) use ($queryPhone, $account) {
            if (!$accessToken) {
                return ['error' => 'Login failed or unable to get access token from Redx'];
            }

            return $this->httpClient()
                ->async()
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                    'Accept' => 'application/json, text/plain, */*',
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $accessToken,
                ])
                ->get("https://redx.com.bd/api/redx_se/admin/parcel/customer-success-return-rate?phoneNumber=88{$queryPhone}")
                ->then(function ($response) use ($account) {
                    if ($response->successful()) {
                        $object = $response->json();

                        // A rate-limited/throttled request can still come back
                        // as HTTP 200 with an unexpected body instead of the
                        // real payload. Require 'data' to actually be present
                        // so that case is treated as a failed attempt instead
                        // of a false "0 success / 0 cancel".
                        if (is_array($object) && array_key_exists('data', $object)) {
                            $success = (int)($object['data']['deliveredParcels'] ?? 0);
                            $total = (int)($object['data']['totalParcels'] ?? 0);
                            $cancel = max(0, $total - $success);
                            $success_ratio = $total > 0 ? round(($success / $total) * 100, 2) : 0;

                            return [
                                'success' => $success,
                                'cancel' => $cancel,
                                'total' => $total,
                                'success_ratio' => $success_ratio,
                            ];
                        }

                        return ['error' => 'Unexpected response from Redx (possibly rate limited)', 'status' => $response->status()];
                    }

                    if ($response->status() === 401) {
                        Cache::forget($this->cacheKeyForPhone($account['phone']));
                        return ['error' => 'Access token expired or invalid for Redx. Trying next account.', 'status' => 401];
                    }

                    return [
                        'success' => 0,
                        'cancel' => 0,
                        'total' => 0,
                        'success_ratio' => 0,
                        'error' => 'Threshold hit, wait a minute for Redx',
                        'status' => $response->status(),
                    ];
                });
        });
    }

    /**
     * Try each pooled account in order, stopping at the first clean success.
     */
    protected function tryAccountsAsync(string $queryPhone, int $index, array $lastError): PromiseInterface
    {
        if (!isset($this->accounts[$index])) {
            return Create::promiseFor($lastError);
        }

        return $this->attemptAccountAsync($queryPhone, $this->accounts[$index])
            ->then(function (array $result) use ($queryPhone, $index) {
                if (!isset($result['error'])) {
                    return $result;
                }

                return $this->tryAccountsAsync($queryPhone, $index + 1, $result);
            })
            ->otherwise(function ($reason) use ($queryPhone, $index) {
                return $this->tryAccountsAsync($queryPhone, $index + 1, [
                    'error' => 'An error occurred while processing Redx request',
                    'message' => $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason,
                ]);
            });
    }

    /**
     * Fetch delivery statistics from RedX for the given phone number,
     * without blocking. Lets CourierCheckerManager run every courier
     * concurrently instead of one after another.
     *
     * @param string $queryPhone The Bangladeshi mobile number to check.
     * @return PromiseInterface<array>
     */
    public function getDeliveryStatsAsync(string $queryPhone): PromiseInterface
    {
        try {
            CourierDataValidator::checkBdMobile($queryPhone);
        } catch (\Exception $e) {
            return Create::promiseFor([
                'error' => 'An error occurred while processing Redx request',
                'message' => $e->getMessage(),
            ]);
        }

        return $this->tryAccountsAsync($queryPhone, 0, ['error' => 'Login failed or unable to get access token from Redx']);
    }

    /**
     * Fetch delivery statistics from RedX for the given phone number.
     *
     * @param string $queryPhone The Bangladeshi mobile number to check.
     * @return array Contains 'success', 'cancel', 'total', and 'success_ratio'.
     *               Returns an array with an 'error' key if the API request fails.
     */
    public function getDeliveryStats(string $queryPhone): array
    {
        return $this->getDeliveryStatsAsync($queryPhone)->wait();
    }
}
