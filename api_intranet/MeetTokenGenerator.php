<?php

require_once __DIR__ . '/vendor/autoload.php';

use Google\Client;

$credentialsPath = __DIR__ . '/config/secrets/google-credentials.json';
$tokenPath = __DIR__ . '/config/secrets/token.json';

if (!file_exists($credentialsPath)) {
    die("Error: Credentials file not found at '$credentialsPath'\n");
}

$client = new Client();
$client->setAuthConfig($credentialsPath);
$client->addScope('https://www.googleapis.com/auth/meetings.space.created');
$client->setAccessType('offline');
$client->setPrompt('select_account consent');
$client->setRedirectUri('https://intranet.pafar.com.ve/ambiente_prueba_intranet/public/api/auth/google/callback');

if (file_exists($tokenPath)) {
    echo "Existing token.json found. Deleting it to start a new authorization...\n";
    unlink($tokenPath);
}

// Generate the URL for the user to log in
$authUrl = $client->createAuthUrl();

echo "\n=======================================================\n";
echo "             GOOGLE MEET TOKEN GENERATOR\n";
echo "=======================================================\n";
echo "\n1. Open the following URL in your web browser:\n\n";
echo $authUrl . "\n\n";
echo "2. Log in with the NEW Google Account and grant all requested permissions.\n";
echo "3. You will be redirected to the callback URL (it might show an error page, that is normal!).\n";
echo "4. Look at the browser address bar, find the 'code' parameter, and copy it.\n";
echo "   (Example: ?code=4/0Adyx...)\n\n";
echo "5. Paste the authorization code here and press ENTER: ";

$handle = fopen("php://stdin", "r");
$authCode = trim(fgets($handle));

if (empty($authCode)) {
    die("Error: No code provided.\n");
}

try {
    // Exchange authorization code for access and refresh tokens
    $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
    
    if (isset($accessToken['error'])) {
        throw new \Exception(implode(', ', $accessToken));
    }
    
    file_put_contents($tokenPath, json_encode($accessToken));
    echo "\n-------------------------------------------------------\n";
    echo "SUCCESS! New token.json generated at:\n";
    echo $tokenPath . "\n";
    echo "-------------------------------------------------------\n";
    echo "You can now copy and upload this new token.json file to your cPanel secrets directory.\n";
} catch (\Exception $e) {
    echo "\nError exchanging code: " . $e->getMessage() . "\n";
}
fclose($handle);
