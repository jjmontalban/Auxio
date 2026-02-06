# AUXIO - Información de Emergencias para España

Agregador de alertas de emergencias que recopila datos de múltiples fuentes oficiales (AEMET, IGN) y genera una página HTML estática optimizada para conexiones lentas, ideada para situaciones de crisis.

## Características

✅ **Sin dependencias externas** - Usa solo PHP 7.4+  
✅ **Ultraligero** - HTML mínimo, sin JavaScript  
✅ **Múltiples fuentes** - AEMET (meteorología), IGN (sísmica)  
✅ **API REST** - Acceso a alertas en JSON  
✅ **Escalable** - Fácil agregar nuevas fuentes  
✅ **Production-ready** - Manejo robusto de errores

## Instalación

### Requisitos

- PHP 7.4 o superior
- Acceso a internet para descargar feeds
- (Opcional) API key de AEMET para alertas meteorológicas

### Setup

1. Clona o descarga el proyecto:
```bash
git clone <repo>
cd Auxio
```

2. Copia el archivo de configuración:
```bash
cp .env.example .env
```

3. Edita `.env` y agrega tu API key de AEMET:
```env
AEMET_API_KEY=tu_clave_aqui
```

4. Obtén una API key gratuita en: https://opendata.aemet.es

## Uso

### Generar página HTML

```bash
php generate.php              # Genera index.html
php generate.php output.html  # Guarda en output.html
```

### Acceder a la API REST

```bash
# Todas las alertas
curl http://localhost/Auxio/api.php?action=alerts

# Filtra por severidad
curl "http://localhost/Auxio/api.php?action=alerts&severity=red"

# Filtra por fuente
curl "http://localhost/Auxio/api.php?action=alerts&source=ign"

# Status de la API
curl http://localhost/Auxio/api.php?action=health
```

### Ejemplo respuesta API

```json
{
  "success": true,
  "timestamp": "2026-02-06 13:05:23 UTC",
  "count": 12,
  "alerts": [
    {
      "source": "ign",
      "severity": "yellow",
      "headline": "Terremoto M3.1 en ATLÁNTICO",
      "description": "Magnitud 3.1 en ATLÁNTICO-CANARIAS, 18/03/2019 19:34:55",
      "area": "ATLÁNTICO-CANARIAS",
      "event_type": "Terremoto",
      "onset": "18/03/2019 19:34:55",
      "sender": "Instituto Geográfico Nacional",
      "web": "https://www.ign.es/..."
    }
  ]
}
```

## Arquitectura

```
Auxio/
├── src/
│   ├── Alert.php              # Modelo de datos normalizado
│   ├── Generator.php          # Renderizado HTML y orquestación
│   └── Sources/
│       ├── AEMET.php          # Parser de alertas meteorológicas
│       └── IGN.php            # Parser de actividad sísmica
├── generate.php               # CLI para generar HTML
├── api.php                    # API REST
├── index.html                 # Página generada (salida)
├── .env.example              # Plantilla de configuración
└── README.md
```

## API REST Endpoints

### GET /api.php?action=alerts
Retorna todas las alertas actuales en formato JSON.

**Parámetros opcionales:**
- `severity` - Filtrar por severidad: `red`, `orange`, `yellow`, `green`
- `source` - Filtrar por fuente: `aemet`, `ign`

**Ejemplo:**
```
GET /api.php?action=alerts&severity=red&source=ign
```

### GET /api.php?action=health
Verifica el estado de la API.

**Respuesta:**
```json
{
  "success": true,
  "status": "OK",
  "version": "1.0.0",
  "timestamp": "2026-02-06 13:05:23 UTC"
}
```

## Fuentes de Datos

### AEMET (Agencia Estatal de Meteorología)

Avisos de fenómenos meteorológicos adversos en formato CAP 1.2.

- **Severidad:** Extreme → Red, Severe → Orange, Moderate → Yellow, Minor → Green
- **Requiere:** API key gratuita
- **Actualización:** Tiempo real
- **Info:** https://opendata.aemet.es

### IGN (Instituto Geográfico Nacional)

Actividad sísmica y terremotos en España y alrededores.

- **Severidad:** Basada en magnitud Richter
- **Requiere:** Ninguno (feed público)
- **Actualización:** Tiempo real
- **Info:** https://www.ign.es

## Seguridad

### Escapado de HTML
Todos los datos de usuario (headline, description, area) se escapan correctamente usando `htmlspecialchars()` para prevenir XSS.

### API CORS
La API permitirá CORS desde cualquier origen. Para restricción en producción:
```php
header('Access-Control-Allow-Origin: https://tu-dominio.com');
```

### Variables de entorno
Las claves sensibles (API keys) se cargan desde `.env`, **nunca harcodear**.

## Deploy

### Laragon/XAMPP

1. Coloca la carpeta en `C:/laragon/www/Auxio`
2. Accede a `http://localhost/Auxio/`
3. Configura `.env` con tu API key
4. Ejecuta en cron:
   ```bash
   * * * * * /usr/bin/php C:/laragon/www/Auxio/generate.php
   ```

### Servidor Linux/VPS

1. Clona en `/var/www/html/auxio/`
2. Instala en cron:
   ```bash
   */5 * * * * php /var/www/html/auxio/generate.php
   ```
3. Expone API vía Nginx/Apache

### Docker

```dockerfile
FROM php:7.4-cli
WORKDIR /app
COPY . /app
RUN chmod +x /app/generate.php
ENTRYPOINT ["php", "generate.php"]
```

## Limitaciones

- **Sin JavaScript** - Mejora compatibilidad pero menos interactividad
- **HTML estático** - Se regenera cada vez (ideal para cron)
- **API sin autenticación** - Usar en red privada o agregar API keys

## Desarrollo

Para agregar una nueva fuente de alertas:

1. Crea `src/Sources/MiFuente.php`:
```php
class MiFuenteSource {
    public static function fetch(): array {
        // Retorna array<Alert>
    }
}
```

2. Regístrala en `src/Generator.php`:
```php
$sources = [
    ['name' => 'MiFuente', 'class' => 'MiFuenteSource'],
    // ...
];
```

## Problemas Comunes

### API key de AEMET no funciona
- Verifica que esté en `.env`
- Asegúrate de que la API key sea válida
- Intenta acceder a https://opendata.aemet.es/opendata/valores/prediccion/

### Genera HTML pero sin alertas
- Verifica conexión a internet
- Revisa los logs de `echo` en la CLI
- Comprueba que los feeds estén disponibles

### Errores en parsing XML
- Algunos feeds pueden cambiar de formato
- Revisa `src/Sources/` para actualizar parsers

## Licencia

Código abierto. Libre para usar y modificar.

## Descargo de Responsabilidad

**AUXIO no sustituye los servicios oficiales de emergencia.**

En caso de emergencia, siempre llama al **112**.

- Fuentes verificadas: AEMET, IGN
- Información recopilada automáticamente
- No hay verificación humana
- Úsalo como complemento, no como fuente primaria

## Contacto

Para reportar problemas o sugerencias, abre un issue en el repositorio.

---

**Última actualización:** 6 Feb 2026  
**Versión:** 1.0.0 (PHP)
