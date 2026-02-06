<?php

/**
 * AUXIO - Generador de página de emergencias para España
 * 
 * Entry point único. Recopila alertas de todas las fuentes,
 * normaliza al schema común y genera un HTML ultraligero.
 * 
 * Uso:
 *   php generate.php [output_file.html]
 */

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

// Archivo de salida (default: index.html)
$output = $argc > 1 ? $argv[1] : 'index.html';

try {
    // Recopilar alertas
    $alerts = AlertGenerator::collectAlerts();
    
    // Generar HTML
    $html = AlertGenerator::renderHTML($alerts);
    
    // Guardar la página
    if (file_put_contents($output, $html) === false) {
        throw new Exception("Cannot write to {$output}");
    }
    
    $fileSize = filesize($output);
    echo "[auxio] {$output} generado ($fileSize bytes, " . count($alerts) . " alertas)\n";
    
} catch (Exception $exc) {
    echo "[error] " . $exc->getMessage() . "\n";
    exit(1);
}
