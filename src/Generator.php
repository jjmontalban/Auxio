<?php

/**
 * AUXIO - Generador de HTML y manejador de alertas
 */

class AlertGenerator {
    private const SEVERITY_ORDER = [
        "red" => 0,
        "orange" => 1,
        "yellow" => 2,
        "green" => 3,
    ];

    private const SEVERITY_LABEL = [
        "red" => "Rojo",
        "orange" => "Naranja",
        "yellow" => "Amarillo",
        "green" => "Verde",
    ];

    private const SEVERITY_EMOJI = [
        "red" => "🔴",
        "orange" => "🟠",
        "yellow" => "🟡",
        "green" => "✅",
    ];

    /**
     * Recopilar alertas de todas las fuentes
     */
    public static function collectAlerts(): array {
        $sources = [
            ['name' => 'AEMET', 'file' => 'AEMET.php', 'class' => 'AEMETSource'],
            ['name' => 'IGN', 'file' => 'IGN.php', 'class' => 'IGNSource'],
        ];

        $allAlerts = [];

        foreach ($sources as $source) {
            try {
                require_once __DIR__ . "/Sources/{$source['file']}";
                $class = $source['class'];
                $alerts = $class::fetch();
                echo "[{$source['name']}] " . count($alerts) . " alertas obtenidas\n";
                $allAlerts = array_merge($allAlerts, $alerts);
            } catch (Exception $exc) {
                echo "[{$source['name']}] Error: {$exc->getMessage()}\n";
            }
        }

        // Ordenar: más severas primero
        usort($allAlerts, function(Alert $a, Alert $b) {
            $sevA = self::SEVERITY_ORDER[$a->severity] ?? 99;
            $sevB = self::SEVERITY_ORDER[$b->severity] ?? 99;
            return $sevA <=> $sevB;
        });

        return $allAlerts;
    }

    /**
     * Renderizar sección de alertas como HTML
     */
    public static function renderAlertsSection(array $alerts): string {
        if (empty($alerts)) {
            return <<<HTML
<p><strong>No hay alertas activas en este momento.</strong></p>
<p>Consulta las fuentes oficiales para información en tiempo real.</p>
HTML;
        }

        $html = "<ul>\n";

        foreach ($alerts as $alert) {
            $sev = self::SEVERITY_LABEL[$alert->severity] ?? $alert->severity;
            $emoji = self::SEVERITY_EMOJI[$alert->severity] ?? "";
            
            $areaPart = '';
            if ($alert->area) {
                $areaPart = " - " . htmlspecialchars($alert->area, ENT_QUOTES, 'UTF-8');
            }
            
            $eventPart = '';
            if ($alert->event_type) {
                $eventPart = " (" . htmlspecialchars($alert->event_type, ENT_QUOTES, 'UTF-8') . ")";
            }
            
            $headline = htmlspecialchars(
                $alert->headline ?: $alert->description,
                ENT_QUOTES,
                'UTF-8'
            );

            $html .= sprintf(
                "<li>%s <strong>[%s]%s</strong>%s: %s</li>\n",
                $emoji,
                $sev,
                $eventPart,
                $areaPart,
                $headline
            );
        }

        $html .= "</ul>";
        return $html;
    }

