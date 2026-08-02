<?php

namespace Czbd\CourierChecker\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Czbd\CourierChecker\Helpers\CourierDataValidator;
use GuzzleHttp\Cookie\CookieJar;

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
        // A shared CookieJar lets Guzzle accumulate cookies set at every hop
        // of a redirect chain (login -> forced-password-change interstitial
        // -> back), instead of only keeping the last response's Set-Cookie
        // headers. Manually re-extracting cookies between steps silently
        // drops cookies set on intermediate redirects, which was causing
        // authenticated sessions to look logged-out on the next request.
        $cookieJar = new CookieJar();
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ];

        // Step 1: Fetch login page
        $response = $this->proxyClient(['cookies' => $cookieJar])
            ->withHeaders($headers)
            ->get('https://steadfast.com.bd/login');

        // Extract CSRF token
        preg_match('/<input type="hidden" name="_token" value="(.*?)"/', $response->body(), $matches);
        $token = $matches[1] ?? null;

        if (!$token) {
            return ['error' => 'CSRF token not found for Steadfast login'];
        }

        // Step 2: Log in
        $loginResponse = $this->proxyClient(['cookies' => $cookieJar])
            ->withHeaders($headers + ['Referer' => 'https://steadfast.com.bd/login'])
            ->asForm()
            ->post('https://steadfast.com.bd/login', [
                '_token' => $token,
                'email' => $account['user'],
                'password' => $account['password'],
            ]);

        if (!($loginResponse->successful() || $loginResponse->redirect())) {
            return ['error' => 'Login to Steadfast failed', 'status' => $loginResponse->status()];
        }

        // Step 3: Access fraud data
        $authResponse = $this->proxyClient(['cookies' => $cookieJar])
            ->withHeaders($headers + ['Referer' => 'https://steadfast.com.bd/'])
            ->get("https://steadfast.com.bd/user/consignment/getbyphone/{$phoneNumber}");

        if (!$authResponse->successful()) {
            return ['error' => 'Failed to fetch fraud data from Steadfast', 'status' => $authResponse->status()];
        }

        // A stale/rejected session gets silently redirected back to the login
        // page (or a forced password-change page) by Steadfast, which still
        // responds with HTTP 200 but HTML (or an unexpected JSON shape)
        // instead of the real payload. Require both expected keys to be
        // present so that case is treated as a failed account (and the pool
        // moves on) instead of a false "0 success / 0 cancel".
        $object = $authResponse->json();

        if (!is_array($object) || !array_key_exists('total_delivered', $object) || !array_key_exists('total_cancelled', $object)) {
            return ['error' => 'Steadfast session was not authenticated (redirected instead of returning data)', 'status' => $authResponse->status()];
        }

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
        $logoutGET = $this->proxyClient(['cookies' => $cookieJar])
            ->withHeaders($headers)
            ->get('https://steadfast.com.bd/user/frauds/check');

        if ($logoutGET->successful()) {
            $html = $logoutGET->body();

            if (preg_match('/<meta name="csrf-token" content="(.*?)"/', $html, $matches)) {
                $csrfToken = $matches[1];

                $this->proxyClient(['cookies' => $cookieJar])
                    ->withHeaders($headers)
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
