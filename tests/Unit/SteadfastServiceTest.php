<?php

namespace Czbd\CourierChecker\Tests\Unit;

use Czbd\CourierChecker\Tests\TestCase;
use Czbd\CourierChecker\Services\SteadfastService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;

class SteadfastServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
    }

    public function test_steadfast_successful_fetch()
    {
        $phone = '01711111111';

        Http::fake([
            'https://steadfast.com.bd/login' => Http::sequence()
                ->push('<input type="hidden" name="_token" value="fake_csrf_123">', 200)
                ->push('Login Success', 302),

            "https://steadfast.com.bd/user/consignment/getbyphone/{$phone}" => Http::response([
                'total_delivered' => 5,
                'total_cancelled' => 2,
            ], 200),
        ]);

        $service = new SteadfastService();
        $result = $service->getDeliveryStats($phone);

        Assert::assertEquals([
            'success' => 5,
            'cancel' => 2,
            'total' => 7,
            'success_ratio' => 71.43,
        ], $result);
    }

    public function test_steadfast_uses_cached_session_and_skips_login(): void
    {
        $phone = '01711111111';

        // TestCase's default steadfast account (see tests/TestCase.php).
        Cache::put('steadfast_cookies_' . md5('fake_email@test.com'), [
            'steadfast_courier_session' => 'cached-session-value',
            'XSRF-TOKEN' => 'cached-xsrf-value',
        ], now()->addMinutes(50));

        Http::fake([
            'https://steadfast.com.bd/login' => function () {
                Assert::fail('Steadfast login should be skipped when a cached session exists.');
            },
            "https://steadfast.com.bd/user/consignment/getbyphone/{$phone}" => Http::response([
                'total_delivered' => 12,
                'total_cancelled' => 3,
            ], 200),
        ]);

        $service = new SteadfastService();
        $result = $service->getDeliveryStats($phone);

        Assert::assertEquals([
            'success' => 12,
            'cancel' => 3,
            'total' => 15,
            'success_ratio' => 80.0,
        ], $result);
    }

    public function test_steadfast_evicts_stale_cached_session_and_retries_with_fresh_login(): void
    {
        $phone = '01711111111';

        Cache::put('steadfast_cookies_' . md5('fake_email@test.com'), [
            'steadfast_courier_session' => 'stale-session-value',
        ], now()->addMinutes(50));

        Http::fake([
            // Cached session is stale: redirected back to the login page
            // (200 OK, but no total_delivered/total_cancelled keys).
            "https://steadfast.com.bd/user/consignment/getbyphone/{$phone}" => Http::sequence()
                ->push('<!DOCTYPE html><title>Steadfast Courier</title>', 200)
                ->push(['total_delivered' => 4, 'total_cancelled' => 1], 200),

            'https://steadfast.com.bd/login' => Http::sequence()
                ->push('<input type="hidden" name="_token" value="fake_csrf_123">', 200)
                ->push('Login Success', 302),
        ]);

        $service = new SteadfastService();
        $result = $service->getDeliveryStats($phone);

        Assert::assertEquals([
            'success' => 4,
            'cancel' => 1,
            'total' => 5,
            'success_ratio' => 80.0,
        ], $result);
    }

    public function test_steadfast_falls_back_to_next_account_when_response_is_malformed(): void
    {
        $phone = '01711111111';

        Config::set('courier-checker.steadfast.users', 'primary@example.com,backup@example.com');
        Config::set('courier-checker.steadfast.passwords', 'primary_pass,backup_pass');

        Http::fake([
            'https://steadfast.com.bd/login' => Http::sequence()
                // Account 1: login page, then a "successful" (redirect) login
                ->push('<input type="hidden" name="_token" value="fake_csrf_123">', 200)
                ->push('Login Success', 302)
                // Account 2: same
                ->push('<input type="hidden" name="_token" value="fake_csrf_456">', 200)
                ->push('Login Success', 302),

            // First account's session gets a 200 OK but a throttled/unexpected
            // body (missing total_delivered/total_cancelled) instead of real
            // data; second account works.
            "https://steadfast.com.bd/user/consignment/getbyphone/{$phone}" => Http::sequence()
                ->push(['message' => 'Too many requests'], 200)
                ->push(['total_delivered' => 9, 'total_cancelled' => 1], 200),
        ]);

        $service = new SteadfastService();
        $result = $service->getDeliveryStats($phone);

        Assert::assertEquals([
            'success' => 9,
            'cancel' => 1,
            'total' => 10,
            'success_ratio' => 90.0,
        ], $result);
    }
}
