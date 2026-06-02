<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

class NasStorageService
{
    private $webdavUrl;
    private $webdavUser;
    private $webdavPassword;
    private $webdavPath;
    private $logger;

    public function __construct(
        string $webdavUrl,
        string $webdavUser,
        string $webdavPassword,
        string $webdavPath,
        LoggerInterface $logger
    ) {
        $this->webdavUrl = rtrim($webdavUrl, '/');
        $this->webdavUser = $webdavUser;
        $this->webdavPassword = $webdavPassword;
        $this->webdavPath = '/' . trim($webdavPath, '/');
        $this->logger = $logger;
    }

    /**
     * Helper to execute a native cURL WebDAV request.
     */
    private function executeRequest(string $method, string $path, array $headers = [], $body = null, bool $returnRaw = false): array
    {
        $path = '/' . ltrim($path, '/');

        // URL-encode path segments (e.g., spaces to %20) while preserving slashes
        $parts = explode('/', $path);
        $encodedParts = array_map('rawurlencode', $parts);
        $encodedPath = implode('/', $encodedParts);

        // Note: rawurlencode will turn empty parts (like double slashes) into empty strings, 
        // but it's safe since we reconstruct with slashes.
        $url = $this->webdavUrl . $encodedPath;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects (e.g., HTTP to HTTPS or trailing slashes)

        // Setup Basic Auth
        curl_setopt($ch, CURLOPT_USERPWD, $this->webdavUser . ":" . $this->webdavPassword);

        // Security bypass for local self-signed certificates
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->logger->error("cURL error during WebDAV {$method} to {$path}: {$curlError}");
            throw new \Exception("Error de conexión con el almacenamiento NAS: " . $curlError);
        }

