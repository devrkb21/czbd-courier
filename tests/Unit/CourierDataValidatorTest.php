<?php

namespace Czbd\CourierChecker\Tests\Unit;

use Czbd\CourierChecker\Helpers\CourierDataValidator;
use Czbd\CourierChecker\Tests\TestCase;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;

class CourierDataValidatorTest extends TestCase
{
    public function test_parse_list_supports_commas_and_newlines(): void
    {
        $input = "alpha@example.com, beta@example.com\n gamma@example.com\r\n";

        Assert::assertSame([
            'alpha@example.com',
            'beta@example.com',
            'gamma@example.com',
        ], CourierDataValidator::parseList($input));
    }

    public function test_build_credential_pool_uses_primary_and_fallback_accounts(): void
    {
        $pool = CourierDataValidator::buildCredentialPool(
            'Pathao',
            [
                'user' => 'primary@example.com',
                'password' => 'primary-pass',
            ],
            [
                'user' => 'primary@example.com,secondary@example.com',
                'password' => 'primary-pass,secondary-pass',
            ]
        );

        Assert::assertSame([
            [
                'user' => 'primary@example.com',
                'password' => 'primary-pass',
            ],
            [
                'user' => 'secondary@example.com',
                'password' => 'secondary-pass',
            ],
        ], $pool);
    }

    public function test_build_credential_pool_throws_for_misaligned_lists(): void
    {
        try {
            CourierDataValidator::buildCredentialPool(
                'Pathao',
                [
                    'user' => '',
                    'password' => '',
                ],
                [
                    'user' => 'first@example.com,second@example.com',
                    'password' => 'single-pass',
                ]
            );

            Assert::fail('Expected InvalidArgumentException for misaligned fallback lists.');
        } catch (InvalidArgumentException $exception) {
            Assert::assertStringContainsString('misaligned', $exception->getMessage());
        }
    }

    public function test_resolve_courier_proxy_supports_global_and_specific_flags(): void
    {
        Config::set('courier-checker.proxy.address', 'http://127.0.0.1:8080');
        Config::set('courier-checker.proxy.all', 'no');
        Config::set('courier-checker.proxy.pathao', 'yes');

        Assert::assertSame('http://127.0.0.1:8080', CourierDataValidator::resolveCourierProxy('pathao'));

        Config::set('courier-checker.proxy.pathao', 'no');
        Config::set('courier-checker.proxy.all', 'yes');

        Assert::assertSame('http://127.0.0.1:8080', CourierDataValidator::resolveCourierProxy('pathao'));

        Config::set('courier-checker.proxy.address', '');

        Assert::assertNull(CourierDataValidator::resolveCourierProxy('pathao'));
    }

    public function test_resolve_courier_proxy_rejects_invalid_yes_no_values(): void
    {
        Config::set('courier-checker.proxy.address', 'http://127.0.0.1:8080');
        Config::set('courier-checker.proxy.all', 'maybe');

        try {
            CourierDataValidator::resolveCourierProxy('pathao');
            Assert::fail('Expected InvalidArgumentException for invalid proxy flag value.');
        } catch (InvalidArgumentException $exception) {
            Assert::assertStringContainsString('Allowed values are yes or no', $exception->getMessage());
        }
    }
}
