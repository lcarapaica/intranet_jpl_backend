<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Service to manage SSO webmail session generation via cPanel UAPI.
 */
class CpanelWebmailService
{
    private $cpanelHost;
    private $cpanelPort;
    private $cpanelUsername;
    private $cpanelApiToken;
    private $logger;

    public function __construct(
        string $cpanelHost,
        string $cpanelPort,
        string $cpanelUsername,
        string $cpanelApiToken,
        LoggerInterface $logger
    ) {
        $this->cpanelHost = $cpanelHost;
        $this->cpanelPort = $cpanelPort;
        $this->cpanelUsername = $cpanelUsername;
        $this->cpanelApiToken = $cpanelApiToken;
        $this->logger = $logger;
    }

    /**
     * Creates a temporary webmail session for the given email address.
     *
     * @param string $email The full email address of the mailbox (e.g. user@company.com)
     * @return array Contains 'session', 'token', and 'hostname' required for login
     * @throws \Exception If the integration fails
     */
    public function createWebmailSession(string $email): array
    {
        // Detect if the credentials are placeholders or empty
        if (
            empty($this->cpanelApiToken) ||
            $this->cpanelApiToken === 'cpanel_api_token' ||
            empty($this->cpanelUsername) ||
            $this->cpanelUsername === 'cpanel_user'
        ) {
            throw new \Exception("Las credenciales de cPanel no están configuradas correctamente.");
        }

        // Validate email structure and split it
        $email = trim($email);
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            throw new \Exception("El formato del correo electrónico es inválido.");
        }

        $login = $parts[0];
        $domain = $parts[1];

        // Build UAPI endpoint and payload
        // Reference: UAPI Module 'Session' -> Function 'create_webmail_session_for_mail_user'
        $url = sprintf(
            "https://%s:%s/execute/Session/create_webmail_session_for_mail_user",
            $this->cpanelHost,
            $this->cpanelPort
        );

        $payload = [
            'login' => $login,
            'domain' => $domain,
        ];

        // Initialize cURL request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Define cPanel Authorization format: "cpanel {user}:{token}"
        $headers = [
            sprintf("Authorization: cpanel %s:%s", $this->cpanelUsername, $this->cpanelApiToken),
            "Content-Type: application/x-www-form-urlencoded"
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Security bypass for local development or self-signed certs
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Fail fast

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Handle transport errors
        if ($response === false) {
            $this->logger->error("cURL error communicating with cPanel API: {$curlError}");
            throw new \Exception("Error de conexión al servidor de correo: {$curlError}");
        }

        if ($httpCode !== 200) {
            $this->logger->error("cPanel API responded with unexpected HTTP code: {$httpCode}. Response: {$response}");
            throw new \Exception("El servidor de correo respondió con un error (código HTTP {$httpCode}).");
        }

        // Decode and validate JSON response
        $data = json_decode($response, true);
        if (!$data) {
            $this->logger->error("Failed to parse cPanel API JSON response. Raw response: {$response}");
            throw new \Exception("Error al procesar la respuesta del servidor de correo.");
        }

        $result = isset($data['result']) ? $data['result'] : $data;
        $status = $result['status'] ?? 0;

        if ($status !== 1) {
            $errors = $result['errors'] ?? ['Error desconocido en la API de cPanel'];
            $errorMessage = implode(' | ', $errors);
            $this->logger->warning("cPanel session creation failed for email {$email}: {$errorMessage}");

            // Clean up cPanel's cryptic hosting/error messages if it fails due to a missing/invalid mailbox
            if (stripos($errorMessage, 'The request failed') !== false || stripos($errorMessage, 'cPanel & WHM') !== false) {
                $errorMessage = "La cuenta de correo electrónico no existe o no está configurada en el servidor.";
                throw new \Exception($errorMessage);
            }

            throw new \Exception("No se pudo crear la sesión de correo: " . $errorMessage);
        }

        // Extract session data
        $sessionData = $result['data'] ?? [];
        $session = $sessionData['session'] ?? null;
        $token = $sessionData['token'] ?? null;
        // Fallback to the requested hostname if the API returns null/empty hostname
        $hostname = $sessionData['hostname'] ?? null;
        if (empty($hostname)) {
            $hostname = $this->cpanelHost;
        }

        if (!$session || !$token) {
            $this->logger->error("cPanel API succeeded but missing key parameters 'session' or 'token' in data: " . json_encode($sessionData));
            throw new \Exception("Respuesta de sesión incompleta del servidor de correo.");
        }

        $this->logger->info("Successfully generated cPanel Webmail SSO session for user: {$email}");

        return [
            'session'  => $session,
            'token'    => $token,
            'hostname' => $hostname
        ];
    }
}
