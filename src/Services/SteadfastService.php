<?php

namespace Czbd\CourierChecker\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Czbd\CourierChecker\Helpers\CourierDataValidator;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;

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
     * @var int Cached session TTL in minutes.
     */
    protected const CACHE_MINUTES = 50;

    /**
     * @var string The cookie domain Steadfast's login flow operates under.
     */
    protected const COOKIE_DOMAIN = 'steadfast.com.bd';

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
     * Shared browser-like headers used for every Steadfast request.
     */
    protected function defaultHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ];
    }

    /**
     * Resolve per-account cache key for the cached session cookies.
     */
    protected function cacheKeyForUser(string $user): string
    {
        return 'steadfast_cookies_' . md5($user);
    }

    /**
     * Rebuild a CookieJar from a cached name => value map.
     */
    protected function buildCookieJarFromArray(array $cookies): CookieJar
    {
        $jar = new CookieJar();

        foreach ($cookies as $name => $value) {
            $jar->setCookie(new SetCookie([
                'Name' => $name,
                'Value' => $value,
                'Domain' => self::COOKIE_DOMAIN,
                'Path' => '/',
            ]));
        }

        return $jar;
    }

    /**
     * Persist the authenticated session's cookies for reuse by later calls.
     */
    protected function cacheCookieJar(string $user, CookieJar $cookieJar): void
    {
        $cookies = [];
        foreach ($cookieJar->toArray() as $cookie) {
            $cookies[$cookie['Name']] = $cookie['Value'];
        }

        if ($cookies !== []) {
            Cache::put($this->cacheKeyForUser($user), $cookies, now()->addMinutes(self::CACHE_MINUTES));
        }
    }

    /**
     * Fetch fraud/delivery data for a phone number using an already-authenticated cookie jar.
     */
    protected function fetchDeliveryDataAsync(string $phoneNumber, CookieJar $cookieJar): PromiseInterface
    {
        return $this->proxyClient(['cookies' => $cookieJar])
            ->async()
            ->withHeaders($this->defaultHeaders() + ['Referer' => 'https://steadfast.com.bd/'])
            ->get("https://steadfast.com.bd/user/consignment/getbyphone/{$phoneNumber}")
            ->then(function ($authResponse) {
                if (!$authResponse->successful()) {
                    return ['error' => 'Failed to fetch fraud data from Steadfast', 'status' => $authResponse->status()];
                }

                // A stale/rejected session gets silently redirected back to the
                // login page (or a forced password-change page) by Steadfast,
                // which still responds with HTTP 200 but HTML (or an unexpected
                // JSON shape) instead of the real payload. Require both expected
                // keys to be present so that case is treated as a failed
                // attempt instead of a false "0 success / 0 cancel".
                $object = $authResponse->json();

                if (!is_array($object) || !array_key_exists('total_delivered', $object) || !array_key_exists('total_cancelled', $object)) {
                    return ['error' => 'Steadfast session was not authenticated (redirected instead of returning data)', 'status' => $authResponse->status()];
                }

                $success = (int)($object['total_delivered'] ?? 0);
                $cancel = (int)($object['total_cancelled'] ?? 0);
                $total = $success + $cancel;
                $success_ratio = $total > 0 ? round(($success / $total) * 100, 2) : 0;

                return [
                    'success' => $success,
                    'cancel' => $cancel,
                    'total'  => $total,
                    'success_ratio' => $success_ratio,
                ];
            });
    }

    /**
     * Perform a full login for an account, cache the resulting session on
     * success, then fetch the delivery data.
     */
    protected function freshLoginAndFetchAsync(string $phoneNumber, array $account): PromiseInterface
    {
        $cookieJar = new CookieJar();
        $headers = $this->defaultHeaders();

        // Step 1: Fetch login page (to scrape a fresh CSRF token)
        return $this->proxyClient(['cookies' => $cookieJar])
            ->async()
            ->withHeaders($headers)
            ->get('https://steadfast.com.bd/login')
            ->then(function ($response) use ($phoneNumber, $account, $cookieJar, $headers) {
                preg_match('/<input type="hidden" name="_token" value="(.*?)"/', $response->body(), $matches);
                $token = $matches[1] ?? null;

                if (!$token) {
                    return ['error' => 'CSRF token not found for Steadfast login'];
                }

                // Step 2: Log in
                return $this->proxyClient(['cookies' => $cookieJar])
                    ->async()
                    ->withHeaders($headers + ['Referer' => 'https://steadfast.com.bd/login'])
                    ->asForm()
                    ->post('https://steadfast.com.bd/login', [
                        '_token' => $token,
                        'email' => $account['user'],
                        'password' => $account['password'],
                    ])
                    ->then(function ($loginResponse) use ($phoneNumber, $account, $cookieJar) {
                        if (!($loginResponse->successful() || $loginResponse->redirect())) {
                            return ['error' => 'Login to Steadfast failed', 'status' => $loginResponse->status()];
                        }

                        // Step 3: Access fraud data
                        return $this->fetchDeliveryDataAsync($phoneNumber, $cookieJar)
                            ->then(function (array $result) use ($account, $cookieJar) {
                                if (!isset($result['error'])) {
                                    $this->cacheCookieJar($account['user'], $cookieJar);
                                }

                                return $result;
                            });
                    });
            });
    }

    /**
     * Execute Steadfast flow for a single account: try the cached session
     * first (skipping the login round-trip entirely), and only fall back to
     * a fresh login if there's no cached session or it's no longer valid.
     */
    protected function attemptAccountAsync(string $phoneNumber, array $account): PromiseInterface
    {
        $cacheKey = $this->cacheKeyForUser($account['user']);
        $cachedCookies = Cache::get($cacheKey);

        if (is_array($cachedCookies) && $cachedCookies !== []) {
            return $this->fetchDeliveryDataAsync($phoneNumber, $this->buildCookieJarFromArray($cachedCookies))
                ->then(function (array $result) use ($phoneNumber, $account, $cacheKey) {
                    if (!isset($result['error'])) {
                        return $result;
                    }

                    // Cached session is no longer valid; evict it and fall
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
                    'error' => 'An error occurred while processing Steadfast request',
                    'message' => $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason,
                ]);
            });
    }

    /**
     * Fetch delivery statistics from Steadfast for the given phone number,
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
                'error' => 'An error occurred while processing Steadfast request',
                'message' => $e->getMessage(),
            ]);
        }

        return $this->tryAccountsAsync($phoneNumber, 0, ['error' => 'Failed to fetch fraud data from Steadfast']);
    }

    /**
     * Fetch delivery statistics from Steadfast for the given phone number.
     *
     * This method handles the full login flow, extracts CSRF tokens, manages
     * cookies, and fetches the fraud data. Successful sessions are cached
     * (~50 minutes) so repeat calls can skip the login round-trip entirely.
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
