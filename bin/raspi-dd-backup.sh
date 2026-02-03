#!/bin/bash
#
# Raspberry Pi Full SD Backup – USB Automount Version
#
# Aufbewahrung:
#   Statusdateien : 30 Tage
#   SD-Backups    : 15 Tage
#   MySQL-Dumps   : 15 Tage
#   Logfiles      : 15 Tage
#

set -euo pipefail

############################
# KONFIGURATION
############################
BACKUP_BASE="/media/peter/USBBACKUP"
BACKUP_DIR="$BACKUP_BASE/backups"
LOCKFILE="/home/peter/backup-running"

STATUS_DATE="$(date +%Y-%m-%d)"
STATUS_OK="$BACKUP_BASE/ok_${STATUS_DATE}.txt"
STATUS_ABORT="$BACKUP_BASE/abgebrochen_${STATUS_DATE}.txt"

DATE="$(date +%Y-%m-%d_%H-%M)"
LOGFILE="$BACKUP_DIR/backup_${DATE}.log"
IMAGE="$BACKUP_DIR/raspi_${DATE}.img.gz"
MYSQL_DUMP="$BACKUP_DIR/mysql_${DATE}.sql.gz"

SERVICES=("mariadb" "heizstab" "collect")
MIN_FREE_GB=15

############################
# USB-STICK PRÜFEN
############################
if ! mountpoint -q "$BACKUP_BASE"; then
    echo "ABGEBROCHEN $(date) – USB-Stick nicht gemountet" > "$STATUS_ABORT"
    exit 0
fi
if [ ! -w "$BACKUP_BASE" ]; then
    echo "ABGEBROCHEN $(date) – Keine Schreibrechte auf USB-Stick" >&2
    exit 1
fi


############################
# AUFRÄUMEN – STATUS (15 TAGE)
############################
find /media/peter/USBBACKUP -maxdepth 1 -type f \( -name 'ok_*.txt' -o -name 'abgebrochen_*.txt' \) -mtime +15 -delete 2>/dev/null || true

############################
# AUFRÄUMEN – BACKUPS & MYSQL (15 TAGE)
############################
find /media/peter/USBBACKUP/backups -type f \( -name 'raspi_*.img.gz' -o -name 'mysql_*.sql.gz' \) -mtime +15 -delete 2>/dev/null || true

############################
# AUFRÄUMEN – LOGFILES (15 TAGE)
############################
find /media/peter/USBBACKUP/backups -type f -name '*.log' -mtime +15 -delete 2>/dev/null || true

############################
# VORBEREITUNG
############################
mkdir -p "$BACKUP_DIR"

touch "$LOCKFILE"
cleanup() {
    rm -f "$LOCKFILE"
}
trap cleanup EXIT

exec > >(tee "$LOGFILE") 2>&1

echo "======================================="
echo "Backup gestartet : $(date)"
echo "Backup-Ziel      : $BACKUP_DIR"
echo "Logfile          : $LOGFILE"
echo "======================================="

############################
# HILFSFUNKTIONEN
############################
service_exists() {
    systemctl show "$1.service" >/dev/null 2>&1
}

service_active() {
    systemctl is-active --quiet "$1.service"
}

############################
# [1/8] MARIA DB DUMP
############################
if service_exists mariadb && service_active mariadb; then
    echo "[1/8] MariaDB Dump"
    if ! mysqldump \
        --single-transaction \
        --routines \
        --events \
        --triggers \
        --all-databases \
        | gzip > "$MYSQL_DUMP"
    then
        echo "WARNUNG $(date) – MariaDB Dump fehlgeschlagen"
        rm -f "$MYSQL_DUMP" 2>/dev/null || true
    fi
else
    echo "[1/8] MariaDB nicht aktiv – kein Dump"
fi

############################
# [2/8] FREIER SPEICHER
############################
FREE_BYTES=$(df -B1 --output=avail "$BACKUP_DIR/." | tail -1)
FREE_GB=$((FREE_BYTES / 1024 / 1024 / 1024))
echo "[2/8] Freier Speicher: ${FREE_GB} GB"

############################
# [3/8] ZERO-FILL
############################
echo "[3/8] Zero-Fill"
sudo pv -f --size "$FREE_BYTES" /dev/zero | sudo dd of="$BACKUP_DIR/.zero.fill" bs=1M status=none || true
sync
sudo rm -f "$BACKUP_DIR/.zero.fill"
sync

############################
# [4/8] PLATZ-PRÜFUNG
############################
FREE_GB_AFTER=$(df -BG --output=avail "$BACKUP_DIR/." | tail -1 | tr -dc '0-9')
if (( FREE_GB_AFTER < MIN_FREE_GB )); then
    echo "ABGEBROCHEN $(date) – Zu wenig freier Speicher (${FREE_GB_AFTER} GB)" > "$STATUS_ABORT"
    exit 1
fi

############################
# [5/8] SERVICES STOPPEN
############################
echo "[5/8] Stoppe Services"
for s in "${SERVICES[@]}"; do
    if service_exists "$s"; then
        sudo systemctl stop "$s.service" || true
    fi
done

############################
# [6/8] SD-KARTEN-BACKUP
############################
echo "[6/8] SD-Backup läuft – NICHT abbrechen"
if ! sudo dd if=/dev/mmcblk0 bs=4M status=progress | gzip > "$IMAGE"; then
    echo "ABGEBROCHEN $(date) – Fehler beim SD-Backup (dd)" > "$STATUS_ABORT"
    exit 1
fi

############################
# [7/8] SERVICES STARTEN
############################
echo "[7/8] Starte Services"
for s in "${SERVICES[@]}"; do
    if service_exists "$s"; then
        sudo systemctl start "$s.service" || true
    fi
done

############################
# [8/8] ABSCHLUSS
############################
sudo chown peter:peter "$IMAGE" "$MYSQL_DUMP" "$LOGFILE" 2>/dev/null || true

echo "OK $(date) – Backup erfolgreich" > "$STATUS_OK"

echo "Backup abgeschlossen"
ls -lh "$IMAGE"
[ -f "$MYSQL_DUMP" ] && ls -lh "$MYSQL_DUMP"
echo "Fertig: $(date)"
