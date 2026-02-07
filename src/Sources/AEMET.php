<?php

/**
 * AUXIO - Fuente AEMET: avisos de fenómenos meteorológicos adversos via CAP
 */

require_once __DIR__ . '/../Alert.php';

class AEMETSource {
    private const API_KEY = null;  // Se lee de ENV
    private const BASE_URL = "https://opendata.aemet.es/opendata";
    private const ENDPOINT = "/api/avisos_cap/ultimoelaborado/area/{area}";
    private const CAP_NS = "urn:oasis:names:tc:emergency:cap:1.2";

    private const SEVERITY_MAP = [
        "Extreme" => "red",
        "Severe" => "orange",
        "Moderate" => "yellow",
        "Minor" => "green",
        "Unknown" => "yellow",
    ];

    private static function getApiKey(): string {
        return $_ENV['AEMET_API_KEY'] ?? getenv('AEMET_API_KEY') ?? '';
    }

    /**
     * Hacer solicitud HTTP GET con clave API
     */
    private static function request(string $url, string $accept = "application/json"): string {
        // Log the requested URL for debugging
        error_log("[AEMET] Requesting URL: {$url}");
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    "Accept: {$accept}",
                    "api_key: " . self::getApiKey(),
                    "User-Agent: AUXIO/1.0",
                ]),
                'timeout' => 30,
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        
        // Check if the request failed
        if ($response === false) {
            $errorMsg = "Error fetching {$url}";
            
            // Include HTTP headers if available for better diagnostics
            if (isset($http_response_header) && !empty($http_response_header)) {
                error_log("[AEMET] Response headers: " . implode(" | ", $http_response_header));
                $errorMsg .= " - Headers: " . implode("; ", $http_response_header);
            }
            
            throw new Exception($errorMsg);
        }
        
        // Check HTTP status code
        if (isset($http_response_header) && !empty($http_response_header)) {
            $statusLine = $http_response_header[0];
            
            // Extract status code from status line (e.g., "HTTP/1.1 200 OK")
            if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $statusLine, $matches)) {
                $statusCode = (int)$matches[1];
                
                // Check if status code is not in 2xx range
                if ($statusCode < 200 || $statusCode >= 300) {
                    error_log("[AEMET] Non-2xx response: {$statusLine}");
                    throw new Exception("Non-2xx response ({$statusLine}) from {$url}");
                }
            }
        }
        
        return $response;
    }

    /**
     * Paso 1: obtener la URL de datos del endpoint AEMET
     */
    private static function getDataUrl(string $area = "esp"): string {
        $url = self::BASE_URL . str_replace("{area}", $area, self::ENDPOINT);
        $body = json_decode(self::request($url), true);

        if (!isset($body['datos'])) {
            throw new Exception("AEMET response missing 'datos' field");
        }
        return $body['datos'];
    }

    /**
     * Verificar si el contenido es gzip válido
     */
    private static function isGzipData(string $data): bool {
        // Check gzip magic number (1f 8b)
        if (strlen($data) < 2) {
            return false;
        }
        
        $header = substr($data, 0, 2);
        return $header === "\x1f\x8b";
    }

    /**
     * Extraer archivos XML de un archivo tar.gz
     */
    private static function extractTarGz(string $data): array {
        // Validate that data is actually gzip format
        if (!self::isGzipData($data)) {
            $preview = substr($data, 0, 200);
            error_log("[AEMET] Data is not in gzip format. First 200 chars: " . $preview);
            throw new Exception("Response is not in gzip format. Received: " . $preview);
        }
        
        $xmlFiles = [];
        $tmpFile = tempnam(sys_get_temp_dir(), 'aemet_');
        $tmpDir = $tmpFile . '_dir';

        try {
            file_put_contents($tmpFile, $data);
            mkdir($tmpDir, 0755, true);

            $cmd = sprintf(
                'tar -xzf %s -C %s 2>&1',
                escapeshellarg($tmpFile),
                escapeshellarg($tmpDir)
            );
            exec($cmd, $output, $returnCode);

            if ($returnCode !== 0) {
                throw new Exception("Failed to extract tar.gz: " . implode(" ", $output));
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'xml') {
                    $xmlFiles[] = file_get_contents($file->getPathname());
                }
            }
        } finally {
            @unlink($tmpFile);
            if (is_dir($tmpDir)) {
                $delIterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($delIterator as $item) {
                    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                }
                @rmdir($tmpDir);
            }
        }

        return $xmlFiles;
    }

    /**
     * Obtener texto de un elemento XML
     */
    private static function getText(SimpleXMLElement $parent, string $tag): ?string {
        $element = $parent->children(self::CAP_NS);
        
        if (isset($element->{$tag})) {
            $text = (string)$element->{$tag};
            return !empty($text) ? trim($text) : null;
        }
        
        if (isset($parent->{$tag})) {
            $text = (string)$parent->{$tag};
            return !empty($text) ? trim($text) : null;
        }
        
        return null;
    }

    /**
     * Seleccionar bloque <info> en español
     */
    private static function pickSpanish(array $infos): ?SimpleXMLElement {
        foreach ($infos as $info) {
            $lang = self::getText($info, 'language') ?? '';
            if (stripos($lang, 'es') === 0) {
                return $info;
            }
        }
        return null;
    }

    /**
     * Parsear CAP 1.2 XML en Alerts
     */
    private static function parseCAP(string $xml): array {
        $alerts = [];
        
        try {
            $root = new SimpleXMLElement($xml);
        } catch (Exception $e) {
            throw new Exception("Invalid CAP XML: {$e->getMessage()}");
        }

        $alertElements = [];

        // Si la raíz es <alert>, procesarla directamente
        if ($root->getName() === 'alert') {
            $alertElements[] = $root;
        } else {
            // Buscar elementos <alert> hijos (con y sin namespace)
            $children = $root->children(self::CAP_NS);
            if (isset($children->alert)) {
                foreach ($children->alert as $alert) {
                    $alertElements[] = $alert;
                }
            }

            if (empty($alertElements) && isset($root->alert)) {
                foreach ($root->alert as $alert) {
                    $alertElements[] = $alert;
                }
            }
        }

        foreach ($alertElements as $alertEl) {
            $sender = self::getText($alertEl, 'sender');
            
            $infoElements = [];
            $infoChildren = $alertEl->children(self::CAP_NS);
            if (isset($infoChildren->info)) {
                foreach ($infoChildren->info as $info) {
                    $infoElements[] = $info;
                }
            }

            if (empty($infoElements) && isset($alertEl->info)) {
                foreach ($alertEl->info as $info) {
                    $infoElements[] = $info;
                }
            }
            
            if (empty($infoElements)) {
                continue;
            }

            $info = self::pickSpanish($infoElements) ?? $infoElements[0];

            $severityRaw = self::getText($info, 'severity') ?? 'Unknown';
            $severity = self::SEVERITY_MAP[$severityRaw] ?? 'yellow';
            
            $headline = self::getText($info, 'headline') ?? '';
            $description = self::getText($info, 'description') ?? $headline;
            $eventType = self::getText($info, 'event');
            $onset = self::getText($info, 'onset');
            $expires = self::getText($info, 'expires');
            $certainty = self::getText($info, 'certainty');
            $urgency = self::getText($info, 'urgency');
            $web = self::getText($info, 'web');

            // Recopilar descripciones de área
            $areaParts = [];
            $areaChildren = $info->children(self::CAP_NS);
            if (isset($areaChildren->area)) {
                foreach ($areaChildren->area as $area) {
                    $areaDesc = self::getText($area, 'areaDesc');
                    if ($areaDesc) {
                        $areaParts[] = $areaDesc;
                    }
                }
            }

            if (empty($areaParts) && isset($info->area)) {
                foreach ($info->area as $area) {
                    $areaDesc = self::getText($area, 'areaDesc');
                    if ($areaDesc) {
                        $areaParts[] = $areaDesc;
                    }
                }
            }

            $areaStr = !empty($areaParts) ? implode("; ", $areaParts) : null;

            $alerts[] = new Alert(
                source: 'aemet',
                severity: $severity,
                headline: $headline,
                description: $description,
                area: $areaStr,
                event_type: $eventType,
                onset: $onset,
                expires: $expires,
                certainty: $certainty,
                urgency: $urgency,
                sender: $sender,
                web: $web,
            );
        }

        return $alerts;
    }

    /**
     * Fetch actualizado: obtener alertas de AEMET
     *
     * El endpoint datos devuelve un archivo tar.gz con múltiples
     * ficheros CAP XML individuales (uno por aviso/zona).
     */
    public static function fetch(): array {
        $apiKey = self::getApiKey();
        if (empty($apiKey)) {
            echo "[aemet] AEMET_API_KEY not set, skipping.\n";
            return [];
        }

        try {
            $datosUrl = self::getDataUrl('esp');
            $rawData = self::request($datosUrl, '*/*');

            // Detectar si es XML directo o tar.gz
            $trimmed = ltrim($rawData);
            if (str_starts_with($trimmed, '<?xml') || str_starts_with($trimmed, '<')) {
                return self::parseCAP($rawData);
            }

            // Es un archivo tar.gz: extraer los XMLs individuales
            $xmlFiles = self::extractTarGz($rawData);

            if (empty($xmlFiles)) {
                echo "[aemet] No XML files found in archive.\n";
                return [];
            }

            $allAlerts = [];
            foreach ($xmlFiles as $xmlContent) {
                try {
                    $alerts = self::parseCAP($xmlContent);
                    $allAlerts = array_merge($allAlerts, $alerts);
                } catch (Exception $e) {
                    // Saltar archivos XML inválidos
                    continue;
                }
            }

            return $allAlerts;
        } catch (Exception $exc) {
            echo "[aemet] Error fetching alerts: {$exc->getMessage()}\n";
            return [];
        }
    }
}
