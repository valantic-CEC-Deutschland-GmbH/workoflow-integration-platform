<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class HealthController extends AbstractController
{
    public function __construct(
        private Connection $connection,
        private CacheInterface $cache,
        private HttpClientInterface $httpClient
    ) {
    }

    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        $checks = [
            'status' => 'healthy',
            'timestamp' => date('c'),
            'checks' => []
        ];

        // Check database connection
        try {
            $this->connection->executeQuery('SELECT 1');
            $checks['checks']['database'] = 'ok';
        } catch (\Exception $e) {
            $checks['checks']['database'] = 'failed';
            $checks['status'] = 'unhealthy';
        }

        // Check cache
        try {
            $this->cache->get('health_check', fn() => 'ok');
            $checks['checks']['cache'] = 'ok';
        } catch (\Exception $e) {
            $checks['checks']['cache'] = 'failed';
            $checks['status'] = 'unhealthy';
        }

        // Check writable directories
        $writableDirs = ['var/cache', 'var/log', 'public/uploads'];
        foreach ($writableDirs as $dir) {
            $fullPath = $this->getParameter('kernel.project_dir') . '/' . $dir;
            if (is_writable($fullPath)) {
                $checks['checks']["writable_$dir"] = 'ok';
            } else {
                $checks['checks']["writable_$dir"] = 'failed';
                $checks['status'] = 'unhealthy';
            }
        }

        // Check Azure credentials (client_credentials grant)
        $azureClientId = $_ENV['AZURE_CLIENT_ID'] ?? '';
        $azureTenantId = $_ENV['AZURE_TENANT_ID'] ?? '';
        $azureClientSecret = $_ENV['AZURE_CLIENT_SECRET'] ?? '';

        if ($azureClientId !== '' && $azureTenantId !== '' && $azureClientSecret !== '') {
            try {
                $response = $this->httpClient->request('POST', sprintf(
                    'https://login.microsoftonline.com/%s/oauth2/v2.0/token',
                    $azureTenantId
                ), [
                    'body' => [
                        'client_id' => $azureClientId,
                        'client_secret' => $azureClientSecret,
                        'scope' => 'https://graph.microsoft.com/.default',
                        'grant_type' => 'client_credentials',
                    ],
                    'timeout' => 5,
                ]);

                $data = $response->toArray(false);
                if ($response->getStatusCode() === 200 && isset($data['access_token'])) {
                    $checks['checks']['azure_credentials'] = 'ok';
                } else {
                    $checks['checks']['azure_credentials'] = 'failed';
                    $checks['status'] = 'unhealthy';
                }
            } catch (\Exception $e) {
                $checks['checks']['azure_credentials'] = 'failed';
                $checks['status'] = 'unhealthy';
            }
        }

        $statusCode = $checks['status'] === 'healthy' ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        return new JsonResponse($checks, $statusCode);
    }
}
