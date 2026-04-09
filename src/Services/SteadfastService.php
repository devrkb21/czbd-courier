<?php

namespace Czbd\CourierChecker\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Czbd\CourierChecker\Helpers\CourierDataValidator;

use Czbd\CourierChecker\Contracts\CourierServiceInterface;

/**
 * Class SteadfastService
 *
 * Handles API interactions with Steadfast courier to fetch delivery statistics
 * for a given customer phone number via their web interface.
 *
 * @package Czbd\CourierChecker\Services
 */
readonly class SteadfastService implements CourierServiceInterface
{
    /**
     * @var array<int, array<string, string>> Pool of Steadfast accounts used for failover.
     */
    protected array $accounts;

    /**
     * @var string|null Optional global/specific proxy for Steadfast requests.
     */
    protected ?string $proxy;

    /**
     * SteadfastService constructor.
     *
     * Validates configuration and initializes the required credentials.
     */
    public function __construct()
    {
        $this->accounts = CourierDataValidator::buildCredentialPool('Steadfast', [
            'user' => (string) config('courier-checker.steadfast.user'),
            'password' => (string) config('courier-checker.steadfast.password'),
        ], [
            'user' => (string) config('courier-checker.steadfast.users'),
            'password' => (string) config('courier-checker.steadfast.passwords'),
        ]);

        $this->proxy = CourierDataValidator::resolveCourierProxy('steadfast');
    }

    /**
     * Build a shared HTTP client that tunnels requests through the BD proxy gateway.
     */
    protected function proxyClient(array $extraOptions = []): PendingRequest
    {
        $options = array_replace_recursive(
            CourierDataValidator::proxyOptions($this->proxy),
            $extraOptions
        );

        return Http::withOptions($options);
    }

    /**
     * Execute Steadfast flow for a single account.
     *
     * @param string $phoneNumber
     * @param array<string, string> $account
     * @return array
     */
    protected function getDeliveryStatsForAccount(string $phoneNumber, array $account): array
    {
        // Step 1: Fetch login page
        $response = $this->proxyClient()->get('https://steadfast.com.bd/login');

        // Extract CSRF token
        preg_match('/<input type="hidden" name="_token" value="(.*?)"/', $response->body(), $matches);
        $token = $matches[1] ?? null;

        if (!$token) {
            return ['error' => 'CSRF token not found for Steadfast login'];
        }

        // Convert CookieJar to array
        $rawCookies = $response->cookies();
        $cookiesArray = [];
        foreach ($rawCookies->toArray() as $cookie) {
            $cookiesArray[$cookie['Name']] = $cookie['Value'];
        }

        // Step 2: Log in
        $loginResponse = $this->proxyClient()
            ->withCookies($cookiesArray, 'steadfast.com.bd')
            ->asForm()
            ->post('https://steadfast.com.bd/login', [
                '_token' => $token,
                'email' => $account['user'],
                'password' => $account['password'],
            ]);

        if (!($loginResponse->successful() || $loginResponse->redirect())) {
            return ['error' => 'Login to Steadfast failed', 'status' => $loginResponse->status()];
        }

        // Rebuild cookies after login
        $loginCookiesArray = [];
        foreach ($loginResponse->cookies()->toArray() as $cookie) {
            $loginCookiesArray[$cookie['Name']] = $cookie['Value'];
        }

        // Step 3: Access fraud data
        $authResponse = $this->proxyClient()
            ->withCookies($loginCookiesArray, 'steadfast.com.bd')
            ->get("https://steadfast.com.bd/user/consignment/getbyphone/{$phoneNumber}");

        if (!$authResponse->successful()) {
            return ['error' => 'Failed to fetch fraud data from Steadfast', 'status' => $authResponse->status()];
        }

        $object = $authResponse->collect()->toArray();

        $success = (int)($object['total_delivered'] ?? 0);
        $cancel = (int)($object['total_cancelled'] ?? 0);
        $total = $success + $cancel;
        $success_ratio = $total > 0 ? round(($success / $total) * 100, 2) : 0;

        $result = [
            'success' => $success,
            'cancel' => $cancel,
            'total'  => $total,
            'success_ratio' => $success_ratio,
        ];

        // Step 4: Logout
        $logoutGET = $this->proxyClient()
            ->withCookies($loginCookiesArray, 'steadfast.com.bd')
            ->get('https://steadfast.com.bd/user/frauds/check');

        if ($logoutGET->successful()) {
            $html = $logoutGET->body();

            if (preg_match('/<meta name="csrf-token" content="(.*?)"/', $html, $matches)) {
                $csrfToken = $matches[1];

                $this->proxyClient()
                    ->withCookies($loginCookiesArray, 'steadfast.com.bd')
                    ->asForm()
                    ->post('https://steadfast.com.bd/logout', [
                        '_token' => $csrfToken,
                    ]);
            }
        }

        return $result;
    }

    /**
     * Fetch delivery statistics from Steadfast for the given phone number.
     *
     * This method handles the full login flow, extracts CSRF tokens, manages
     * cookies, fetches the fraud data, and then gracefully logs out.
     *
     * @param string $phoneNumber The Bangladeshi mobile number to check.
     * @return array Contains 'success', 'cancel', 'total', and 'success_ratio'.
     *               Returns an array with an 'error' key if any step fails.
     */
    public function getDeliveryStats(string $phoneNumber): array
    {
        try {
            CourierDataValidator::checkBdMobile($phoneNumber);
            $lastError = ['error' => 'Failed to fetch fraud data from Steadfast'];

            foreach ($this->accounts as $account) {
                $result = $this->getDeliveryStatsForAccount($phoneNumber, $account);

                if (!isset($result['error'])) {
                    return $result;
                }

                $lastError = $result;
            }

            return $lastError;
        } catch (\Exception $e) {
            return [
                'error' => 'An error occurred while processing Steadfast request',
                'message' => $e->getMessage()
            ];
        }
    }
}
