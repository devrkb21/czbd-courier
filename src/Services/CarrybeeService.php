<?php

namespace Czbd\CourierChecker\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Czbd\CourierChecker\Contracts\CourierServiceInterface;
use Czbd\CourierChecker\Helpers\CourierDataValidator;
use GuzzleHttp\Cookie\CookieJar;

/**
 * Class CarrybeeService
 *
 * Handles API interactions with Carrybee courier to fetch delivery statistics.
 * Uses a CookieJar to maintain session state across NextAuth endpoints.
 *
 * @package Czbd\CourierChecker\Services
 */
class CarrybeeService implements CourierServiceInterface
{
    /**
     * @var int The token expiration time in minutes.
     */
    protected int $cacheMinutes = 55;

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
            'phone' => (string) config('courier-checker.carrybee.phone'),
            'password' => (string) config('courier-checker.carrybee.password'),
        ], [
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
     * Retrieve a valid Carrybee access token from the cache or authenticate.
     *
        * @return array<string, mixed>|null Access token and business id, or null on failure.
     */
    protected function getAccessTokenAndBusinessId(array $account): ?array
    {
        $cacheKey = $this->cacheKeyForPhone($account['phone']);

        // Use cached token if available
        $tokenData = Cache::get($cacheKey);
        if ($tokenData && isset($tokenData['accessToken'], $tokenData['businessId'])) {
            return $tokenData;
        }

        $cookieJar = new CookieJar();
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) width/1920 height/1080',
            'Accept' => 'application/json',
            'Referer' => 'https://merchant.carrybee.com/login',
        ];

        // Step 1: Get CSRF Token
        $csrfResponse = $this->httpClient(['cookies' => $cookieJar])
            ->withHeaders($headers)
            ->get('https://merchant.carrybee.com/api/auth/csrf');

        if (!$csrfResponse->successful() || !$csrfResponse->json('csrfToken')) {
            return null;
        }

        $csrfToken = $csrfResponse->json('csrfToken');

        // Step 2: Callback Login
        // NextAuth expects form data usually or JSON. The screenshot shows Form Data, so we use asForm()
        $loginResponse = $this->httpClient(['cookies' => $cookieJar])
            ->withHeaders($headers)
            ->asForm()
            ->post('https://merchant.carrybee.com/api/auth/callback/login?', [
                'phone' => '+88' . ltrim($account['phone'], '+88'), // ensure +88 prefix if needed, screenshot shows +8801...
                'password' => $account['password'],
                'csrfToken' => $csrfToken,
                'callbackUrl' => 'https://merchant.carrybee.com/login',
            ]);

        if (!$loginResponse->successful()) {
            return null;
        }

        // Step 3: Get Session
        $sessionResponse = $this->httpClient(['cookies' => $cookieJar])
            ->withHeaders($headers)
            ->get('https://merchant.carrybee.com/api/auth/session');

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

        Cache::put($cacheKey, $tokenData, now()->addMinutes($this->cacheMinutes));

        return $tokenData;
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
        try {
            CourierDataValidator::checkBdMobile($phoneNumber);

            $lastError = ['error' => 'Login failed or unable to get access token from Carrybee'];

            foreach ($this->accounts as $account) {
                $authData = $this->getAccessTokenAndBusinessId($account);

                if (!$authData) {
                    $lastError = ['error' => 'Login failed or unable to get access token from Carrybee'];
                    continue;
                }

                $accessToken = $authData['accessToken'];
                $businessId = $authData['businessId'];

                // Format phone to 01xxxxxxxxx as per the screenshot URL ending.
                $cleanPhone = preg_replace('/^(?:\+?88)?(01[3-9]\d{8})$/', '$1', $phoneNumber);

                if (empty($cleanPhone) || strlen($cleanPhone) !== 11) {
                    // Fallback to original if regex failed
                    $cleanPhone = $phoneNumber;
                }

                $response = $this->httpClient()->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) width/1920 height/1080',
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $accessToken,
                ])->get("https://api-merchant.carrybee.com/api/v2/businesses/{$businessId}/fraud-check/{$cleanPhone}");

                if ($response->successful() && !$response->json('error')) {
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
                    $lastError = ['error' => 'Access token expired or invalid for Carrybee. Trying next account.', 'status' => 401];
                    continue;
                }

                $lastError = [
                    'success' => 0,
                    'cancel' => 0,
                    'total' => 0,
                    'success_ratio' => 0,
                    'error' => 'Failed to fetch from Carrybee',
                    'status' => $response->status(),
                ];
            }

            return $lastError;
        } catch (\Exception $e) {
            return [
                'error' => 'An error occurred while processing Carrybee request',
                'message' => $e->getMessage()
            ];
        }
    }
}
