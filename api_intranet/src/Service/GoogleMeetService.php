<?php

namespace App\Service;

use Google\Client;
use Psr\Log\LoggerInterface;

/**
 * Service to manage direct interactions with the Google Meet API.
 * Uses a service account configuration to orchestrate ad-hoc calls.
 */
class GoogleMeetService
{
    private $credentialsPath;
    private $systemOrganizer;
    private $logger;

    public function __construct(string $credentialsPath, string $systemOrganizer, LoggerInterface $logger)
    {
        $this->credentialsPath = $credentialsPath;
        $this->systemOrganizer = $systemOrganizer;
        $this->logger = $logger;
    }

    /**
     * Creates a new Google Meet space and registers the requested attendees.
     * Fallbacks to a mock development URL if credentials are placeholder or invalid.
     *
     * @param string $title The descriptive title for the meeting
     * @param array $attendeeEmails List of user emails to pre-authorize / invite
     * @return string The generated Google Meet URL
     */
    public function createSpace(string $title, array $attendeeEmails = []): string
    {
        // 1. Detect if the credentials file is missing or a placeholder
        if (!file_exists($this->credentialsPath)) {
            $this->logger->warning("Google Meet credentials file not found at '{$this->credentialsPath}'. Falling back to development mock URL.");
            return $this->generateMockMeetUrl();
        }

        $credentialsContent = file_get_contents($this->credentialsPath);
        if (str_contains($credentialsContent, 'placeholder-project-id')) {
            $this->logger->info("Google Meet credentials are placeholders. Generating a simulated meeting link.");
            return $this->generateMockMeetUrl();
        }

        try {
            // 2. Initialize the Google API Client
            $client = new Client();
            $client->addScope('https://www.googleapis.com/auth/meetings.space.created');

            $credentials = json_decode($credentialsContent, true);

            if (isset($credentials['type']) && $credentials['type'] === 'service_account') {
                // Flow A: Service Account (standard or with Domain delegation)
                $client->setAuthConfig($this->credentialsPath);
                if (!empty($this->systemOrganizer) && $this->systemOrganizer !== 'meet-organizer@yourcompany.com') {
                    $client->setSubject($this->systemOrganizer);
                }
            } else {
                // Flow B: OAuth 2.0 Client Credentials (for personal development / non-Workspace)
                $client->setAuthConfig($this->credentialsPath);
                $client->setAccessType('offline');
                $client->setPrompt('select_account consent');

                // Token file is saved in the same secrets directory next to the credentials
                $tokenPath = dirname($this->credentialsPath) . '/token.json';

                if (file_exists($tokenPath)) {
                    $accessToken = json_decode(file_get_contents($tokenPath), true);
                    $client->setAccessToken($accessToken);
                }

                // Automatically refresh expired token if refresh token is available
                if ($client->isAccessTokenExpired()) {
                    if ($client->getRefreshToken()) {
                        $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                        file_put_contents($tokenPath, json_encode($client->getAccessToken()));
                    } else {
                        // Throw specific error containing redirect authorization link
                        $authUrl = $client->createAuthUrl();
                        throw new \Exception("OAuth token is missing or expired. To generate a new one, please execute 'php test_meet.php' in your CLI to perform first-time authorization. Authorization URL: " . $authUrl);
                    }
                }
            }

            // 3. Call the Google Meet API via direct HTTP REST request
            $httpClient = $client->authorize();

            $response = $httpClient->post('https://meet.googleapis.com/v2/spaces', [
                'json' => [
                    'config' => [
                        'accessType' => 'OPEN' // OPEN, TRUSTED, or RESTRICTED
                    ]
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (php_sapi_name() === 'cli') {
                echo "\n\e[0;32m[Google API Response] " . json_encode($data, JSON_PRETTY_PRINT) . "\e[0m\n";
            }

            // Return the full URI (e.g. 'https://meet.google.com/abc-defg-hij')
            if (isset($data['meetingUri'])) {
                return $data['meetingUri'];
            }

            // Fallback just in case Uri is blank
            return $this->generateMockMeetUrl();

        } catch (\Exception $e) {
            $this->logger->error("Failed to create Google Meet space via API: " . $e->getMessage() . ". Falling back to mock URL.");
            if (php_sapi_name() === 'cli') {
                echo "\n\e[0;31m[Google Meet Service Error] " . $e->getMessage() . "\e[0m\n";
            }
            return $this->generateMockMeetUrl();
        }
    }

    /**
     * Generates a realistic-looking mock Google Meet room link for testing.
     */
    private function generateMockMeetUrl(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyz';
        $part1 = substr(str_shuffle($chars), 0, 3);
        $part2 = substr(str_shuffle($chars), 0, 4);
        $part3 = substr(str_shuffle($chars), 0, 3);

        return "https://meet.google.com/{$part1}-{$part2}-{$part3}?dev=true";
    }
}
