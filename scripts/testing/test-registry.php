<?php

require_once 'vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use App\Kernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;

$dotenv = new Dotenv();
$dotenv->bootEnv(dirname(__FILE__).'/.env');

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);

// Get a controller instance to test
$container = $kernel->getContainer();
$controller = $container->get('App\Controller\IntegrationController');

// Get the registry through the controller
$refClass = new ReflectionClass($controller);
$method = $refClass->getMethod('new');
$params = $method->getParameters();

echo "Testing Integration Controller's 'new' method...\n\n";

// Create a mock request
$request = Request::create('/tools/new', 'GET');
$request->setSession(new Session());

// Manually get IntegrationRegistry service
try {
    // Simulate what controller does
    $integrationRegistry = null;

    // Use service locator approach
    $locator = $container->get('service_locator');
    if ($locator && $locator->has('App\Integration\IntegrationRegistry')) {
        $integrationRegistry = $locator->get('App\Integration\IntegrationRegistry');
    }

    if (!$integrationRegistry) {
        // Try direct instantiation with tagged services
        $taggedServices = [];
        $serviceIds = [
            'App\Integration\SystemTools\ShareFileIntegration',
            'App\Integration\UserIntegrations\JiraIntegration',
            'App\Integration\UserIntegrations\ConfluenceIntegration',
            'App\Integration\UserIntegrations\SharePointIntegration',
        ];

        foreach ($serviceIds as $serviceId) {
            if ($container->has($serviceId)) {
                $taggedServices[] = $container->get($serviceId);
            }
        }

        $integrationRegistry = new \App\Integration\IntegrationRegistry($taggedServices);
    }

    echo "Registry loaded with " . count($integrationRegistry->all()) . " integrations\n";
    echo "User integrations: " . count($integrationRegistry->getUserIntegrations()) . "\n\n";

    foreach ($integrationRegistry->getUserIntegrations() as $type => $integration) {
        echo "- $type: " . $integration->getName() . "\n";
        echo "  Requires credentials: " . ($integration->requiresCredentials() ? 'yes' : 'no') . "\n";
        echo "  Tools: " . count($integration->getTools()) . "\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}