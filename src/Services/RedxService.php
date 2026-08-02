<?php

namespace Czbd\CourierChecker\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Czbd\CourierChecker\Helpers\CourierDataValidator;

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
     * Retrieve a valid RedX access token from the cache or authenticate to get a new one.
     *
     * @return string|null The access token, or null on failure.
     */
    protected function getAccessToken(array $account): ?string
    {
        $cacheKey = $this->cacheKeyForPhone($account['phone']);

        // Use cached token if available
        $token = Cache::get($cacheKey);
        if ($token) {
            return $token;
        }

        // Request new token from RedX
        $response = $this->httpClient()->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'Accept' => 'application/json, text/plain, */*',
        ])->post('https://api.redx.com.bd/v4/auth/login', [
            'phone' => '88' . $account['phone'],
            'password' => $account['password'],
        ]);

        if (!$response->successful()) {
            return null;
        }

        $token = $response->json('data.accessToken');
        if ($token) {
            Cache::put($cacheKey, $token, now()->addMinutes($this->cacheMinutes));
        }

        return $token;
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
        try {
            CourierDataValidator::checkBdMobile($queryPhone);

            $lastError = ['error' => 'Login failed or unable to get access token from Redx'];

            foreach ($this->accounts as $account) {
                $accessToken = $this->getAccessToken($account);

                if (!$accessToken) {
                    $lastError = ['error' => 'Login failed or unable to get access token from Redx'];
                    continue;
                }

                $response = $this->httpClient()->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                    'Accept' => 'application/json, text/plain, */*',
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $accessToken,
                ])->get("https://redx.com.bd/api/redx_se/admin/parcel/customer-success-return-rate?phoneNumber=88{$queryPhone}");

                if ($response->successful()) {
                    $object = $response->json();

                    // A rate-limited/throttled request can still come back as
                    // HTTP 200 with an unexpected body instead of the real
                    // payload. Require 'data' to actually be present so that
                    // case is treated as a failed account (and the pool
                    // moves on) instead of a false "0 success / 0 cancel".
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

                    $lastError = ['error' => 'Unexpected response from Redx (possibly rate limited)', 'status' => $response->status()];
                    continue;
                }

                if ($response->status() === 401) {
                    Cache::forget($this->cacheKeyForPhone($account['phone']));
                    $lastError = ['error' => 'Access token expired or invalid for Redx. Trying next account.', 'status' => 401];
                    continue;
                }

                $lastError = [
                    'success' => 0,
                    'cancel' => 0,
                    'total' => 0,
                    'success_ratio' => 0,
                    'error' => 'Threshold hit, wait a minute for Redx',
                    'status' => $response->status(),
                ];
            }

            return $lastError;
        } catch (\Exception $e) {
            return [
                'error' => 'An error occurred while processing Redx request',
                'message' => $e->getMessage()
            ];
        }
    }
}
