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
    
    // Asegurar que el directorio existe
    $outputDir = dirname($output);
    if ($outputDir !== '.' && !is_dir($outputDir)) {
        if (!@mkdir($outputDir, 0755, true)) {
            $error = error_get_last();
            $errorMsg = $error ? $error['message'] : 'Unknown error';
            throw new Exception("Cannot create directory {$outputDir}: {$errorMsg}");
        }
    }
    
    // Intentar guardar la página
    $bytesWritten = @file_put_contents($output, $html);
    if ($bytesWritten === false) {
        $error = error_get_last();
        $errorMsg = $error ? $error['message'] : 'Unknown error';
        
        // Intentar determinar el directorio para un mensaje de error más útil
        $resolvedDir = $outputDir === '.' ? getcwd() : realpath($outputDir);
        
        if ($resolvedDir && !is_writable($resolvedDir)) {
            throw new Exception("Cannot write to {$output}: directory '{$resolvedDir}' is not writable");
        }
        
        throw new Exception("Cannot write to {$output}: {$errorMsg}");
    }
    
    $fileSize = filesize($output);
    echo "[auxio] {$output} generado ($fileSize bytes, " . count($alerts) . " alertas)\n";
    
} catch (Exception $exc) {
    echo "[error] " . $exc->getMessage() . "\n";
    exit(1);
}
