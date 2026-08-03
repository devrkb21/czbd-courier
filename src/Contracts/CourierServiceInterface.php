<?php

namespace Czbd\CourierChecker\Contracts;

use GuzzleHttp\Promise\PromiseInterface;

/**
 * Interface CourierServiceInterface
 *
 * Defines the contract for all courier service integrations. Any new courier
 * service added to the package must implement this interface to ensure
 * consistency in fetching delivery statistics.
 *
 * @package Czbd\CourierChecker\Contracts
 */
interface CourierServiceInterface
{
    /**
     * Get customer delivery statistics based on their phone number.
     *
     * @param string $phoneNumber The customer phone number.
     * @return array Returns an array with keys 'success', 'cancel', 'total', and 'success_ratio'.
     */
    public function getDeliveryStats(string $phoneNumber): array;

    /**
     * Same as getDeliveryStats(), but returns a promise instead of blocking.
     *
     * Lets CourierCheckerManager run every courier concurrently instead of
     * one after another. The promise always resolves (never rejects) to the
     * same array shape getDeliveryStats() returns, success or error alike.
     *
     * @param string $phoneNumber The customer phone number.
     * @return PromiseInterface<array>
     */
    public function getDeliveryStatsAsync(string $phoneNumber): PromiseInterface;
}
