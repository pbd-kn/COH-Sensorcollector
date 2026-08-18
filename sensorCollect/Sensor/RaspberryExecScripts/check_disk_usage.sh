#!/bin/bash

LIMIT=70
LOGFILE="/home/peter/coh/logs/check_disk_usage.log"
TO="pbd@gmx.de"

# Diese Mountpoints müssen immer eingehängt sein.
REQUIRED_MOUNTPOINTS=(
  "/"
  "/mnt/data"
  "/media/peter/USBBACKUP"
)

SCRIPT_PATH="$(readlink -f "$0")"
HOST="$(hostname)"
NOW="$(date '+%F %T')"

JSON_ITEMS=()
WARNINGS=()
DF_OUTPUT=""
PARTS=()

# Alle aktuell eingehängten echten Datenträger ermitteln.
mapfile -t DETECTED_MOUNTPOINTS < <(
  findmnt -rn -o TARGET,SOURCE |
    awk '$2 ~ "^/dev/" {print $1}' |
    sort -u
)

# Automatisch erkannte Mountpoints übernehmen.
for PART in "${DETECTED_MOUNTPOINTS[@]}"; do
  PARTS+=("$PART")
done

# Pflicht-Mountpoints hinzufügen, falls sie nicht erkannt wurden.
# Dadurch können fehlende Laufwerke gemeldet werden.
for REQUIRED_PART in "${REQUIRED_MOUNTPOINTS[@]}"; do
  FOUND=false

  for PART in "${PARTS[@]}"; do
    if [ "$PART" = "$REQUIRED_PART" ]; then
      FOUND=true
      break
    fi
  done

  if [ "$FOUND" = false ]; then
    PARTS+=("$REQUIRED_PART")
  fi
done

