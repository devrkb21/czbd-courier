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
        $app['config']->set('courier-checker.steadfast.user', 'fake_email@test.com');
        $app['config']->set('courier-checker.steadfast.password', 'fake_password');

        $app['config']->set('courier-checker.pathao.user', 'fake_user');
        $app['config']->set('courier-checker.pathao.password', 'fake_password');

        $app['config']->set('courier-checker.redx.phone', '01700000000');
        $app['config']->set('courier-checker.redx.password', 'fake_password');
    }
}
