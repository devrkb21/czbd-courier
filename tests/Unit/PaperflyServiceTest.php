<?php

namespace Czbd\CourierChecker\Tests\Unit;

use Czbd\CourierChecker\Services\PaperflyService;
use Czbd\CourierChecker\Tests\TestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;

class PaperflyServiceTest extends TestCase
{
    public function test_paperfly_successful_fetch(): void
    {
        Config::set('courier-checker.paperfly.users', 'paperfly_user');
        Config::set('courier-checker.paperfly.passwords', 'paperfly_password');

        Http::fake([
            'https://go-app.paperfly.com.bd/merchant/api/react/authentication/login_using_password.php' => Http::response([
                'token' => 'paperfly-token',
            ], 200),
            'https://go-app.paperfly.com.bd/merchant/api/react/smart-check/list.php' => Http::response([
                'totalRecords' => 5,
                'records' => [
                    ['status' => 'Delivered'],
                    ['status' => 'Returned'],
                    ['status' => 'Cancelled'],
                    ['status' => 'success'],
                    ['status' => 'In Transit'],
                ],
            ], 200),
        ]);

        $service = new PaperflyService();
        $result = $service->getDeliveryStats('01711111111');

        Assert::assertSame([
            'success' => 2,
            'cancel' => 2,
            'total' => 5,
            'success_ratio' => 50.0,
        ], $result);
    }

    public function test_paperfly_uses_fallback_account_when_primary_login_fails(): void
    {
        Config::set('courier-checker.paperfly.users', 'primary_user,backup_user');
        Config::set('courier-checker.paperfly.passwords', 'wrong_pass,backup_pass');

        Http::fake([
            'https://go-app.paperfly.com.bd/merchant/api/react/authentication/login_using_password.php' => Http::sequence()
                ->push(['message' => 'invalid credentials'], 401)
                ->push(['token' => 'backup-token'], 200),
            'https://go-app.paperfly.com.bd/merchant/api/react/smart-check/list.php' => Http::response([
                'totalRecords' => 2,
                'records' => [
                    ['status' => 'Delivered'],
                    ['status' => 'Cancelled'],
                ],
            ], 200),
        ]);

        $service = new PaperflyService();
        $result = $service->getDeliveryStats('01711111111');

        Assert::assertSame([
            'success' => 1,
            'cancel' => 1,
            'total' => 2,
            'success_ratio' => 50.0,
        ], $result);
    }

    public function test_paperfly_falls_back_to_next_account_when_response_is_malformed(): void
    {
        Config::set('courier-checker.paperfly.users', 'primary_user,backup_user');
        Config::set('courier-checker.paperfly.passwords', 'primary_pass,backup_pass');

        Http::fake([
            'https://go-app.paperfly.com.bd/merchant/api/react/authentication/login_using_password.php' => Http::sequence()
                ->push(['token' => 'primary-token'], 200)
                ->push(['token' => 'backup-token'], 200),

            // First account gets a 200 OK but a throttled/unexpected body
            // (no 'totalRecords' key) instead of real data; second works.
            'https://go-app.paperfly.com.bd/merchant/api/react/smart-check/list.php' => Http::sequence()
                ->push(['message' => 'Too many requests'], 200)
                ->push(['totalRecords' => 1, 'records' => [['status' => 'Delivered']]], 200),
        ]);

        $service = new PaperflyService();
        $result = $service->getDeliveryStats('01711111111');

        Assert::assertSame([
            'success' => 1,
            'cancel' => 0,
            'total' => 1,
            'success_ratio' => 100.0,
        ], $result);
    }
}
