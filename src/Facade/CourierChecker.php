<?php

namespace Czbd\CourierChecker\Facade;

use Illuminate\Support\Facades\Facade;

/**
 * Class CourierChecker
 *
 * Facade for the Fraud Checker BD Courier package. Provides static access
 * to the underlying CourierCheckerManager instance.
 *
 * @method static array check(string $phoneNumber) Fetch aggregated stats for a given phone number.
 *
 * @package Czbd\CourierChecker\Facade
 * @see \Czbd\CourierChecker\CourierCheckerManager
 */
class CourierChecker extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'courier-checker';
    }
}
