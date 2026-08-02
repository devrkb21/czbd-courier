<?php

namespace Czbd\CourierChecker\Tests\Unit;

use Czbd\CourierChecker\Tests\TestCase;
use Czbd\CourierChecker\Services\RedxService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class RedxServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
    }

    public function test_redx_successful_fetch()
    {
        $phone = '01711111111';

        Http::fake([
            'https://api.redx.com.bd/v4/auth/login' => Http::response([
                'data' => [
                    'accessToken' => 'fake_redx_token_xyz'
                ]
            ], 200),

            "https://redx.com.bd/api/redx_se/admin/parcel/customer-success-return-rate?phoneNumber=88{$phone}" => Http::response([
                'data' => [
                    'deliveredParcels' => 20,
                    'totalParcels' => 25
                ]
            ], 200),
        ]);

        $service = new RedxService();
        $result = $service->getDeliveryStats($phone);

        $this->assertEquals([
            'success' => 20,
            'cancel' => 5,
            'total' => 25,
            'success_ratio' => 80.0,
        ], $result);
    }

    public function test_redx_falls_back_to_next_account_when_response_is_malformed(): void
    {
        $phone = '01711111111';

        Config::set('courier-checker.redx.phones', '01700000001,01700000002');
        Config::set('courier-checker.redx.passwords', 'primary_pass,fallback_pass');

        Http::fake([
            'https://api.redx.com.bd/v4/auth/login' => Http::sequence()
                ->push(['data' => ['accessToken' => 'primary_token']], 200)
                ->push(['data' => ['accessToken' => 'fallback_token']], 200),

            // First account gets a 200 OK but a throttled/unexpected body
            // (no 'data' key) instead of real data; second account works.
            "https://redx.com.bd/api/redx_se/admin/parcel/customer-success-return-rate?phoneNumber=88{$phone}" => Http::sequence()
                ->push(['message' => 'Too many requests'], 200)
                ->push(['data' => ['deliveredParcels' => 3, 'totalParcels' => 4]], 200),
        ]);

        $service = new RedxService();
        $result = $service->getDeliveryStats($phone);

        $this->assertEquals([
            'success' => 3,
            'cancel' => 1,
            'total' => 4,
            'success_ratio' => 75.0,
        ], $result);
    }
}
