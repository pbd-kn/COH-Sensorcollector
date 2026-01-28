#!/bin/bash

LOGFILE="/home/peter/coh/logs/sensor-collect.log"
SERVICE="collect.service"

OUTPUT=""

# Service prüfen
if ! systemctl is-active --quiet "$SERVICE"; then
    OUTPUT+="service collect stopped "
fi

# Log prüfen (Anzahl Treffer direkt ermitteln)
if [ -f "$LOGFILE" ]; then
    ERRORS=$(grep -i -c "error" "$LOGFILE")
    if [ "$ERRORS" -gt 0 ]; then
        OUTPUT+="Errors in logfile: $ERRORS "
    fi
fi

# Ausgabe
if [ -z "$OUTPUT" ]; then
    printf "0"
else
    printf "%s" "$OUTPUT"
fi



