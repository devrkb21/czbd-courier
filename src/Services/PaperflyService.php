<?php

namespace Czbd\CourierChecker\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Czbd\CourierChecker\Contracts\CourierServiceInterface;
use Czbd\CourierChecker\Helpers\CourierDataValidator;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * Class PaperflyService
 *
 * Handles API interactions with Paperfly courier to fetch delivery statistics
 * for a given customer phone number.
 *
 * @package Czbd\CourierChecker\Services
 */
readonly class PaperflyService implements CourierServiceInterface
{
    /**
     * Base URL for the Paperfly Merchant Reactor API.
     */
    protected const BASE_URL = 'https://go-app.paperfly.com.bd/merchant/api/react';

    /**
     * Cached access token TTL in minutes, since Paperfly tokens may expire.
     */
    protected const CACHE_MINUTES = 55;

    /**
      * @var array<int, array<string, string>> Pool of Paperfly accounts used for failover.
     */
     protected array $accounts;

    /**
      * @var string|null Optional global/specific proxy for Paperfly requests.
     */
     protected ?string $proxy;

    /**
     * PaperflyService constructor.
     *
     * Validates configuration and initializes the required credentials.
     */
    public function __construct()
    {
        $this->accounts = CourierDataValidator::buildCredentialPool('Paperfly', [
            'user' => (string) config('courier-checker.paperfly.users'),
            'password' => (string) config('courier-checker.paperfly.passwords'),
        ]);

        $this->proxy = CourierDataValidator::resolveCourierProxy('paperfly');
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
        return 'paperfly_access_token_' . md5($user);
    }

    /**
     * Get the authentication token, either from cache or by logging in.
     *
     * @return PromiseInterface<string> Rejects if login fails.
     */
    protected function getTokenAsync(array $account): PromiseInterface
    {
        $cacheKey = $this->cacheKeyForUser($account['user']);

        $cached = Cache::get($cacheKey);
        if ($cached) {
            return Create::promiseFor($cached);
        }

        return $this->httpClient()
            ->async()
            ->post(self::BASE_URL . "/authentication/login_using_password.php", [
                'username' => $account['user'],
                'password' => $account['password'],
            ])
            ->then(function ($response) use ($cacheKey) {
                if ($response->failed() || !$response->json('token')) {
                    throw new \Exception("Paperfly Login Failed: " . $response->body());
                }

                $token = $response->json('token');
                Cache::put($cacheKey, $token, now()->addMinutes(self::CACHE_MINUTES));

                return $token;
            });
    }

    /**
     * Execute the Paperfly flow for a single account.
     */
    protected function attemptAccountAsync(string $phoneNumber, array $account): PromiseInterface
    {
        return $this->getTokenAsync($account)
            ->then(function (string $token) use ($phoneNumber) {
                // Perform the Smart Check search
                return $this->httpClient()
                    ->async()
                    ->withToken($token)
                    ->withHeaders(['Accept' => 'application/json, text/plain, */*'])
                    ->post(self::BASE_URL . "/smart-check/list.php", [
                        'search_text' => $phoneNumber,
                        'limit' => 50,
                        'page' => 1,
                    ])
                    ->then(function ($response) {
                        if ($response->failed()) {
                            return ['error' => 'Failed to fetch fraud data from Paperfly', 'status' => $response->status()];
                        }

                        $data = $response->json();

                        // A rate-limited/throttled request can still come back as
                        // HTTP 200 with an unexpected body instead of the real
                        // payload. Require 'totalRecords' to actually be present
                        // so that case is treated as a failed attempt instead of
                        // a false "0 success / 0 cancel".
                        if (!is_array($data) || !array_key_exists('totalRecords', $data)) {
                            return ['error' => 'Unexpected response from Paperfly (possibly rate limited)', 'status' => $response->status()];
                        }

                        // Total records tells us how many associated history items exist
                        $total = (int) ($data['totalRecords'] ?? 0);
                        $records = $data['records'] ?? [];

                        $success = 0;
                        $cancel = 0;

                        // Attempt to derive success/cancel from records if available.
                        // If the paperfly `records` array yields clear status information,
                        // this loops through and categorizes them.
                        if (is_array($records) && count($records) > 0) {
                            foreach ($records as $record) {
                                $status = strtolower($record['status'] ?? '');
                                if (str_contains($status, 'delivered') || str_contains($status, 'success')) {
                                    $success++;
                                } elseif (str_contains($status, 'return') || str_contains($status, 'cancel') || str_contains($status, 'fail')) {
                                    $cancel++;
                                }
                            }
                        }

                        $success_ratio = 0;
                        if (($success + $cancel) > 0) {
                            $totalParsed = $success + $cancel;
                            $success_ratio = round(($success / $totalParsed) * 100, 2);
                        }

                        return [
                            'success' => $success,
                            'cancel' => $cancel,
                            'total'  => $total,
                            'success_ratio' => $success_ratio,
                        ];
                    });
            })
            ->otherwise(function ($reason) {
                return [
                    'error' => 'An error occurred while processing Paperfly request',
                    'message' => $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason,
                ];
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
            });
    }

    /**
     * Fetch delivery statistics from Paperfly for the given phone number,
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
                'error' => 'An error occurred while processing Paperfly request',
                'message' => $e->getMessage(),
            ]);
        }

        return $this->tryAccountsAsync($phoneNumber, 0, ['error' => 'Failed to fetch fraud data from Paperfly']);
    }

    /**
     * Fetch delivery statistics from Paperfly for the given phone number.
     *
     * @param string $phoneNumber The Bangladeshi mobile number to check.
     * @return array Contains 'success', 'cancel', 'total', and 'success_ratio'.
     *               Returns an array with an 'error' key if any step fails.
     */
    public function getDeliveryStats(string $phoneNumber): array
    {
        return $this->getDeliveryStatsAsync($phoneNumber)->wait();
    }
}
