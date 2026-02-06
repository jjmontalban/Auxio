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
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new Exception("Error fetching {$url}");
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
        
        // Manejo de namespace
        $children = $root->children(self::CAP_NS);
        if (isset($children->alert)) {
            foreach ($children->alert as $alert) {
                $alertElements[] = $alert;
            }
        }
        
        if (isset($root->alert)) {
            foreach ($root->alert as $alert) {
                $alertElements[] = $alert;
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
            
            if (isset($alertEl->info)) {
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
            
            if (isset($info->area)) {
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
     */
    public static function fetch(): array {
        $apiKey = self::getApiKey();
        if (empty($apiKey)) {
            echo "[aemet] AEMET_API_KEY not set, skipping.\n";
            return [];
        }

        try {
            $datosUrl = self::getDataUrl('esp');
            $xmlBytes = self::request($datosUrl, 'application/xml');
            return self::parseCAP($xmlBytes);
        } catch (Exception $exc) {
            echo "[aemet] Error fetching alerts: {$exc->getMessage()}\n";
            return [];
        }
    }
}