    /**
     * Generar página HTML completa
     */
    public static function renderHTML(array $alerts): string {
        $now = date('Y-m-d H:i') . ' UTC';
        $alertsHtml = self::renderAlertsSection($alerts);

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="AUXIO - Información de emergencias para España">
<title>AUXIO - Emergencias España</title>
<style>
body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
h1, h2, h3 { color: #333; }
hr { border: none; border-top: 1px solid #ddd; margin: 20px 0; }
ul { padding-left: 20px; }
a { color: #0066cc; }
small { color: #666; }
</style>
</head>
<body>

<h1>Emergencias España</h1>

<p><strong>Información crítica de para conexiones lentas.</strong></p>
<p><em>Fecha act: $now</em></p>

<hr>

<h2>LLAMADAS DE EMERGENCIA</h2>

<h3>Emergencias Generales</h3>
<ul>
<li><strong>112</strong> - Emergencias (Policía, Bomberos, Ambulancia) - <a href="tel:112">Llamar</a></li>
<li><strong>091</strong> - Policía Nacional - <a href="tel:091">Llamar</a></li>
<li><strong>062</strong> - Guardia Civil - <a href="tel:062">Llamar</a></li>
<li><strong>080/085</strong> - Bomberos (varía por comunidad) - <a href="tel:080">Llamar</a></li>
<li><strong>061</strong> - Urgencias Sanitarias - <a href="tel:061">Llamar</a></li>
</ul>

<h3>Emergencias Especializadas</h3>
<ul>
<li><strong>016</strong> - Violencia de Género (no deja rastro en factura) - <a href="tel:016">Llamar</a></li>
<li><strong>024</strong> - Atención a la Conducta Suicida - <a href="tel:024">Llamar</a></li>
<li><strong>915 620 420</strong> - Servicio de Información Toxicológica - <a href="tel:915620420">Llamar</a></li>
</ul>

<hr>

<h2>ALERTAS Y AVISOS ACTIVOS</h2>

$alertsHtml

<p><strong>Consulta las alertas oficiales en tiempo real:</strong></p>
<ul>
<li><strong>AEMET</strong> - Alertas meteorológicas: <a href="https://www.aemet.es/es/eltiempo/prediccion/avisos">aemet.es/avisos</a></li>
<li><strong>Protección Civil</strong>: <a href="https://www.proteccioncivil.es">proteccioncivil.es</a></li>
<li><strong>DGT</strong> - Estado de carreteras: <a href="https://infocar.dgt.es/etraffic/">infocar.dgt.es</a></li>
<li><strong>IGN</strong> - Actividad sísmica: <a href="https://www.ign.es/web/ign/portal/sis-catalogo-terremotos">ign.es/terremotos</a></li>
</ul>

<hr>

<h2>GUÍAS RÁPIDAS DE ACTUACIÓN</h2>

<h3>DANA / INUNDACIONES</h3>
<ul>
<li>NO cruces zonas inundadas a pie ni en vehículo</li>
<li>Alójate de barrancos, ramblas y cauces secos</li>
<li>Si estás en un vehículo y empieza a flotar, ABANDÓNALO</li>
<li>Busca terreno elevado</li>
<li>NO bajes a sótanos o garajes</li>
<li>Corta la electricidad si hay agua en casa</li>
</ul>

<h3>TERREMOTOS</h3>
<ul>
<li>DENTRO: Protégete bajo mesa resistente o marco de puerta</li>
<li>FUERA: Alójate de edificios, cables, farolas</li>
<li>NO uses ascensores</li>
<li>Espera réplicas</li>
<li>Sal de edificios dañados inmediatamente</li>
</ul>

<h3>INCENDIOS FORESTALES</h3>
<ul>
<li>Llama al 112 inmediatamente</li>
<li>Evacúa si las autoridades lo ordenan - NO ESPERES</li>
<li>NO huyas monte arriba, el fuego sube más rápido</li>
<li>Alójate en dirección perpendicular al avance del fuego</li>
</ul>

<h3>OLAS DE CALOR</h3>
<ul>
<li>Hidrátate constantemente</li>
<li>Evita salir entre 12h y 17h</li>
<li>Vigila a ancianos, niños y enfermos crónicos</li>
<li>Golpe de calor: llama al 112 y enfría el cuerpo con agua</li>
</ul>

<hr>

<h2>KIT DE EMERGENCIA BÁSICO (72 horas)</h2>
<ul>
<li>Agua: 3 litros por persona/día</li>
<li>Alimentos no perecederos</li>
<li>Radio portátil y linterna con pilas</li>
<li>Batería externa para móvil</li>
<li>Botiquín básico y medicación personal</li>
<li>Copias de documentos importantes</li>
<li>Efectivo en billetes pequeños</li>
<li>Manta térmica y silbato</li>
</ul>

<hr>

<p><small>AUXIO - Proyecto de código abierto. No sustituye los servicios oficiales de emergencia. En caso de emergencia, llama al <strong>112</strong>.</small></p>

</body>
</html>
HTML;
    }
}