        return [
            'code' => $httpCode,
            'body' => $response
        ];
    }

    /**
     * Sanitizes directory path to prevent directory traversal attacks (e.g. "../").
     */
    private function sanitizePath(string $path): string
    {
        $path = str_replace(['..', '\\'], ['', '/'], $path);
        $path = preg_replace('/\/+/', '/', $path);
        return trim($path, '/');
    }

    /**
     * Lists files and folders in a given subdirectory of the shared path.
     */
    public function listFiles(string $subPath = ''): array
    {
        $subPath = $this->sanitizePath($subPath);
        $fullPath = $this->webdavPath;
        if ($subPath !== '') {
            $fullPath .= '/' . $subPath;
        }

        // Ensure directory paths always end with a trailing slash to prevent 307 redirects to GET (405 Method Not Allowed)
        $fullPath = rtrim($fullPath, '/') . '/';

        // WebDAV PROPFIND request
        $headers = [
            'Depth: 1',
            'Content-Type: application/xml; charset="utf-8"'
        ];

        // XML request body for specific properties
        $xmlBody = '<?xml version="1.0" encoding="utf-8" ?>
        <D:propfind xmlns:D="DAV:">
            <D:prop>
                <D:displayname/>
                <D:getcontentlength/>
                <D:getlastmodified/>
                <D:resourcetype/>
            </D:prop>
        </D:propfind>';

        $res = $this->executeRequest('PROPFIND', $fullPath, $headers, $xmlBody);

        // 207 Multi-Status is the standard successful response for PROPFIND
        if ($res['code'] !== 207) {
            $this->logger->error("WebDAV PROPFIND returned HTTP code {$res['code']}. Response: {$res['body']}");
            throw new \Exception("El almacenamiento NAS respondió con código " . $res['code']);
        }

        return $this->parsePropfindXml($res['body'], $fullPath);
    }

    /**
     * Parses the WebDAV PROPFIND XML response using namespace-agnostic XPath queries.
     */
    private function parsePropfindXml(string $xmlContent, string $requestedPath): array
    {
        $files = [];

        try {
            // Disable entity loading for security (prevent XXE injection)
            $backupEntityLoader = libxml_disable_entity_loader(true);
            $xml = new \SimpleXMLElement($xmlContent);
            libxml_disable_entity_loader($backupEntityLoader);

            // Use namespace-agnostic XPath for maximum robustness
            $responses = $xml->xpath('//*[local-name()="response"]');
            if ($responses === false) {
                return [];
            }

            // The root requested path folder itself is typically returned as the first element.
            // We want to skip it in the files list.
            $normalizedRequestedPath = '/' . trim(urldecode($requestedPath), '/');

            foreach ($responses as $responseNode) {
                $hrefList = $responseNode->xpath('.//*[local-name()="href"]');
                if (empty($hrefList)) {
                    continue;
                }

                $href = urldecode((string)$hrefList[0]);
                // Remove host/port info if returned as absolute URL
                $hrefPath = '/' . trim(parse_url($href, PHP_URL_PATH), '/');

                // Skip the folder itself
                if ($hrefPath === $normalizedRequestedPath) {
                    continue;
                }

                // Get properties
                $propNodeList = $responseNode->xpath('.//*[local-name()="prop"]');
                if (empty($propNodeList)) {
                    continue;
                }
                $propNode = $propNodeList[0];

                // Name
                $displayNameList = $propNode->xpath('.//*[local-name()="displayname"]');
                $name = !empty($displayNameList) ? (string)$displayNameList[0] : basename($hrefPath);

                // Size
                $sizeList = $propNode->xpath('.//*[local-name()="getcontentlength"]');
                $size = !empty($sizeList) ? (int)$sizeList[0] : 0;

                // Date
                $dateList = $propNode->xpath('.//*[local-name()="getlastmodified"]');
                $updatedAt = !empty($dateList) ? (string)$dateList[0] : '';

                // Is Directory?
                $isDir = false;
                $resourcetypeList = $propNode->xpath('.//*[local-name()="resourcetype"]');
                if (!empty($resourcetypeList)) {
                    $collectionList = $resourcetypeList[0]->xpath('.//*[local-name()="collection"]');
                    if (!empty($collectionList)) {
                        $isDir = true;
                    }
                }

                // Map format
                $files[] = [
                    'name' => $name,
                    'path' => $subPath = ($requestedPath === $this->webdavPath) ? $name : basename($requestedPath) . '/' . $name,
                    'size' => $isDir ? 0 : $size,
                    'isDir' => $isDir,
                    'updatedAt' => $updatedAt
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to parse WebDAV XML output: " . $e->getMessage() . ". Raw XML: " . $xmlContent);
            throw new \Exception("Error al procesar el listado de archivos del almacenamiento.");
        }

        return $files;
    }

    /**
     * Downloads a file content from the NAS.
     */
    public function downloadFile(string $filePath): array
    {
        $filePath = $this->sanitizePath($filePath);
        $fullPath = $this->webdavPath . '/' . $filePath;

        $res = $this->executeRequest('GET', $fullPath);

        if ($res['code'] !== 200) {
            $this->logger->error("WebDAV GET returned HTTP code {$res['code']} for {$filePath}");
            throw new \Exception("No se pudo descargar el archivo del NAS (Código {$res['code']})");
        }

        // Guess mime type from path
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeTypes = [
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'txt' => 'text/plain',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'zip' => 'application/zip',
        ];
        $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';

        return [
            'name' => basename($filePath),
            'contentType' => $contentType,
            'content' => $res['body']
        ];
    }

    /**
     * Uploads/Overwrites a file on the NAS.
     */
    public function uploadFile(string $filePath, $content): bool
    {
        $filePath = $this->sanitizePath($filePath);
        $fullPath = $this->webdavPath . '/' . $filePath;

        // PUT request
        $res = $this->executeRequest('PUT', $fullPath, [], $content);

        // 201 Created or 204 No Content represent successful upload
        if ($res['code'] !== 201 && $res['code'] !== 204) {
            $this->logger->error("WebDAV PUT returned HTTP code {$res['code']} for {$filePath}. Response: {$res['body']}");
            throw new \Exception("No se pudo subir el archivo al NAS (Código {$res['code']})");
        }

        return true;
    }

    /**
     * Deletes a file or directory from the NAS.
     */
    public function deleteFile(string $filePath): bool
    {
        $filePath = $this->sanitizePath($filePath);
        $fullPath = $this->webdavPath . '/' . $filePath;

        $res = $this->executeRequest('DELETE', $fullPath);

        // 204 No Content is the standard success code for delete
        if ($res['code'] !== 204 && $res['code'] !== 200) {
            $this->logger->error("WebDAV DELETE returned HTTP code {$res['code']} for {$filePath}. Response: {$res['body']}");
            throw new \Exception("No se pudo borrar el archivo en el NAS (Código {$res['code']})");
        }

        return true;
    }

    /**
     * Creates a new directory on the NAS (MKCOL).
     */
    public function createDirectory(string $dirPath): bool
    {
        $dirPath = $this->sanitizePath($dirPath);
        $fullPath = $this->webdavPath . '/' . $dirPath;

        $res = $this->executeRequest('MKCOL', $fullPath);

        // 201 Created is the success code
        if ($res['code'] !== 201) {
            $this->logger->error("WebDAV MKCOL returned HTTP code {$res['code']} for {$dirPath}. Response: {$res['body']}");
            throw new \Exception("No se pudo crear la carpeta en el NAS (Código {$res['code']})");
        }

        return true;
    }
}
