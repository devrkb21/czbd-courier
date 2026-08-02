<?php

namespace Czbd\CourierChecker\Tests\Unit;

use Czbd\CourierChecker\Tests\TestCase;
use Czbd\CourierChecker\Services\CarrybeeService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class CarrybeeServiceTest extends TestCase
{
    public function test_carrybee_successful_fetch()
    {
        $phone = '01711111111';

        Config::set('courier-checker.carrybee.phones', '+8801787350229');
        Config::set('courier-checker.carrybee.passwords', '2pWdRmwF');

        Http::fake([
            'https://merchant.carrybee.com/api/auth/csrf' => Http::response([
                'csrfToken' => 'fake_csrf_123'
            ], 200),

            'https://merchant.carrybee.com/api/auth/callback/login' => Http::response([], 200),

            'https://merchant.carrybee.com/api/auth/session' => Http::response([
                'accessToken' => 'fake_access_token_abc',
                'user' => [
                    'selectedBusinessId' => '15834'
                ]
            ], 200),

            'https://api-merchant.carrybee.com/api/v2/businesses/15834/customers/*' => Http::response([
                'error' => false,
                'message' => 'Customer info',
                'data' => [
                    'phone' => '8801711111111',
                    'total_order' => 10,
                    'cancelled_order' => 2,
                    'success_rate' => 80
                ]
            ], 200),
        ]);

        $service = new CarrybeeService();
        $result = $service->getDeliveryStats($phone);

        $this->assertEquals([
            'success' => 8,
            'cancel' => 2,
            'total' => 10,
            'success_ratio' => 80.0,
        ], $result);
    }

    public function test_carrybee_failed_login()
    {
        $phone = '01711111111';

        Config::set('courier-checker.carrybee.phones', '+8801787350229');
        Config::set('courier-checker.carrybee.passwords', 'wrong_password');

        Http::fake([
            'https://merchant.carrybee.com/api/auth/csrf' => Http::response([
                'csrfToken' => 'fake_csrf_123'
            ], 200),

            'https://merchant.carrybee.com/api/auth/callback/login' => Http::response([], 401),
        ]);

        $service = new CarrybeeService();
        $result = $service->getDeliveryStats($phone);

        $this->assertEquals([
            'error' => 'Login failed or unable to get access token from Carrybee',
        ], $result);
    }

    public function test_carrybee_falls_back_to_next_account_when_response_is_malformed()
    {
        $phone = '01711111111';

        Config::set('courier-checker.carrybee.phones', '01700000001,01700000002');
        Config::set('courier-checker.carrybee.passwords', 'primary_pass,backup_pass');

        Http::fake([
            'https://merchant.carrybee.com/api/auth/csrf' => Http::response([
                'csrfToken' => 'fake_csrf_123'
            ], 200),

            'https://merchant.carrybee.com/api/auth/callback/login' => Http::response([], 200),

            'https://merchant.carrybee.com/api/auth/session' => Http::sequence()
                ->push(['accessToken' => 'primary_token', 'user' => ['selectedBusinessId' => '15834']], 200)
                ->push(['accessToken' => 'backup_token', 'user' => ['selectedBusinessId' => '15834']], 200),

            // First account gets a 200 OK but a throttled/unexpected body
            // (no real 'data') instead of real data; second account works.
            'https://api-merchant.carrybee.com/api/v2/businesses/15834/customers/*' => Http::sequence()
                ->push(['error' => false, 'data' => null], 200)
                ->push(['error' => false, 'data' => ['total_order' => 6, 'cancelled_order' => 1, 'success_rate' => 83.33]], 200),
        ]);

        $service = new CarrybeeService();
        $result = $service->getDeliveryStats($phone);

        $this->assertEquals([
            'success' => 5,
            'cancel' => 1,
            'total' => 6,
            'success_ratio' => 83.33,
        ], $result);
    }
}
