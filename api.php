<?php

/**
 * AUXIO - API REST para acceso a alertas en JSON
 * 
 * Endpoints:
 *   GET /api.php?action=alerts              - Todas las alertas
 *   GET /api.php?action=alerts&severity=red - Por severidad
 *   GET /api.php?action=alerts&source=ign   - Por fuente
 *   GET /api.php?action=health              - Status API
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/src/Alert.php';
require_once __DIR__ . '/src/Generator.php';

// Cargar variables de entorno
if (file_exists(__DIR__ . '/.env')) {
    $env = parse_ini_file(__DIR__ . '/.env');
    foreach ($env as $key => $value) {
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }
}

function returnJSON($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function getParam(string $name, $default = null) {
    return $_GET[$name] ?? $default;
}

try {
    $action = getParam('action', 'alerts');

    switch ($action) {
        case 'alerts':
            // Recopilar todas las alertas
            $alerts = AlertGenerator::collectAlerts();
            
            // Filtrar por severidad si se especifica
            $severity = getParam('severity');
            if ($severity) {
                $alerts = array_filter($alerts, fn(Alert $a) => $a->severity === $severity);
            }
            
            // Filtrar por fuente si se especifica
            $source = getParam('source');
            if ($source) {
                $alerts = array_filter($alerts, fn(Alert $a) => $a->source === $source);
            }
            
            // Agrupar por fuente si se solicita
            $grouped = getParam('grouped');
            if ($grouped) {
                $bySource = AlertGenerator::groupBySource($alerts);
                $groupedData = [];
                foreach ($bySource as $src => $srcAlerts) {
                    $groupedData[$src] = array_map(fn(Alert $a) => $a->toArray(), $srcAlerts);
                }
                returnJSON([
                    'success' => true,
                    'timestamp' => date('Y-m-d H:i:s') . ' UTC',
                    'count' => count($alerts),
                    'grouped' => $groupedData,
                ]);
            }

            // Convertir a arrays
            $alertData = array_map(fn(Alert $a) => $a->toArray(), $alerts);

            returnJSON([
                'success' => true,
                'timestamp' => date('Y-m-d H:i:s') . ' UTC',
                'count' => count($alertData),
                'alerts' => $alertData,
            ]);

        case 'health':
            returnJSON([
                'success' => true,
                'status' => 'OK',
                'version' => '1.0.0',
                'timestamp' => date('Y-m-d H:i:s') . ' UTC',
            ]);

        default:
            returnJSON([
                'success' => false,
                'error' => "Unknown action: {$action}",
                'available_actions' => ['alerts', 'health'],
            ], 400);
    }

} catch (Exception $exc) {
    returnJSON([
        'success' => false,
        'error' => $exc->getMessage(),
    ], 500);
}
