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
$originalOutput = $output; // Store original for error messages

try {
    // Recopilar alertas
    $alerts = AlertGenerator::collectAlerts();
    
    // Generar HTML
    $html = AlertGenerator::renderHTML($alerts);
    
    // Asegurar que el directorio existe
    $outputDir = dirname($output);
    if ($outputDir !== '.' && !is_dir($outputDir)) {
        // Intentar crear el directorio con permisos 0755
        $created = @mkdir($outputDir, 0755, true);
        if (!$created && !is_dir($outputDir)) {
            // Si mkdir falló y el directorio aún no existe
            $error = error_get_last();
            $errorMsg = $error ? $error['message'] : 'Unknown error';
            throw new Exception("Cannot create directory {$outputDir}: {$errorMsg}");
        }
    }
    
    // Resolver el directorio antes de intentar escribir para mensajes de error precisos
    $resolvedDir = $outputDir === '.' ? getcwd() : realpath($outputDir);
    if ($resolvedDir === false) {
        $resolvedDir = $outputDir; // Fallback al path original
    }
    
    // Verificar permisos de escritura ANTES de intentar escribir
    // Solo aplicar fallback si el output especifica un directorio explícito (no solo un nombre de archivo)
    if ($outputDir !== '.' && !is_writable($resolvedDir)) {
        // Intentar escribir en el directorio actual como fallback
        $fallbackOutput = basename($output);
        $currentDir = getcwd();
        
        if (is_writable($currentDir)) {
            echo "[warning] Directory '{$resolvedDir}' is not writable, using current directory: {$currentDir}\n";
            $output = $fallbackOutput;
        } else {
            throw new Exception("Cannot write to {$originalOutput}: directory '{$resolvedDir}' is not writable. Please ensure the directory has write permissions (e.g., chmod 755 for owner-only or chmod 775 for group access) or run the script from a writable directory.");
        }
    }
    
    // Intentar guardar la página
    $bytesWritten = @file_put_contents($output, $html);
    if ($bytesWritten === false) {
        // Capturar el error inmediatamente
        $error = error_get_last();
        $errorMsg = $error ? $error['message'] : 'Unknown error';
        throw new Exception("Cannot write to {$originalOutput}: {$errorMsg}");
    }
    
    $fileSize = filesize($output);
    echo "[auxio] {$output} generado ($fileSize bytes, " . count($alerts) . " alertas)\n";
    
} catch (Exception $exc) {
    echo "[error] " . $exc->getMessage() . "\n";
    exit(1);
}
