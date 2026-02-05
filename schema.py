"""Contrato de datos normalizado para todas las fuentes de AUXIO."""

from dataclasses import dataclass
from typing import Optional


@dataclass
class Alert:
    source: str  # "aemet", "ign", "dgt", "proteccion_civil"
    severity: str  # "red", "orange", "yellow", "green"
    headline: str
    description: str
    area: Optional[str] = None
    event_type: Optional[str] = None
    onset: Optional[str] = None
    expires: Optional[str] = None
    certainty: Optional[str] = None
    urgency: Optional[str] = None
    sender: Optional[str] = None
    web: Optional[str] = None
