<?php

namespace Czbd\CourierChecker\Tests\Unit;

use Czbd\CourierChecker\Tests\TestCase;
use Czbd\CourierChecker\Services\PathaoService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;

class PathaoServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
    }

    public function test_pathao_successful_fetch()
    {
        $phone = '01711111111';

        Http::fake([
            'https://merchant.pathao.com/api/v1/login' => Http::response([
                'access_token' => 'fake_access_token_abc'
            ], 200),

            'https://merchant.pathao.com/api/v1/user/success' => Http::response([
                'data' => [
                    'customer_rating' => 'trusted_customer',
                    'customer' => [
                        'successful_delivery' => 10,
                        'total_delivery' => 12
                    ]
                ]
            ], 200),
        ]);

        $pathaoService = new PathaoService();

        // Execute function
        $result = $pathaoService->getDeliveryStats('01711111111');

        Assert::assertEquals([
            'success' => 10,
            'cancel' => 2,
            'total' => 12,
            'success_ratio' => 83.33,
            'customer_rating' => 'trusted_customer',
        ], $result);
    }

    public function test_pathao_falls_back_to_next_account_when_response_is_malformed(): void
    {
        $phone = '01711111111';

        Config::set('courier-checker.pathao.users', 'primary@example.com,fallback@example.com');
        Config::set('courier-checker.pathao.passwords', 'primary_pass,fallback_pass');

        Http::fake([
            'https://merchant.pathao.com/api/v1/login' => Http::sequence()
                ->push(['access_token' => 'primary_token'], 200)
                ->push(['access_token' => 'fallback_token'], 200),

            // First account gets a 200 OK but a throttled/unexpected body
            // (no 'data' key) instead of real data; second account works.
            'https://merchant.pathao.com/api/v1/user/success' => Http::sequence()
                ->push(['message' => 'Too many requests'], 200)
                ->push(['data' => ['customer' => ['successful_delivery' => 4, 'total_delivery' => 5]]], 200),
        ]);

        $service = new PathaoService();
        $result = $service->getDeliveryStats($phone);

        Assert::assertEquals([
            'success' => 4,
            'cancel' => 1,
            'total' => 5,
            'success_ratio' => 80.0,
            'customer_rating' => null,
        ], $result);
    }

    public function test_pathao_uses_cached_access_token_and_skips_login(): void
    {
        $phone = '01711111111';

        // TestCase's default pathao account (see tests/TestCase.php).
        Cache::put('pathao_access_token_' . md5('fake_user'), 'cached_token_xyz', now()->addMinutes(50));

        Http::fake([
            'https://merchant.pathao.com/api/v1/login' => function () {
                Assert::fail('Pathao login should be skipped when a cached token exists.');
            },
            'https://merchant.pathao.com/api/v1/user/success' => Http::response([
                'data' => [
                    'customer' => [
                        'successful_delivery' => 6,
                        'total_delivery' => 8,
                    ],
                ],
            ], 200),
        ]);

        $service = new PathaoService();
        $result = $service->getDeliveryStats($phone);

        Assert::assertEquals([
            'success' => 6,
            'cancel' => 2,
            'total' => 8,
            'success_ratio' => 75.0,
            'customer_rating' => null,
        ], $result);
    }

    public function test_pathao_evicts_stale_cached_token_and_retries_with_fresh_login(): void
    {
        $phone = '01711111111';

        Cache::put('pathao_access_token_' . md5('fake_user'), 'stale_token', now()->addMinutes(50));

        Http::fake([
            'https://merchant.pathao.com/api/v1/user/success' => Http::sequence()
                ->push(['message' => 'Unauthenticated'], 401)
                ->push(['data' => ['customer' => ['successful_delivery' => 3, 'total_delivery' => 4]]], 200),

            'https://merchant.pathao.com/api/v1/login' => Http::response([
                'access_token' => 'fresh_token_xyz',
            ], 200),
        ]);

        $service = new PathaoService();
        $result = $service->getDeliveryStats($phone);

        Assert::assertEquals([
            'success' => 3,
            'cancel' => 1,
            'total' => 4,
            'success_ratio' => 75.0,
            'customer_rating' => null,
        ], $result);
    }
}
