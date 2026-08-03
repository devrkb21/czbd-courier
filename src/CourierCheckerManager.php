<?php

namespace Czbd\CourierChecker;

use Czbd\CourierChecker\Contracts\CourierServiceInterface;
use Illuminate\Support\Facades\Log;

/**
 * Class CourierCheckerManager
 *
 * Core manager class responsible for aggregating delivery statistics
 * from multiple courier services (Steadfast, Pathao, RedX).
 *
 * @package Czbd\CourierChecker
 */
class CourierCheckerManager
{
    /**
     * CourierCheckerManager constructor.
     *
     * Initializes the manager with the required courier service instances.
     *
     * @param CourierServiceInterface $steadfastService Instance of SteadfastService.
     * @param CourierServiceInterface $pathaoService    Instance of PathaoService.
     * @param CourierServiceInterface $redxService      Instance of RedxService.
     */
    public function __construct(
        protected CourierServiceInterface $steadfastService,
        protected CourierServiceInterface $pathaoService,
        protected CourierServiceInterface $redxService,
        protected CourierServiceInterface $paperflyService,
        protected CourierServiceInterface $carrybeeService,
    ) {}

    /**
     * Fetch delivery statistics across all configured couriers and aggregate the results.
     *
     * Couriers are queried one at a time. A concurrent version was tried
     * (see git history) but Guzzle/Laravel's async promise machinery drives
     * chained, multi-step requests (login -> fetch, account failover) via a
     * sequential wait loop under the hood - it only gives real parallelism
     * for flat, single-step requests, not the kind of session-based flows
     * every courier here uses. In testing it was no faster on average and
     * occasionally slower than running sequentially, so it wasn't worth the
     * added complexity and risk. Each service still caches its own
     * session/token internally, which is where the real speedup comes from.
     *
     * @param string $phoneNumber The Bangladeshi mobile number to check.
     * @return array Returns an associative array containing stats for each courier
     *               as well as an overall aggregated summary.
     */
    public function check(string $phoneNumber): array
    {
        $payload = [
            'steadfast' => null,
            'pathao' => null,
            'redx' => null,
            'paperfly' => null,
            'carrybee' => null,
            'aggregate' => [
                'total_success' => 0,
                'total_cancel' => 0,
                'total_deliveries' => 0,
                'success_ratio' => 0,
                'cancel_ratio' => 0,
            ]
        ];

        $services = [
            'steadfast' => $this->steadfastService,
            'pathao' => $this->pathaoService,
            'redx' => $this->redxService,
            'paperfly' => $this->paperflyService,
            'carrybee' => $this->carrybeeService,
        ];

        $totalSuccessCount = 0;
        $totalCancelCount = 0;

        foreach ($services as $key => $service) {
            try {
                $stats = $service->getDeliveryStats($phoneNumber);
                $payload[$key] = $stats;

                if (isset($stats['success'], $stats['cancel']) && is_numeric($stats['success']) && is_numeric($stats['cancel'])) {
                    $totalSuccessCount += (int)$stats['success'];
                    $totalCancelCount += (int)$stats['cancel'];
                }
            } catch (\Exception $e) {
                Log::error("CourierChecker: {$key} service failed.", [
                    'message' => $e->getMessage(),
                    'phone' => $phoneNumber,
                    'trace' => $e->getTraceAsString(),
                ]);

                $payload[$key] = [
                    'error' => 'Service unavailable or failed to process',
                    'message' => $e->getMessage()
                ];
            }
        }

        $overallTotal = $totalSuccessCount + $totalCancelCount;

        $payload['aggregate']['total_success'] = $totalSuccessCount;
        $payload['aggregate']['total_cancel'] = $totalCancelCount;
        $payload['aggregate']['total_deliveries'] = $overallTotal;

        if ($overallTotal > 0) {
            $payload['aggregate']['success_ratio'] = round(($totalSuccessCount / $overallTotal) * 100, 2);
            $payload['aggregate']['cancel_ratio'] = round(($totalCancelCount / $overallTotal) * 100, 2);
        }

        return $payload;
    }
}
