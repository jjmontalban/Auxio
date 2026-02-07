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
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    "Accept: {$accept}",
                    "api_key: " . self::getApiKey(),
                    "User-Agent: AUXIO/1.0",
                ]),
                'timeout' => 30,
                'ignore_errors' => true, // Allow capturing HTTP error responses
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $error = error_get_last();
            throw new Exception("Error fetching {$url}: " . ($error['message'] ?? 'Unknown error'));
        }
        
        // Verificar código de respuesta HTTP
        if (isset($http_response_header)) {
            $statusLine = $http_response_header[0] ?? '';
            preg_match('/HTTP\/\d\.\d\s+(\d+)/', $statusLine, $matches);
            $statusCode = $matches[1] ?? 0;
            
            if ($statusCode >= 400) {
                throw new Exception("HTTP {$statusCode} error for {$url}");
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
     * Extraer archivos XML de un archivo tar.gz
     */
    private static function extractTarGz(string $data): array {
        $xmlFiles = [];
        $tmpFile = tempnam(sys_get_temp_dir(), 'aemet_');
        $tmpDir = $tmpFile . '_dir';

        try {
            // Validar que el archivo es gzip antes de intentar extraer
            if (strlen($data) < 2 || substr($data, 0, 2) !== "\x1f\x8b") {
                throw new Exception("Data is not in gzip format");
            }

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

            // Validar que tenemos datos
            if (empty($rawData)) {
                echo "[aemet] Empty response from API.\n";
                return [];
            }

            // Detectar formato de respuesta
            $trimmed = ltrim($rawData);
            
            // 1. Verificar si es JSON (posible error del API)
            if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
                $jsonData = json_decode($rawData, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                    // Es una respuesta JSON, probablemente un error
                    $errorMsg = $jsonData['descripcion'] ?? $jsonData['mensaje'] ?? 'Unknown JSON response';
                    echo "[aemet] API returned JSON response: {$errorMsg}\n";
                    return [];
                }
            }
            
            // 2. Verificar respuesta no comprimida de AEMET (sin datos disponibles)
            // La API de AEMET devuelve respuestas que empiezan con "Z_" (ej: "Z_CAP_C_AEMET...")
            // cuando no hay datos de avisos disponibles. Estas respuestas no son archivos tar.gz.
            if (str_starts_with($rawData, 'Z_')) {
                echo "[aemet] API returned a non-compressed response (no data available)\n";
                return [];
            }
            
            // 3. Verificar si es XML directo
            if (str_starts_with($trimmed, '<?xml') || str_starts_with($trimmed, '<')) {
                return self::parseCAP($rawData);
            }

            // 4. Verificar si es realmente un archivo gzip usando magic bytes
            $magicBytes = substr($rawData, 0, 2);
            if ($magicBytes !== "\x1f\x8b") {
                // No es un archivo gzip válido
                echo "[aemet] Response is not in gzip format. First bytes: " . bin2hex($magicBytes) . "\n";
                
                // Intentar mostrar más información si parece ser texto
                if (ctype_print(substr($rawData, 0, 100))) {
                    echo "[aemet] Response preview: " . substr($rawData, 0, 100) . "\n";
                }
                return [];
            }

            // 5. Es un archivo tar.gz: extraer los XMLs individuales
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
