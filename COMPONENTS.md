# Componentes del Proyecto Auxio

Auxio es un sistema de agregación de alertas de emergencia en tiempo real para España. Recopila avisos meteorológicos de AEMET y actividad sísmica del IGN, generando una página HTML estática ultraligera optimizada para conexiones lentas durante situaciones de crisis.

**Lenguaje:** PHP 7.4+ puro (sin dependencias externas)
**Código total:** ~839 líneas de PHP

---

## Estructura del proyecto

```
Auxio/
├── .env                  # Configuración (clave API de AEMET)
├── generate.php          # Punto de entrada CLI
├── api.php               # Endpoint REST API
├── cron-setup.sh         # Script de automatización con cron
│
└── src/
    ├── Alert.php          # Modelo de datos de alerta
    ├── Generator.php      # Orquestador y renderizador HTML
    │
    └── Sources/
        ├── AEMET.php      # Adaptador de alertas meteorológicas
        └── IGN.php        # Adaptador de actividad sísmica
```

---

## Componentes principales

### 1. `src/Alert.php` — Modelo de datos

Define la estructura normalizada que representa una alerta, independientemente de su origen.

**Atributos principales:**

| Atributo      | Descripción                                          |
|---------------|------------------------------------------------------|
| `source`      | Origen de la alerta (`aemet` o `ign`)                |
| `severity`    | Nivel de gravedad: `red`, `orange`, `yellow`, `green`|
| `headline`    | Título de la alerta                                  |
| `description` | Descripción detallada                                |
| `area`        | Zona geográfica afectada                             |
| `event_type`  | Tipo de evento (Terremoto, Lluvia, etc.)             |
| `onset`       | Fecha/hora de inicio                                 |
| `expires`     | Fecha/hora de expiración                             |
| `sender`      | Entidad emisora                                      |
| `web`         | Enlace a la fuente oficial                           |

**Método clave:**
- `toArray()`: Convierte la alerta a un array asociativo serializable como JSON.

Su función es garantizar que los datos de distintas fuentes se ajusten a un esquema único y consistente.

---

### 2. `src/Generator.php` — Orquestador y renderizador

Es el componente central del sistema. Tiene tres responsabilidades:

#### a) Recopilación de alertas (`collectAlerts()`)
- Carga dinámicamente todos los adaptadores de fuente (AEMET, IGN).
- Agrega las alertas de todas las fuentes.
- Las ordena por gravedad (Rojo → Naranja → Amarillo → Verde).
- Si una fuente falla, las demás siguen funcionando.

#### b) Renderizado HTML (`renderHTML()`, `renderAlertsSection()`)
- Genera HTML mínimo y semántico, **sin JavaScript**.
- Escapa datos con `htmlspecialchars()` para prevenir XSS.
- Incluye indicadores visuales de severidad con emojis (🔴 🟠 🟡 ✅).
- Incorpora contenido estático de utilidad:
  - Teléfonos de emergencia (112, 091, 062, 016, 024, etc.)
  - Guías de actuación ante DANA/inundaciones, terremotos, incendios forestales y olas de calor.
  - Checklist de kit de emergencia de 72 horas.
  - Enlaces a fuentes oficiales (AEMET, Protección Civil, DGT, IGN).

#### c) Mapeo de severidad
- Define constantes para ordenación (`red=0`, `orange=1`, `yellow=2`, `green=3`).
- Etiquetas en español y emojis asociados a cada nivel.

---

### 3. `src/Sources/AEMET.php` — Adaptador de alertas meteorológicas

Obtiene alertas en tiempo real de la **Agencia Estatal de Meteorología**.

| Detalle          | Valor                                           |
|------------------|------------------------------------------------|
| Formato de datos | CAP 1.2 XML (Common Alerting Protocol)         |
| Autenticación    | Clave API (gratuita en opendata.aemet.es)      |
| Frecuencia       | Tiempo real                                    |

**Funcionamiento:**
1. Solicita el endpoint de metadatos de AEMET para obtener la URL del documento CAP.
2. Descarga y parsea el XML CAP 1.2 con `SimpleXMLElement`.
3. Extrae bloques en español específicamente.
4. Mapea severidades del estándar CAP a las del sistema:
   - `Extreme` → Rojo
   - `Severe` → Naranja
   - `Moderate` → Amarillo
   - `Minor` → Verde
5. Parsea múltiples áreas por alerta y extrae metadatos (emisor, web, certeza, urgencia, inicio, expiración).

**Comunicación HTTP:** Usa `file_get_contents()` con contexto de stream (sin dependencia de cURL).

---

### 4. `src/Sources/IGN.php` — Adaptador de actividad sísmica

Obtiene datos sísmicos en tiempo real del **Instituto Geográfico Nacional**.

