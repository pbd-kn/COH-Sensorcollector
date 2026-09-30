#!/bin/bash

LIMIT=70
LOGFILE="/home/peter/coh/logs/check_disk_usage.log"

# Diese Mountpoints müssen immer eingehängt sein.
REQUIRED_MOUNTPOINTS=(
  "/"
  "/mnt/data"
  "/media/peter/USBBACKUP"
)

# Wartezeit bei zunächst fehlendem Mountpoint.
MOUNT_RETRY_SECONDS=60

NOW="$(date '+%F %T')"

JSON_ITEMS=()
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

    echo "$NOW - INFO: $PART zunächst nicht eingehängt, erneute Prüfung in ${MOUNT_RETRY_SECONDS}s" >> "$LOGFILE"

    sleep "$MOUNT_RETRY_SECONDS"

    NOW="$(date '+%F %T')"

    if ! mountpoint -q "$PART"; then
      ERROR_TEXT="$PART ist nicht eingehängt"

      echo "$NOW - FEHLER: $ERROR_TEXT" >> "$LOGFILE"

      JSON_ITEMS+=(
        "{\"Partition\":\"${PART}\",\"value\":0,\"einheit\":\"%\",\"totalHuman\":\"0\",\"usedHuman\":\"0\",\"availableHuman\":\"0\",\"status\":\"nicht eingehängt\"}"
      )

      # Fehler nur im JSON und Log melden.
      continue
    else
      echo "$NOW - INFO: $PART ist nach erneuter Prüfung eingehängt" >> "$LOGFILE"
    fi
  fi

  # Gesamt, belegt und verfügbar in Bytes abfragen.
  if ! DF_RESULT="$(LC_ALL=C df -P -B1 "$PART" 2>/dev/null)"; then
    ERROR_TEXT="$PART konnte nicht geprüft werden"

    echo "$NOW - FEHLER: $ERROR_TEXT" >> "$LOGFILE"

    JSON_ITEMS+=(
      "{\"Partition\":\"${PART}\",\"value\":0,\"einheit\":\"%\",\"totalHuman\":\"0\",\"usedHuman\":\"0\",\"availableHuman\":\"0\",\"status\":\"nicht lesbar\"}"
    )

    # Fehler nur im JSON und Log melden.
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
      "{\"Partition\":\"${PART}\",\"value\":0,\"einheit\":\"%\",\"totalHuman\":\"0\",\"usedHuman\":\"0\",\"availableHuman\":\"0\",\"status\":\"ungültiger Wert\"}"
    )

    # Fehler nur im JSON und Log melden.
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

  NOW="$(date '+%F %T')"

  echo "$NOW - geprüft ($PART: USAGE=${USAGE}%, LIMIT=${LIMIT}%)" >> "$LOGFILE"

  # Grenzwert prüfen.
  LIMIT_REACHED="$(
    LC_ALL=C awk \
      -v usage="$USAGE" \
      -v limit="$LIMIT" \
      'BEGIN {print (usage >= limit) ? 1 : 0}'
  )"

  if [ "$LIMIT_REACHED" -eq 1 ]; then
    echo "$NOW - FEHLER: $PART: ${USAGE}% belegt (Grenzwert: ${LIMIT}%), Gesamt: ${TOTAL_HUMAN}, Belegt: ${USED_HUMAN}, Frei: ${AVAILABLE_HUMAN}" >> "$LOGFILE"
  fi
done

# JSON-Ausgabe erzeugen.
(
  IFS=,
  printf '[%s]\n' "${JSON_ITEMS[*]}"
)