<?php

namespace Czbd\CourierChecker\Tests\Unit;

use Czbd\CourierChecker\Tests\TestCase;
use Czbd\CourierChecker\Services\SteadfastService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;

class SteadfastServiceTest extends TestCase
{
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

            'https://steadfast.com.bd/user/frauds/check' => Http::response(
                '<meta name="csrf-token" content="logout_csrf_123">',
                200
            ),

            'https://steadfast.com.bd/logout' => Http::response('Logged out', 200),
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

            'https://steadfast.com.bd/user/frauds/check' => Http::response(
                '<meta name="csrf-token" content="logout_csrf_123">',
                200
            ),

            'https://steadfast.com.bd/logout' => Http::response('Logged out', 200),
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
