"""Fuente IGN: actividad sismica via GeoRSS del Instituto Geografico Nacional."""

import re
import xml.etree.ElementTree as ET
from typing import List
from urllib.request import Request, urlopen
from urllib.error import URLError

from schema import Alert

FEED_URL = "https://www.ign.es/ign/RssTools/sismologia.xml"
GEO_NS = "{http://www.w3.org/2003/01/geo/wgs84_pos#}"

# Magnitude-to-severity mapping (Richter-like scale thresholds).
# < 2.5  : green  — generalmente no sentido
# 2.5–4.0: yellow — sentido, danos menores
# 4.0–5.5: orange — danos moderados
# >= 5.5 : red    — danos significativos
_SEV_THRESHOLDS = [(5.5, "red"), (4.0, "orange"), (2.5, "yellow")]

# Regex to extract magnitude and region from the description text.
# Example: "Se ha producido un terremoto de magnitud 3.1 en SW EL PINAR
#           DE EL HIERRO.IHI en la fecha 18/03/2019 19:34:55 en la
#           siguiente localizacion: 27.62,-18.0859"
_RE_MAGNITUDE = re.compile(r"magnitud\s+([\d.]+)")
_RE_REGION = re.compile(r"magnitud\s+[\d.]+\s+en\s+(.+?)\s+en la fecha")
_RE_DATE = re.compile(r"en la fecha\s+(\d{2}/\d{2}/\d{4}\s+\d{1,2}:\d{2}:\d{2})")


def _severity_from_magnitude(mag: float) -> str:
    """Map a numeric magnitude to a severity level."""
    for threshold, level in _SEV_THRESHOLDS:
        if mag >= threshold:
            return level
    return "green"


def _fetch_feed() -> bytes:
    """Download the IGN GeoRSS feed."""
    req = Request(FEED_URL, headers={"User-Agent": "AUXIO/1.0"})
    with urlopen(req, timeout=30) as resp:
        return resp.read()


def _parse_feed(xml_bytes: bytes) -> List[Alert]:
    """Parse the GeoRSS XML into a list of Alert objects."""
    alerts: List[Alert] = []
    root = ET.fromstring(xml_bytes)

    channel = root.find("channel")
    if channel is None:
        channel = root  # fallback: items at root level

    for item in channel.findall("item"):
        description = ""
        desc_el = item.find("description")
        if desc_el is not None and desc_el.text:
            description = desc_el.text.strip()

        # Extract magnitude
        mag_match = _RE_MAGNITUDE.search(description)
        if not mag_match:
            continue  # skip items without magnitude info
        magnitude = float(mag_match.group(1))

        # Extract region
        region_match = _RE_REGION.search(description)
        region = region_match.group(1).strip() if region_match else None

        # Extract date/time
        date_match = _RE_DATE.search(description)
        onset = date_match.group(1) if date_match else None

        # Extract coordinates
        lat_el = item.find(f"{GEO_NS}lat")
        lon_el = item.find(f"{GEO_NS}long")
        coords = ""
        if lat_el is not None and lon_el is not None:
            lat_text = lat_el.text.strip() if lat_el.text else ""
            lon_text = lon_el.text.strip() if lon_el.text else ""
            if lat_text and lon_text:
                coords = f" ({lat_text}, {lon_text})"

        # Link to detail page
        link_el = item.find("link")
        web = None
        if link_el is not None and link_el.text:
            web = link_el.text.strip()

        severity = _severity_from_magnitude(magnitude)
        headline = f"Terremoto M{magnitude}"
        if region:
            headline += f" en {region}"

        full_description = f"Magnitud {magnitude}"
        if region:
            full_description += f" en {region}"
        if onset:
            full_description += f", {onset}"
        if coords:
            full_description += coords

        alerts.append(
            Alert(
                source="ign",
                severity=severity,
                headline=headline,
                description=full_description,
                area=region,
                event_type="Terremoto",
                onset=onset,
                sender="Instituto Geografico Nacional",
                web=web,
            )
        )

    return alerts


def fetch() -> List[Alert]:
    """Fetch current seismic activity from IGN GeoRSS feed.

    Returns a list of Alert objects following the AUXIO schema.
    Returns an empty list if the request fails.
    """
    try:
        xml_bytes = _fetch_feed()
        return _parse_feed(xml_bytes)
    except (URLError, ET.ParseError, ValueError) as exc:
        print(f"[ign] Error fetching seismic data: {exc}")
        return []
