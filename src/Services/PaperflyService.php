<?php

namespace Czbd\CourierChecker\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Czbd\CourierChecker\Contracts\CourierServiceInterface;
use Czbd\CourierChecker\Helpers\CourierDataValidator;

/**
 * Class PaperflyService
 *
 * Handles API interactions with Paperfly courier to fetch delivery statistics
 * for a given customer phone number.
 *
 * @package Czbd\CourierChecker\Services
 */
class PaperflyService implements CourierServiceInterface
{
    /**
     * @var string Base URL for the Paperfly Merchant Reactor API.
     */
    protected string $baseUrl = 'https://go-app.paperfly.com.bd/merchant/api/react';

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
            'user' => (string) config('courier-checker.paperfly.user'),
            'password' => (string) config('courier-checker.paperfly.password'),
        ], [
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
     * Get the authentication token, either from cache or by logging in.
     *
     * @return string
     * @throws \Exception
     */
    public function getToken(array $account): string
    {
        $cacheKey = 'paperfly_access_token_' . md5($account['user']);

        // Cache the token for 55 minutes since Paperfly tokens may expire
        return Cache::remember($cacheKey, 3300, function () use ($account) {
            $response = $this->httpClient()->post("{$this->baseUrl}/authentication/login_using_password.php", [
                'username' => $account['user'],
                'password' => $account['password'],
            ]);

            if ($response->failed() || !$response->json('token')) {
                throw new \Exception("Paperfly Login Failed: " . $response->body());
            }

            return $response->json('token');
        });
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
        try {
            CourierDataValidator::checkBdMobile($phoneNumber);

            $lastError = ['error' => 'Failed to fetch fraud data from Paperfly'];

            foreach ($this->accounts as $account) {
                try {
                    $token = $this->getToken($account);

                    // Perform the Smart Check search
                    $response = $this->httpClient()->withToken($token)
                        ->withHeaders(['Accept' => 'application/json, text/plain, */*'])
                        ->post("{$this->baseUrl}/smart-check/list.php", [
                            'search_text' => $phoneNumber,
                            'limit' => 50,
                            'page' => 1,
                        ]);

                    if ($response->failed()) {
                        $lastError = ['error' => 'Failed to fetch fraud data from Paperfly', 'status' => $response->status()];
                        continue;
                    }

                    $data = $response->json();

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
                } catch (\Exception $e) {
                    $lastError = [
                        'error' => 'An error occurred while processing Paperfly request',
                        'message' => $e->getMessage(),
                    ];
                }
            }

            return $lastError;
            
        } catch (\Exception $e) {
            return [
                'error' => 'An error occurred while processing Paperfly request',
                'message' => $e->getMessage()
            ];
        }
    }
}
