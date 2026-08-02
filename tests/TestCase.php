<?php

namespace Czbd\CourierChecker\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Czbd\CourierChecker\CourierCheckerServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            CourierCheckerServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Mock the required configs to bypass configuration exception
        $app['config']->set('courier-checker.steadfast.users', 'fake_email@test.com');
        $app['config']->set('courier-checker.steadfast.passwords', 'fake_password');

        $app['config']->set('courier-checker.pathao.users', 'fake_user');
        $app['config']->set('courier-checker.pathao.passwords', 'fake_password');

        $app['config']->set('courier-checker.redx.phones', '01700000000');
        $app['config']->set('courier-checker.redx.passwords', 'fake_password');
    }
}
