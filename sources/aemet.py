"""Fuente AEMET: avisos de fenomenos meteorologicos adversos via CAP."""

import os
import xml.etree.ElementTree as ET
from typing import List
from urllib.request import Request, urlopen
from urllib.error import URLError
import json

from schema import Alert

API_KEY = os.environ.get("AEMET_API_KEY", "")
BASE_URL = "https://opendata.aemet.es/opendata"
ENDPOINT = "/api/avisos_cap/ultimoelaborado/area/{area}"
CAP_NS = "{urn:oasis:names:tc:emergency:cap:1.2}"

SEVERITY_MAP = {
    "Extreme": "red",
    "Severe": "orange",
    "Moderate": "yellow",
    "Minor": "green",
    "Unknown": "yellow",
}


def _request(url: str, accept: str = "application/json") -> bytes:
    """Make an HTTP GET request with the AEMET API key."""
    headers = {
        "Accept": accept,
        "api_key": API_KEY,
    }
    req = Request(url, headers=headers)
    with urlopen(req, timeout=30) as resp:
        return resp.read()


def _get_data_url(area: str = "esp") -> str:
    """Step 1: call the AEMET endpoint to get the datos URL."""
    url = BASE_URL + ENDPOINT.format(area=area)
    body = json.loads(_request(url))
    datos_url = body.get("datos")
    if not datos_url:
        raise ValueError(f"AEMET response missing 'datos' field: {body}")
    return datos_url


def _parse_cap(xml_bytes: bytes) -> List[Alert]:
    """Parse CAP 1.2 XML into a list of Alert objects."""
    alerts: List[Alert] = []
    root = ET.fromstring(xml_bytes)

    # The response can be a single <alert> or multiple wrapped in a root element.
    # Handle both: if root IS an alert, wrap it; otherwise iterate children.
    if root.tag == f"{CAP_NS}alert":
        alert_elements = [root]
    else:
        alert_elements = root.findall(f"{CAP_NS}alert")
        # Also try without namespace in case AEMET omits it
        if not alert_elements:
            alert_elements = root.findall("alert")

    for alert_el in alert_elements:
        sender = _text(alert_el, f"{CAP_NS}sender") or _text(alert_el, "sender")

        # Each <alert> can have multiple <info> blocks (one per language).
        # Prefer Spanish ("es") if available.
        info_elements = alert_el.findall(f"{CAP_NS}info")
        if not info_elements:
            info_elements = alert_el.findall("info")
        if not info_elements:
            continue

        info = _pick_spanish(info_elements) or info_elements[0]

        severity_raw = (
            _text(info, f"{CAP_NS}severity")
            or _text(info, "severity")
            or "Unknown"
        )
        severity = SEVERITY_MAP.get(severity_raw, "yellow")

        headline = (
            _text(info, f"{CAP_NS}headline")
            or _text(info, "headline")
            or ""
        )
        description = (
            _text(info, f"{CAP_NS}description")
            or _text(info, "description")
            or headline
        )
        event_type = (
            _text(info, f"{CAP_NS}event")
            or _text(info, "event")
        )
        onset = (
            _text(info, f"{CAP_NS}onset")
            or _text(info, "onset")
        )
        expires = (
            _text(info, f"{CAP_NS}expires")
            or _text(info, "expires")
        )
        certainty = (
            _text(info, f"{CAP_NS}certainty")
            or _text(info, "certainty")
        )
        urgency = (
            _text(info, f"{CAP_NS}urgency")
            or _text(info, "urgency")
        )
        web = (
            _text(info, f"{CAP_NS}web")
            or _text(info, "web")
        )

        # Collect area descriptions
        area_parts = []
        for area_el in info.findall(f"{CAP_NS}area"):
            desc = _text(area_el, f"{CAP_NS}areaDesc")
            if desc:
                area_parts.append(desc)
        if not area_parts:
            for area_el in info.findall("area"):
                desc = _text(area_el, "areaDesc")
                if desc:
                    area_parts.append(desc)

        area_str = "; ".join(area_parts) if area_parts else None

        alerts.append(
            Alert(
                source="aemet",
                severity=severity,
                headline=headline,
                description=description,
                area=area_str,
                event_type=event_type,
                onset=onset,
                expires=expires,
                certainty=certainty,
                urgency=urgency,
                sender=sender,
                web=web,
            )
        )

    return alerts


def _text(parent: ET.Element, tag: str) -> str | None:
    """Get text content of a child element, or None."""
    el = parent.find(tag)
    if el is not None and el.text:
        return el.text.strip()
    return None


def _pick_spanish(infos: list) -> ET.Element | None:
    """Pick the <info> block with language='es' or 'es-ES'."""
    for info in infos:
        lang = (
            _text(info, f"{CAP_NS}language")
            or _text(info, "language")
            or ""
        )
        if lang.lower().startswith("es"):
            return info
    return None


def fetch() -> List[Alert]:
    """Fetch current AEMET weather alerts for Spain.

    Returns a list of Alert objects following the AUXIO schema.
    Returns an empty list if the API key is missing or the request fails.
    """
    if not API_KEY:
        print("[aemet] AEMET_API_KEY not set, skipping.")
        return []

    try:
        datos_url = _get_data_url("esp")
        xml_bytes = _request(datos_url, accept="application/xml")
        return _parse_cap(xml_bytes)
    except (URLError, ValueError, ET.ParseError) as exc:
        print(f"[aemet] Error fetching alerts: {exc}")
        return []
