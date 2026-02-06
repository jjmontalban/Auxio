<?php

/**
 * AUXIO - Fuente IGN: actividad sísmica via GeoRSS
 */

require_once __DIR__ . '/../Alert.php';

class IGNSource {
    private const FEED_URL = "https://www.ign.es/ign/RssTools/sismologia.xml";

    private const SEV_THRESHOLDS = [
        5.5 => "red",
        4.0 => "orange",
        2.5 => "yellow",
    ];

    /**
     * Map de magnitud a severidad (escala Richter)
     * < 2.5  : green   — generalmente no sentido
     * 2.5–4.0: yellow  — sentido, daños menores
     * 4.0–5.5: orange  — daños moderados
     * >= 5.5 : red     — daños significativos
     */
    private static function severityFromMagnitude(float $mag): string {
        if ($mag >= 5.5) return "red";
        if ($mag >= 4.0) return "orange";
        if ($mag >= 2.5) return "yellow";
        return "green";
    }

    /**
     * Descargar feed GeoRSS del IGN
     */
    private static function fetchFeed(): string {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: AUXIO/1.0\r\n",
                'timeout' => 30,
            ]
        ]);

        $response = @file_get_contents(self::FEED_URL, false, $context);
        if ($response === false) {
            throw new Exception("Error fetching IGN feed");
        }
        return $response;
    }

    /**
     * Extraer magnitud desde descripción (regex)
     * Ejemplo: "Se ha producido un terremoto de magnitud 3.1 en..."
     */
    private static function extractMagnitude(string $description): ?float {
        if (preg_match('/magnitud\s+([\d.]+)/', $description, $matches)) {
            return (float)$matches[1];
        }
        return null;
    }

    /**
     * Extraer región desde descripción
     */
    private static function extractRegion(string $description): ?string {
        if (preg_match('/magnitud\s+[\d.]+\s+en\s+(.+?)\s+en la fecha/', $description, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    /**
     * Extraer fecha desde descripción
     */
    private static function extractDate(string $description): ?string {
        if (preg_match('/en la fecha\s+(\d{2}\/\d{2}\/\d{4}\s+\d{1,2}:\d{2}:\d{2})/', $description, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Parsear feed GeoRSS en Alerts
     */
    private static function parseFeed(string $xml): array {
        $alerts = [];

        try {
            $root = new SimpleXMLElement($xml);
        } catch (Exception $e) {
            throw new Exception("Invalid RSS XML: {$e->getMessage()}");
        }

        $channel = $root->channel ?? $root;

        foreach ($channel->item ?? [] as $item) {
            $description = '';
            if (isset($item->description)) {
                $description = trim((string)$item->description);
            }

            // Extraer magnitud
            $magnitude = self::extractMagnitude($description);
            if ($magnitude === null) {
                continue;  // Skip sin magnitud
            }

            // Extraer región
            $region = self::extractRegion($description);

            // Extraer fecha
            $onset = self::extractDate($description);

            // Extraer coordenadas (GeoRSS)
            $coords = '';
            $namespaces = $item->getNamespaces(true);
            if (isset($namespaces['geo'])) {
                $geoNS = $item->children($namespaces['geo']);
                if (isset($geoNS->lat) && isset($geoNS->long)) {
                    $lat = trim((string)$geoNS->lat);
                    $lon = trim((string)$geoNS->long);
                    if ($lat && $lon) {
                        $coords = " ({$lat}, {$lon})";
                    }
                }
            }

            // Link a página de detalle
            $web = null;
            if (isset($item->link)) {
                $web = trim((string)$item->link);
            }

            $severity = self::severityFromMagnitude($magnitude);
            $headline = "Terremoto M{$magnitude}";
            if ($region) {
                $headline .= " en {$region}";
            }

            $fullDescription = "Magnitud {$magnitude}";
            if ($region) {
                $fullDescription .= " en {$region}";
            }
            if ($onset) {
                $fullDescription .= ", {$onset}";
            }
            if ($coords) {
                $fullDescription .= $coords;
            }

            $alerts[] = new Alert(
                source: 'ign',
                severity: $severity,
                headline: $headline,
                description: $fullDescription,
                area: $region,
                event_type: 'Terremoto',
                onset: $onset,
                sender: 'Instituto Geográfico Nacional',
                web: $web,
            );
        }

        return $alerts;
    }

    /**
     * Fetch: obtener actividad sísmica del IGN
     */
    public static function fetch(): array {
        try {
            $xml = self::fetchFeed();
            return self::parseFeed($xml);
        } catch (Exception $exc) {
            echo "[ign] Error fetching seismic data: {$exc->getMessage()}\n";
            return [];
        }
    }
}
