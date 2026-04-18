<?php

namespace Ubxty\CoreAi\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Ubxty\CoreAi\Contracts\AiManagerContract;

class HealthCheckController extends Controller
{
    public function __invoke(AiManagerContract $manager): JsonResponse
    {
        try {
            $result = $manager->testConnection();

            return new JsonResponse([
                'status' => $result['success'] ? 'ok' : 'error',
                'platform' => $manager->platformName(),
                'message' => $result['message'] ?? '',
                'response_time_ms' => $result['response_time'] ?? null,
            ], $result['success'] ? 200 : 503);
        } catch (\Exception $e) {
            return new JsonResponse([
                'status' => 'error',
                'platform' => $manager->platformName(),
                'message' => $e->getMessage(),
            ], 503);
        }
    }
}
