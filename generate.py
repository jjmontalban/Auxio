#!/usr/bin/env python3
"""AUXIO - Generador de pagina de emergencias para Espana.

Entry point unico. Recopila alertas de todas las fuentes,
normaliza al schema comun y genera un HTML ultraligero.
"""

import sys
from datetime import datetime, timezone
from typing import List

from schema import Alert
from sources import aemet
from sources import ign

# -- Severity ordering for display (most severe first) --
SEVERITY_ORDER = {"red": 0, "orange": 1, "yellow": 2, "green": 3}

# -- All source modules --
SOURCES = [
    ("AEMET", aemet),
    ("IGN", ign),
    # ("DGT", dgt),        # futuro
    # ("Proteccion Civil", proteccion_civil),  # futuro
]

SEVERITY_LABEL = {
    "red": "Rojo",
    "orange": "Naranja",
    "yellow": "Amarillo",
    "green": "Verde",
}

SEVERITY_EMOJI = {
    "red": "&#9888;",      # warning sign
    "orange": "&#9888;",
    "yellow": "&#9888;",
    "green": "&#10003;",   # check mark
}


def collect_alerts() -> List[Alert]:
    """Fetch alerts from all registered sources."""
    all_alerts: List[Alert] = []
    for name, module in SOURCES:
        try:
            alerts = module.fetch()
            print(f"[{name}] {len(alerts)} alertas obtenidas")
            all_alerts.extend(alerts)
        except Exception as exc:
            print(f"[{name}] Error: {exc}", file=sys.stderr)
    # Sort: most severe first
    all_alerts.sort(key=lambda a: SEVERITY_ORDER.get(a.severity, 99))
    return all_alerts


def render_alerts_section(alerts: List[Alert]) -> str:
    """Render the alerts section as HTML."""
    if not alerts:
        return (
            "<p><strong>No hay alertas activas en este momento.</strong></p>\n"
            "<p>Consulta las fuentes oficiales para informacion en tiempo real.</p>"
        )

    lines = ["<ul>"]
    for alert in alerts:
        sev = SEVERITY_LABEL.get(alert.severity, alert.severity)
        emoji = SEVERITY_EMOJI.get(alert.severity, "")
        area_part = f" - {alert.area}" if alert.area else ""
        event_part = f" ({alert.event_type})" if alert.event_type else ""
        headline = alert.headline or alert.description
        lines.append(
            f'<li><strong>[{sev}]{event_part}</strong>{area_part}: '
            f'{headline}</li>'
        )
    lines.append("</ul>")
    return "\n".join(lines)


