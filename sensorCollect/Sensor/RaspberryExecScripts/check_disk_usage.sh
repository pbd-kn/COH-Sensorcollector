#!/bin/bash
LIMIT=70
LOGFILE="/var/log/check_disk_usage.log"
PART="/dev/mmcblk0p2"
TO="pbd@gmx.de"

USAGE=$(df --output=pcent "$PART" | tail -n 1 | tr -dc '0-9')
SCRIPT_PATH="$(readlink -f "$0")"
HOST=$(hostname)
NOW=$(date '+%F %T')

if [ "$USAGE" -ge "$LIMIT" ]; then

  SUBJECT="Raspberry Speicherwarnung: $HOST - Limit $LIMIT%, aktuell ${USAGE}% auf ${PART}"

  BODY="Warnung: Speicher auf $HOST ($PART) ist mit ${USAGE}% belegt.

Zeit: $NOW

df -h $PART:
$(df -h "$PART")

Skript: $SCRIPT_PATH

Hinweis: In phpmyadmin http://raspberrypi/phpmyadmin (Peter/sql666sql) kann die tabelle tl_coh_sensorvalue auf 1/2 Jahr verkleinert werden.
Testlauf:
SELECT * FROM tl_coh_sensorvalue
WHERE tstamp < UNIX_TIMESTAMP(NOW() - INTERVAL 6 MONTH);

Löschen:
DELETE FROM tl_coh_sensorvalue
WHERE tstamp < UNIX_TIMESTAMP(NOW() - INTERVAL 6 MONTH);
"

  echo "$NOW - WARNUNG gesendet (USAGE=${USAGE}%, LIMIT=${LIMIT}%)" >> "$LOGFILE"
  printf "Subject: %s\nFrom: pbd@gmx.de\nTo: %s\nMIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\n\n%s\n" \
  "$SUBJECT" "$TO" "$BODY" | msmtp -a gmx "$TO"
else
  echo "$NOW - geprueft (USAGE=${USAGE}%, LIMIT=${LIMIT}%)" >> "$LOGFILE"
fi

# ergebnis als JSON manuell als String bauen
json="[ { \"Partition\": \"${PART}\", \"value\": ${USAGE}, \"einheit\": \"%\" } ]"
echo "$json"


