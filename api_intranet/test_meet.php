<?php

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Google\Client;

require __DIR__.'/vendor/autoload.php';

if (!file_exists(__DIR__.'/.env')) {
    echo "Error: .env file not found at the root.\n";
    exit(1);
}

(new Dotenv())->bootEnv(__DIR__.'/.env');

echo "Booting Symfony Kernel to verify Google Meet API Integration...\n";
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? true));
$kernel->boot();

$container = $kernel->getContainer();
$meetService = $container->get(\App\Service\GoogleMeetService::class);

$credentialsPath = $_ENV['GOOGLE_CREDENTIALS_PATH'] ?? 'config/secrets/google-credentials.json';
$credentialsPath = str_replace('%kernel.project_dir%', __DIR__, $credentialsPath);
$credentialsPath = trim($credentialsPath, '"\'');

if (!file_exists($credentialsPath)) {
    echo "Error: Google credentials file not found at '{$credentialsPath}'.\n";
    exit(1);
}

$credentials = json_decode(file_get_contents($credentialsPath), true);
$isServiceAccount = isset($credentials['type']) && $credentials['type'] === 'service_account';

echo "----------------------------------------\n";
echo "Credentials Type Detected: " . ($isServiceAccount ? "Google Service Account" : "Google OAuth Client ID") . "\n";
echo "Credentials Path: {$credentialsPath}\n";
echo "----------------------------------------\n";

if (!$isServiceAccount) {
    $tokenPath = dirname($credentialsPath) . '/token.json';
    
    // Check if we need to perform initial CLI-based authorization
    if (!file_exists($tokenPath)) {
        echo "No saved OAuth token found at '{$tokenPath}'. Starting CLI authorization...\n\n";
        
        $client = new Client();
        $client->setAuthConfig($credentialsPath);
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');
        $client->addScope('https://www.googleapis.com/auth/meetings.space.created');
        
        $authUrl = $client->createAuthUrl();
        echo "1. Please open the following URL in your web browser to authorize the application:\n\n";
        echo "   \e[1;36m" . $authUrl . "\e[0m\n\n";
        echo "2. After logging in, you will be redirected to a redirect URI (or a blank screen/local error).\n";
        echo "3. Copy the 'code' parameter from the URL in your browser's address bar (e.g. code=4/0Af...).\n\n";
        
        echo "Enter the Authorization Code: ";
        $authCode = trim(fgets(STDIN));
        
        if (empty($authCode)) {
            echo "Error: Authorization code cannot be empty.\n";
            exit(1);
        }
        
        try {
            // Exchange the code for an Access and Refresh Token
            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
            if (isset($accessToken['error'])) {
                throw new \Exception(implode(', ', $accessToken));
            }
            
            // Save token
            file_put_contents($tokenPath, json_encode($accessToken));
            echo "\n🎉 SUCCESS! Token successfully generated and saved to '{$tokenPath}'.\n\n";
        } catch (\Exception $e) {
            echo "❌ Failed to exchange authorization code: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
}

// Perform active API creation test
echo "Running active Google Meet Space creation test...\n";
try {
    $url = $meetService->createSpace("Prueba de Conexión Intranet JPL", ["test-participant@example.com"]);
    echo "\n";
    if (str_contains($url, 'dev=true')) {
        echo "⚠️  Note: The service used the simulated development fallback URL:\n";
        echo "URL: $url\n\n";
        echo "Please make sure your credentials file is valid.\n";
    } else {
        echo "🎉 SUCCESS! Successfully generated real Google Meet Link:\n";
        echo "URL: \e[1;32m{$url}\e[0m\n\n";
        echo "The Google Meet API integration is fully operational!\n";
    }
} catch (\Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
echo "----------------------------------------\n";
