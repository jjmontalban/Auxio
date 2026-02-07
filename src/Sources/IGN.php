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

    /** Códigos de provincia (matrículas) usados por IGN */
    private const PROVINCE_CODES = [
        'A' => 'Alicante', 'AB' => 'Albacete', 'AL' => 'Almería',
        'AV' => 'Ávila', 'B' => 'Barcelona', 'BA' => 'Badajoz',
        'BI' => 'Vizcaya', 'BU' => 'Burgos', 'C' => 'A Coruña',
        'CA' => 'Cádiz', 'CC' => 'Cáceres', 'CE' => 'Ceuta',
        'CO' => 'Córdoba', 'CR' => 'Ciudad Real', 'CS' => 'Castellón',
        'CU' => 'Cuenca', 'GC' => 'Las Palmas', 'GI' => 'Girona',
        'GR' => 'Granada', 'GU' => 'Guadalajara', 'H' => 'Huelva',
        'HU' => 'Huesca', 'IB' => 'Illes Balears', 'J' => 'Jaén',
        'L' => 'Lleida', 'LE' => 'León', 'LO' => 'La Rioja',
        'LU' => 'Lugo', 'M' => 'Madrid', 'MA' => 'Málaga',
        'ML' => 'Melilla', 'MU' => 'Murcia', 'NA' => 'Navarra',
        'O' => 'Asturias', 'OR' => 'Ourense', 'P' => 'Palencia',
        'PO' => 'Pontevedra', 'S' => 'Cantabria', 'SA' => 'Salamanca',
        'SE' => 'Sevilla', 'SG' => 'Segovia', 'SO' => 'Soria',
        'SS' => 'Guipúzcoa', 'T' => 'Tarragona', 'TE' => 'Teruel',
        'TF' => 'Sta. Cruz de Tenerife', 'TO' => 'Toledo',
        'V' => 'Valencia', 'VA' => 'Valladolid', 'VI' => 'Álava',
        'Z' => 'Zaragoza', 'ZA' => 'Zamora',
    ];

    /** Direcciones cardinales a texto completo */
    private const DIRECTION_NAMES = [
        'N' => 'Norte', 'S' => 'Sur', 'E' => 'Este', 'W' => 'Oeste',
        'NW' => 'Noroeste', 'NE' => 'Noreste',
        'SW' => 'Suroeste', 'SE' => 'Sureste',
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
     * Convertir texto a Title Case respetando artículos/preposiciones españolas
     */
    private static function toTitleCase(string $str): string {
        $str = mb_convert_case(mb_strtolower($str, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        // Minúscula en artículos/preposiciones que no sean la primera palabra
        $articles = ['De', 'Del', 'La', 'El', 'Las', 'Los', 'En'];
        foreach ($articles as $art) {
            $lower = mb_strtolower($art, 'UTF-8');
            $str = preg_replace('/(?<=\s)' . preg_quote($art, '/') . '(?=\s)/u', $lower, $str);
        }
        return $str;
    }

    /**
     * Comprobar si la región es española o zona marítima monitoreada por IGN
     *
     * Descarta solo alertas con código de país tras punto (.MAC, .ARG, etc.)
     * Mantiene zonas costeras/marítimas con guión (ATLÁNTICO-GALICIA, etc.)
     */
    private static function isSpanishRegion(string $raw): bool {
        $body = $raw;
        if (preg_match('/^(NW|NE|SW|SE|N|S|E|W)\s+(.+)$/u', $raw, $m)) {
            $body = $m[2];
        }
        // Código tras el punto → español solo si es provincia conocida
        if (preg_match('/\.([A-Z]{1,3})$/u', $body, $m)) {
            return isset(self::PROVINCE_CODES[$m[1]]);
        }
        return true;
    }

    /**
     * Formatear región del IGN a texto legible
     *
     * Entrada IGN:  "S GAUCÍN.MA"
     * Salida:        "Sur de Gaucín, Málaga"
     */
    private static function formatRegion(string $raw): string {
        $direction = '';
        $body = $raw;

        // Extraer prefijo de dirección y expandir a nombre completo
        if (preg_match('/^(NW|NE|SW|SE|N|S|E|W)\s+(.+)$/u', $raw, $m)) {
            $dirName = self::DIRECTION_NAMES[$m[1]] ?? $m[1];
            $direction = $dirName . ' de ';
            $body = $m[2];
        }

        $location = $body;
        $suffix = '';

        // Separar código de provincia tras el punto (.MA, .CA, etc.)
        if (preg_match('/^(.+)\.([A-Z]{1,3})$/u', $body, $m)) {
            $location = $m[1];
            $code = $m[2];
            $suffix = ', ' . (self::PROVINCE_CODES[$code] ?? $code);
        } elseif (strpos($body, '-') !== false) {
            // Zona marítima: ATLÁNTICO-GALICIA → Atlántico-Galicia
            $parts = explode('-', $body);
            $location = implode('-', array_map([self::class, 'toTitleCase'], $parts));
            return $direction . $location;
        }

        return $direction . self::toTitleCase($location) . $suffix;
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

            // Solo alertas de España
            if ($region && !self::isSpanishRegion($region)) {
                continue;
            }

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
            $formattedRegion = $region ? self::formatRegion($region) : null;

            $headline = "Terremoto M{$magnitude}";
            if ($formattedRegion) {
                $headline .= " en {$formattedRegion}";
            }

            $fullDescription = "Magnitud {$magnitude}";
            if ($formattedRegion) {
                $fullDescription .= " en {$formattedRegion}";
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
                area: $formattedRegion,
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