def render_html(alerts: List[Alert]) -> str:
    """Generate the full static HTML page."""
    now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC")
    alerts_html = render_alerts_section(alerts)

    return f"""<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="AUXIO - Informacion de emergencias para Espana">
<title>AUXIO - Emergencias Espana</title>
</head>
<body>

<h1>AUXIO - Emergencias Espana</h1>

<p><strong>Informacion critica de emergencias. Optimizada para conexiones lentas.</strong></p>
<p><em>Ultima actualizacion: {now}</em></p>

<hr>

<h2>LLAMADAS DE EMERGENCIA</h2>

<h3>Emergencias Generales</h3>
<ul>
<li><strong>112</strong> - Emergencias (Policia, Bomberos, Ambulancia) - <a href="tel:112">Llamar</a></li>
<li><strong>091</strong> - Policia Nacional - <a href="tel:091">Llamar</a></li>
<li><strong>062</strong> - Guardia Civil - <a href="tel:062">Llamar</a></li>
<li><strong>080/085</strong> - Bomberos (varia por comunidad) - <a href="tel:080">Llamar</a></li>
<li><strong>061</strong> - Urgencias Sanitarias - <a href="tel:061">Llamar</a></li>
</ul>

<h3>Emergencias Especializadas</h3>
<ul>
<li><strong>016</strong> - Violencia de Genero (no deja rastro en factura) - <a href="tel:016">Llamar</a></li>
<li><strong>024</strong> - Atencion a la Conducta Suicida - <a href="tel:024">Llamar</a></li>
<li><strong>915 620 420</strong> - Servicio de Informacion Toxicologica - <a href="tel:915620420">Llamar</a></li>
</ul>

<hr>

<h2>ALERTAS Y AVISOS ACTIVOS</h2>

{alerts_html}

<p><strong>Consulta las alertas oficiales en tiempo real:</strong></p>
<ul>
<li><strong>AEMET</strong> - Alertas meteorologicas: <a href="https://www.aemet.es/es/eltiempo/prediccion/avisos">aemet.es/avisos</a></li>
<li><strong>Proteccion Civil</strong>: <a href="https://www.proteccioncivil.es">proteccioncivil.es</a></li>
<li><strong>DGT</strong> - Estado de carreteras: <a href="https://infocar.dgt.es/etraffic/">infocar.dgt.es</a></li>
<li><strong>IGN</strong> - Actividad sismica: <a href="https://www.ign.es/web/ign/portal/sis-catalogo-terremotos">ign.es/terremotos</a></li>
</ul>

<hr>

<h2>GUIAS RAPIDAS DE ACTUACION</h2>

<h3>DANA / INUNDACIONES</h3>
<ul>
<li>NO cruces zonas inundadas a pie ni en vehiculo</li>
<li>Alejate de barrancos, ramblas y cauces secos</li>
<li>Si estas en un vehiculo y empieza a flotar, ABANDONALO</li>
<li>Busca terreno elevado</li>
<li>NO bajes a sotanos o garajes</li>
<li>Corta la electricidad si hay agua en casa</li>
</ul>

<h3>TERREMOTOS</h3>
<ul>
<li>DENTRO: Protegete bajo mesa resistente o marco de puerta</li>
<li>FUERA: Alejate de edificios, cables, farolas</li>
<li>NO uses ascensores</li>
<li>Espera replicas</li>
<li>Sal de edificios danados inmediatamente</li>
</ul>

<h3>INCENDIOS FORESTALES</h3>
<ul>
<li>Llama al 112 inmediatamente</li>
<li>Evacua si las autoridades lo ordenan - NO ESPERES</li>
<li>NO huyas monte arriba, el fuego sube mas rapido</li>
<li>Alejate en direccion perpendicular al avance del fuego</li>
</ul>

<h3>OLAS DE CALOR</h3>
<ul>
<li>Hidratate constantemente</li>
<li>Evita salir entre 12h y 17h</li>
<li>Vigila a ancianos, ninos y enfermos cronicos</li>
<li>Golpe de calor: llama al 112 y enfria el cuerpo con agua</li>
</ul>

<hr>

<h2>KIT DE EMERGENCIA BASICO (72 horas)</h2>
<ul>
<li>Agua: 3 litros por persona/dia</li>
<li>Alimentos no perecederos</li>
<li>Radio portatil y linterna con pilas</li>
<li>Bateria externa para movil</li>
<li>Botiquin basico y medicacion personal</li>
<li>Copias de documentos importantes</li>
<li>Efectivo en billetes pequenos</li>
<li>Manta termica y silbato</li>
</ul>

<hr>

<p><small>AUXIO - Proyecto de codigo abierto. No sustituye los servicios oficiales de emergencia. En caso de emergencia, llama al <strong>112</strong>.</small></p>

</body>
</html>"""


def main():
    output = "index.html"
    if len(sys.argv) > 1:
        output = sys.argv[1]

    alerts = collect_alerts()
    html = render_html(alerts)

    with open(output, "w", encoding="utf-8") as f:
        f.write(html)

    print(f"[auxio] {output} generado ({len(html)} bytes, {len(alerts)} alertas)")


if __name__ == "__main__":
    main()
