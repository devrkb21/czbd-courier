<?php

namespace Czbd\CourierChecker\Tests\Unit;

use Czbd\CourierChecker\Facade\CourierChecker;
use Czbd\CourierChecker\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;

class CourierCheckerServiceProviderTest extends TestCase
{
    public function test_facade_does_not_crash_when_some_couriers_are_unconfigured(): void
    {
        // TestCase only configures steadfast/pathao/redx; paperfly/carrybee are left empty.
        Http::fake([
            '*' => Http::response([], 500),
        ]);

        $result = CourierChecker::check('01711111111');

        Assert::assertArrayHasKey('error', $result['paperfly']);
        Assert::assertArrayHasKey('error', $result['carrybee']);
        Assert::assertSame('paperfly is not configured', $result['paperfly']['error']);
        Assert::assertSame('carrybee is not configured', $result['carrybee']['error']);
    }
}