| Detalle          | Valor                                           |
|------------------|------------------------------------------------|
| Formato de datos | GeoRSS XML                                     |
| Autenticación    | Ninguna (feed público)                         |
| URL del feed     | `https://www.ign.es/ign/RssTools/sismologia.xml` |

**Funcionamiento:**
1. Descarga el feed GeoRSS público del IGN.
2. Extrae datos mediante expresiones regulares: magnitud, región, timestamp y coordenadas.
3. Calcula la severidad a partir de la magnitud en la escala Richter:
   - M ≥ 5.5 → Rojo (daños significativos)
   - M 4.0–5.5 → Naranja (daños moderados)
   - M 2.5–4.0 → Amarillo (se siente, daños menores)
   - M < 2.5 → Verde (generalmente no se percibe)
4. Construye objetos `Alert` normalizados con enlace a la página de detalle del IGN.

---

### 5. `generate.php` — Punto de entrada CLI

Script de línea de comandos que orquesta la generación de la página HTML estática.

**Uso:**
```bash
php generate.php              # Genera index.html
php generate.php salida.html  # Nombre de archivo personalizado
```

**Flujo:**
1. Carga variables de entorno desde `.env`.
2. Llama a `AlertGenerator::collectAlerts()` para obtener las alertas.
3. Llama a `AlertGenerator::renderHTML()` para generar el HTML.
4. Escribe el resultado en disco.
5. Registra en consola el tamaño del archivo y la cantidad de alertas.

---

### 6. `api.php` — Endpoint REST API

Interfaz HTTP que expone las alertas en formato JSON para acceso programático.

**Endpoints disponibles:**

| Acción                          | URL                                        |
|---------------------------------|--------------------------------------------|
| Todas las alertas               | `?action=alerts`                           |
| Filtrar por severidad           | `?action=alerts&severity=red`              |
| Filtrar por fuente              | `?action=alerts&source=ign`                |
| Comprobación de estado          | `?action=health`                           |

**Características:**
- Cabeceras JSON y CORS (`Access-Control-Allow-Origin: *`).
- Códigos HTTP: 200 (éxito), 400 (petición inválida), 500 (error del servidor).
- Reutiliza la misma lógica de recopilación que `generate.php`.

---

### 7. `cron-setup.sh` — Automatización

Script interactivo en Bash para configurar la actualización automática.

- Configura un cron job que ejecuta `generate.php` cada 5 minutos.
- Crea el directorio `logs/` para la salida del cron.
- Detecta automáticamente la ubicación del binario de PHP.

---

## Flujo de datos

```
                 ┌──────────────┐     ┌──────────────┐
                 │  AEMET API   │     │  IGN GeoRSS  │
                 │  (CAP XML)   │     │  (XML Feed)  │
                 └──────┬───────┘     └──────┬───────┘
                        │                    │
                        ▼                    ▼
                 ┌──────────────┐     ┌──────────────┐
                 │  AEMET.php   │     │   IGN.php    │
                 │  fetch()     │     │   fetch()    │
                 └──────┬───────┘     └──────┬───────┘
                        │                    │
                        └────────┬───────────┘
                                 │
                          Objetos Alert
                          normalizados
                                 │
                                 ▼
                      ┌────────────────────┐
                      │   Generator.php    │
                      │  collectAlerts()   │
                      │  (ordena por       │
                      │   severidad)       │
                      └─────────┬──────────┘
                                │
                 ┌──────────────┼──────────────┐
                 │              │              │
                 ▼              ▼              ▼
          ┌───────────┐  ┌──────────┐  ┌───────────┐
          │  HTML     │  │  JSON    │  │  Health   │
          │ estático  │  │  API     │  │  check    │
          │ (CLI)     │  │ (HTTP)   │  │  (HTTP)   │
          └───────────┘  └──────────┘  └───────────┘
```

---

## Decisiones arquitectónicas clave

1. **Cero dependencias externas:** Solo usa la biblioteca estándar de PHP, lo que facilita el despliegue en cualquier servidor.
2. **Interfaz dual:** CLI para HTML estático + API REST para acceso JSON, compartiendo la misma lógica de negocio.
3. **Arquitectura de fuentes extensible:** Añadir una nueva fuente requiere solo crear un archivo en `Sources/` con un método estático `fetch()` y registrarlo en `Generator.php`.
4. **Tolerancia a fallos:** Si una fuente falla, las demás continúan funcionando normalmente.
5. **Sin base de datos:** El sistema regenera la salida cada 5 minutos desde las fuentes en tiempo real. No necesita almacenamiento persistente.
6. **Seguridad:** Prevención de XSS con `htmlspecialchars()`, clave API en `.env` fuera del repositorio, y gestión de errores que no expone trazas al usuario.
