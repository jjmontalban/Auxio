<?php

/**
 * AUXIO - Esquema normalizado de alertas
 * 
 * Clase que representa una alerta de emergencia normalizada
 * desde cualquier fuente (AEMET, IGN, etc)
 */

class Alert {
    public string $source;        // "aemet", "ign", etc
    public string $severity;      // "red", "orange", "yellow", "green"
    public string $headline;
    public string $description;
    public ?string $area = null;
    public ?string $event_type = null;
    public ?string $onset = null;
    public ?string $expires = null;
    public ?string $certainty = null;
    public ?string $urgency = null;
    public ?string $sender = null;
    public ?string $web = null;

    public function __construct(
        string $source,
        string $severity,
        string $headline,
        string $description,
        ?string $area = null,
        ?string $event_type = null,
        ?string $onset = null,
        ?string $expires = null,
        ?string $certainty = null,
        ?string $urgency = null,
        ?string $sender = null,
        ?string $web = null
    ) {
        $this->source = $source;
        $this->severity = $severity;
        $this->headline = $headline;
        $this->description = $description;
        $this->area = $area;
        $this->event_type = $event_type;
        $this->onset = $onset;
        $this->expires = $expires;
        $this->certainty = $certainty;
        $this->urgency = $urgency;
        $this->sender = $sender;
        $this->web = $web;
    }

    /**
     * Convertir alerta a array para JSON
     */
    public function toArray(): array {
        return [
            'source' => $this->source,
            'severity' => $this->severity,
            'headline' => $this->headline,
            'description' => $this->description,
            'area' => $this->area,
            'event_type' => $this->event_type,
            'onset' => $this->onset,
            'expires' => $this->expires,
            'certainty' => $this->certainty,
            'urgency' => $this->urgency,
            'sender' => $this->sender,
            'web' => $this->web,
        ];
    }
}
