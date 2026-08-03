<?php

namespace Czbd\CourierChecker\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Czbd\CourierChecker\Contracts\CourierServiceInterface;
use Czbd\CourierChecker\Helpers\CourierDataValidator;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * Class CarrybeeService
 *
 * Handles API interactions with Carrybee courier to fetch delivery statistics.
 * Uses a CookieJar to maintain session state across NextAuth endpoints.
 *
 * @package Czbd\CourierChecker\Services
 */
readonly class CarrybeeService implements CourierServiceInterface
{
    /**
     * The token expiration time in minutes.
     */
    protected const CACHE_MINUTES = 55;

    /**
     * @var array<int, array<string, string>> Pool of Carrybee accounts used for failover.
     */
    protected array $accounts;

    /**
     * @var string|null Optional global/specific proxy for Carrybee requests.
     */
    protected ?string $proxy;

    /**
     * CarrybeeService constructor.
     *
     * Validates configuration and prepares the authentication details.
     */
    public function __construct()
    {
        $this->accounts = CourierDataValidator::buildCredentialPool('Carrybee', [
            'phone' => (string) config('courier-checker.carrybee.phones'),
            'password' => (string) config('courier-checker.carrybee.passwords'),
        ]);

        $this->proxy = CourierDataValidator::resolveCourierProxy('carrybee');
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
        return 'carrybee_access_token_' . md5($phone);
    }

    /**
     * Retrieve a valid Carrybee access token from the cache, or authenticate.
     *
     * @return PromiseInterface<array{accessToken: string, businessId: string}|null>
     */
    protected function getAccessTokenAndBusinessIdAsync(array $account): PromiseInterface
    {
        $cacheKey = $this->cacheKeyForPhone($account['phone']);

        $tokenData = Cache::get($cacheKey);
        if ($tokenData && isset($tokenData['accessToken'], $tokenData['businessId'])) {
            return Create::promiseFor($tokenData);
        }

        $cookieJar = new CookieJar();
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) width/1920 height/1080',
            'Accept' => 'application/json',
            'Referer' => 'https://merchant.carrybee.com/login',
        ];

        // Step 1: Get CSRF Token
        return $this->httpClient(['cookies' => $cookieJar])
            ->async()
            ->withHeaders($headers)
            ->get('https://merchant.carrybee.com/api/auth/csrf')
            ->then(function ($csrfResponse) use ($account, $cookieJar, $headers, $cacheKey) {
                if (!$csrfResponse->successful() || !$csrfResponse->json('csrfToken')) {
                    return null;
                }

                $csrfToken = $csrfResponse->json('csrfToken');

                // Step 2: Callback Login
                // NextAuth expects form data usually or JSON. The screenshot shows Form Data, so we use asForm()
                return $this->httpClient(['cookies' => $cookieJar])
                    ->async()
                    ->withHeaders($headers)
                    ->asForm()
                    ->post('https://merchant.carrybee.com/api/auth/callback/login', [
                        'phone' => '+88' . ltrim($account['phone'], '+88'), // ensure +88 prefix if needed, screenshot shows +8801...
                        'password' => $account['password'],
                        'csrfToken' => $csrfToken,
                        'callbackUrl' => 'https://merchant.carrybee.com/login',
                    ])
                    ->then(function ($loginResponse) use ($cookieJar, $headers, $cacheKey) {
                        if (!$loginResponse->successful()) {
                            return null;
                        }

                        // Step 3: Get Session
                        return $this->httpClient(['cookies' => $cookieJar])
                            ->async()
                            ->withHeaders($headers)
                            ->get('https://merchant.carrybee.com/api/auth/session')
                            ->then(function ($sessionResponse) use ($cacheKey) {
                                if (!$sessionResponse->successful() || !$sessionResponse->json('accessToken')) {
                                    return null;
                                }

                                $accessToken = $sessionResponse->json('accessToken');
                                $businessId = $sessionResponse->json('user.selectedBusinessId');

                                if (!$businessId) {
                                    return null;
                                }

                                $tokenData = [
                                    'accessToken' => $accessToken,
                                    'businessId'  => $businessId,
                                ];

                                Cache::put($cacheKey, $tokenData, now()->addMinutes(self::CACHE_MINUTES));

                                return $tokenData;
                            });
                    });
            });
    }

    /**
     * Execute the Carrybee flow for a single account.
     */
    protected function attemptAccountAsync(string $phoneNumber, array $account): PromiseInterface
    {
        return $this->getAccessTokenAndBusinessIdAsync($account)->then(function (?array $authData) use ($phoneNumber, $account) {
            if (!$authData) {
                return ['error' => 'Login failed or unable to get access token from Carrybee'];
            }

            $accessToken = $authData['accessToken'];
            $businessId = $authData['businessId'];

            // Format phone to +8801xxxxxxxxx as expected by the customers endpoint.
            $localPhone = preg_replace('/^(?:\+?88)?(01[3-9]\d{8})$/', '$1', $phoneNumber);

            if (empty($localPhone) || strlen($localPhone) !== 11) {
                // Fallback to original if regex failed
                $localPhone = ltrim($phoneNumber, '+');
                $localPhone = preg_replace('/^88/', '', $localPhone);
            }

            $e164Phone = '+88' . $localPhone;

            return $this->httpClient()
                ->async()
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) width/1920 height/1080',
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $accessToken,
                ])
                ->get("https://api-merchant.carrybee.com/api/v2/businesses/{$businessId}/customers/" . rawurlencode($e164Phone))
                ->then(function ($response) use ($account) {
                    // A rate-limited/throttled request can still come back as
                    // HTTP 200 with an unexpected body instead of the real
                    // payload. Require 'data' to actually be a real array so
                    // that case is treated as a failed attempt instead of a
                    // false "0 success / 0 cancel".
                    if ($response->successful() && !$response->json('error') && is_array($response->json('data'))) {
                        $data = $response->json('data');

                        $total = (int) ($data['total_order'] ?? 0);
                        $cancel = (int) ($data['cancelled_order'] ?? 0);
                        $success = max(0, $total - $cancel);
                        $success_ratio = (float) ($data['success_rate'] ?? 0);

                        if ($total > 0 && empty($data['success_rate'])) {
                            $success_ratio = round(($success / $total) * 100, 2);
                        }

                        return [
                            'success' => $success,
                            'cancel' => $cancel,
                            'total' => $total,
                            'success_ratio' => $success_ratio,
                        ];
                    }

                    if ($response->status() === 401) {
                        Cache::forget($this->cacheKeyForPhone($account['phone']));
                        return ['error' => 'Access token expired or invalid for Carrybee. Trying next account.', 'status' => 401];
                    }

                    return [
                        'success' => 0,
                        'cancel' => 0,
                        'total' => 0,
                        'success_ratio' => 0,
                        'error' => 'Failed to fetch from Carrybee',
                        'status' => $response->status(),
                    ];
                });
        });
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
                    'error' => 'An error occurred while processing Carrybee request',
                    'message' => $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason,
                ]);
            });
    }

    /**
     * Fetch delivery statistics from Carrybee for the given phone number,
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
                'error' => 'An error occurred while processing Carrybee request',
                'message' => $e->getMessage(),
            ]);
        }

        return $this->tryAccountsAsync($phoneNumber, 0, ['error' => 'Login failed or unable to get access token from Carrybee']);
    }

    /**
     * Fetch delivery statistics from Carrybee for the given phone number.
     *
     * @param string $phoneNumber The Bangladeshi mobile number to check.
     * @return array Contains 'success', 'cancel', 'total', and 'success_ratio'.
     *               Returns an array with an 'error' key if the API request fails.
     */
    public function getDeliveryStats(string $phoneNumber): array
    {
        return $this->getDeliveryStatsAsync($phoneNumber)->wait();
    }
}