# Alle Dateisysteme prüfen.
for PART in "${PARTS[@]}"; do

  # Prüfen, ob der Mountpoint wirklich eingehängt ist.
  if ! mountpoint -q "$PART"; then
    ERROR_TEXT="$PART ist nicht eingehängt"

    echo "$NOW - FEHLER: $ERROR_TEXT" >> "$LOGFILE"

    JSON_ITEMS+=(
      "{\"Partition\":\"${PART}\",\"value\":null,\"einheit\":\"%\",\"totalHuman\":null,\"usedHuman\":null,\"availableHuman\":null,\"status\":\"nicht eingehängt\"}"
    )

    WARNINGS+=("$ERROR_TEXT")
    continue
  fi

  # Gesamt, belegt und verfügbar in Bytes abfragen.
  if ! DF_RESULT="$(LC_ALL=C df -P -B1 "$PART" 2>/dev/null)"; then
    ERROR_TEXT="$PART konnte nicht geprüft werden"

    echo "$NOW - FEHLER: $ERROR_TEXT" >> "$LOGFILE"

    JSON_ITEMS+=(
      "{\"Partition\":\"${PART}\",\"value\":null,\"einheit\":\"%\",\"totalHuman\":null,\"usedHuman\":null,\"availableHuman\":null,\"status\":\"nicht lesbar\"}"
    )

    WARNINGS+=("$ERROR_TEXT")
    continue
  fi

  TOTAL_BYTES="$(
    printf '%s\n' "$DF_RESULT" |
      awk 'NR==2 {print $2}'
  )"

  USED_BYTES="$(
    printf '%s\n' "$DF_RESULT" |
      awk 'NR==2 {print $3}'
  )"

  AVAILABLE_BYTES="$(
    printf '%s\n' "$DF_RESULT" |
      awk 'NR==2 {print $4}'
  )"

  # Prüfen, ob gültige Zahlen geliefert wurden.
  if ! [[ "$TOTAL_BYTES" =~ ^[0-9]+$ ]] ||
     ! [[ "$USED_BYTES" =~ ^[0-9]+$ ]] ||
     ! [[ "$AVAILABLE_BYTES" =~ ^[0-9]+$ ]] ||
     [ "$TOTAL_BYTES" -eq 0 ]; then

    ERROR_TEXT="$PART lieferte keine gültigen Speicherwerte"

    echo "$NOW - FEHLER: $ERROR_TEXT" >> "$LOGFILE"

    JSON_ITEMS+=(
      "{\"Partition\":\"${PART}\",\"value\":null,\"einheit\":\"%\",\"totalHuman\":null,\"usedHuman\":null,\"availableHuman\":null,\"status\":\"ungültiger Wert\"}"
    )

    WARNINGS+=("$ERROR_TEXT")
    continue
  fi

  # Prozentuale Belegung mit zwei Nachkommastellen.
  USAGE="$(
    LC_ALL=C awk \
      -v used="$USED_BYTES" \
      -v total="$TOTAL_BYTES" \
      'BEGIN {printf "%.2f", (used / total) * 100}'
  )"

  # Gesamtgröße lesbar formatieren.
  TOTAL_HUMAN="$(
    numfmt \
      --to=iec-i \
      --suffix=B \
      --format="%.2f" \
      "$TOTAL_BYTES" |
      sed -E 's/([0-9])([KMGTPE]iB)$/\1 \2/'
  )"

  # Belegten Speicher lesbar formatieren.
  USED_HUMAN="$(
    numfmt \
      --to=iec-i \
      --suffix=B \
      --format="%.2f" \
      "$USED_BYTES" |
      sed -E 's/([0-9])([KMGTPE]iB)$/\1 \2/'
  )"

  # Freien Speicher lesbar formatieren.
  AVAILABLE_HUMAN="$(
    numfmt \
      --to=iec-i \
      --suffix=B \
      --format="%.2f" \
      "$AVAILABLE_BYTES" |
      sed -E 's/([0-9])([KMGTPE]iB)$/\1 \2/'
  )"

  JSON_ITEMS+=(
    "{\"Partition\":\"${PART}\",\"value\":${USAGE},\"einheit\":\"%\",\"totalHuman\":\"${TOTAL_HUMAN}\",\"usedHuman\":\"${USED_HUMAN}\",\"availableHuman\":\"${AVAILABLE_HUMAN}\",\"status\":\"OK\"}"
  )

  echo "$NOW - geprüft ($PART: USAGE=${USAGE}%, LIMIT=${LIMIT}%)" >> "$LOGFILE"

  # Grenzwert prüfen.
  LIMIT_REACHED="$(
    LC_ALL=C awk \
      -v usage="$USAGE" \
      -v limit="$LIMIT" \
      'BEGIN {print (usage >= limit) ? 1 : 0}'
  )"

  if [ "$LIMIT_REACHED" -eq 1 ]; then
    WARNINGS+=(
      "$PART: ${USAGE}% belegt, Gesamt: ${TOTAL_HUMAN}, Belegt: ${USED_HUMAN}, Frei: ${AVAILABLE_HUMAN}"
    )

    DF_OUTPUT+=$'\n'
    DF_OUTPUT+="df -h ${PART}:"
    DF_OUTPUT+=$'\n'
    DF_OUTPUT+="$(df -h "$PART")"
    DF_OUTPUT+=$'\n'
  fi
done

# Warnmail versenden.
if [ "${#WARNINGS[@]}" -gt 0 ]; then
  WARNING_LIST="$(printf -- '- %s\n' "${WARNINGS[@]}")"

  SUBJECT="Raspberry Speicherwarnung: $HOST"

  BODY="Warnung auf $HOST:

${WARNING_LIST}
Grenzwert: ${LIMIT}%
Zeit: $NOW
${DF_OUTPUT}
Skript: $SCRIPT_PATH

Hinweis: In phpMyAdmin kann die Tabelle tl_coh_sensorvalue
auf ein halbes Jahr verkleinert werden.

Testlauf:
SELECT * FROM tl_coh_sensorvalue
WHERE tstamp < UNIX_TIMESTAMP(NOW() - INTERVAL 6 MONTH);

Löschen:
DELETE FROM tl_coh_sensorvalue
WHERE tstamp < UNIX_TIMESTAMP(NOW() - INTERVAL 6 MONTH);
"

  if printf \
    "Subject: %s\nFrom: pbd@gmx.de\nTo: %s\nMIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\n\n%s\n" \
    "$SUBJECT" "$TO" "$BODY" |
    msmtp -a gmx "$TO"
  then
    echo "$NOW - WARNUNG für ${#WARNINGS[@]} Problem(e) gesendet" >> "$LOGFILE"
  else
    echo "$NOW - FEHLER: Warnmail konnte nicht gesendet werden" >> "$LOGFILE"
  fi
fi

# JSON-Ausgabe erzeugen.
(
  IFS=,
  printf '[%s]\n' "${JSON_ITEMS[*]}"
)
