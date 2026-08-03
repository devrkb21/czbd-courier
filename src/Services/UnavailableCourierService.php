<?php

namespace Czbd\CourierChecker\Services;

use Czbd\CourierChecker\Contracts\CourierServiceInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * Class UnavailableCourierService
 *
 * Stand-in used when a courier's credentials are missing or invalid at
 * container resolution time. Lets the manager report a per-courier error
 * instead of failing the whole container build.
 *
 * @package Czbd\CourierChecker\Services
 */
readonly class UnavailableCourierService implements CourierServiceInterface
{
    public function __construct(protected string $courier, protected string $reason) {}

    public function getDeliveryStats(string $phoneNumber): array
    {
        return [
            'error' => sprintf('%s is not configured', $this->courier),
            'message' => $this->reason,
        ];
    }

    public function getDeliveryStatsAsync(string $phoneNumber): PromiseInterface
    {
        return Create::promiseFor($this->getDeliveryStats($phoneNumber));
    }
}
