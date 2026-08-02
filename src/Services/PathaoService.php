<?php

namespace Czbd\CourierChecker\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Czbd\CourierChecker\Helpers\CourierDataValidator;

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
     * Execute Pathao flow for a single account.
     *
     * @param string $phoneNumber
     * @param array<string, string> $account
     * @return array
     */
    protected function getDeliveryStatsForAccount(string $phoneNumber, array $account): array
    {
        $response = $this->httpClient()->post('https://merchant.pathao.com/api/v1/login', [
            'username' => $account['user'],
            'password' => $account['password'],
        ]);

        if (!$response->successful()) {
            return ['error' => 'Failed to authenticate with Pathao', 'status' => $response->status()];
        }

        $data = $response->json();
        $accessToken = trim($data['access_token'] ?? '');

        if (!$accessToken) {
            return ['error' => 'No access token received from Pathao'];
        }

        $responseAuth = $this->httpClient()->withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $accessToken,
        ])->post('https://merchant.pathao.com/api/v1/user/success', [
            'phone' => $phoneNumber,
        ]);

        if (!$responseAuth->successful()) {
            return ['error' => 'Failed to retrieve customer data from Pathao', 'status' => $responseAuth->status()];
        }

        $object = $responseAuth->json();

        // A rate-limited/throttled request can still come back as HTTP 200
        // with an unexpected body instead of the real payload. Require the
        // 'data' key to actually be present so that case is treated as a
        // failed account (and the pool moves on) instead of a false "0
        // success / 0 cancel".
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
    }

    /**
     * Fetch delivery statistics from Pathao for the given phone number.
     *
     * @param string $phoneNumber The Bangladeshi mobile number to check.
     * @return array Contains 'success', 'cancel', 'total', 'success_ratio', and optional 'customer_rating'.
     *               In case of an error, returns an array with an 'error' key.
     */
    public function getDeliveryStats(string $phoneNumber): array
    {
        try {
            CourierDataValidator::checkBdMobile($phoneNumber);
            $lastError = ['error' => 'Failed to retrieve customer data from Pathao'];

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
                'error' => 'An error occurred while processing Pathao request',
                'message' => $e->getMessage()
            ];
        }
    }
}
