#!/bin/bash
#
# Raspberry Pi Full SD Backup – FINAL
# inkl. Zero-Fill + Monitoring-Lockfile
# crontag eintrag für samstag 23:00
# select-editor
# 0 23 * * 6 /home/peter/bin/raspi-dd-backup.sh
#

set -euo pipefail

############################
# KONFIGURATION
############################
BACKUP_DIR="/home/peter/backups"
LOGFILE="$BACKUP_DIR/backup.log"
LOCKFILE="/run/backup-running"

DATE="$(date +%Y-%m-%d_%H-%M)"
IMAGE="$BACKUP_DIR/raspi_${DATE}.img.gz"
MYSQL_DUMP="$BACKUP_DIR/mysql_${DATE}.sql.gz"

SERVICES=("mariadb" "heizstab" "collect")
MIN_FREE_GB=15

############################
# VORBEREITUNG
############################
mkdir -p "$BACKUP_DIR"

# Lockfile setzen (für Monitoring)
touch "$LOCKFILE"

# Sicherstellen, dass Lockfile immer entfernt wird
cleanup() {
    rm -f "$LOCKFILE"
}
trap cleanup EXIT

# Alte Backups + Logfile löschen
rm -f "$BACKUP_DIR"/raspi_*.img.gz \
      "$BACKUP_DIR"/mysql_*.sql.gz \
      "$LOGFILE" 2>/dev/null || true

# Logging: alles auf Konsole + Logfile
exec > >(tee -a "$LOGFILE") 2>&1

echo "======================================="
echo "Backup gestartet: $(date)"
echo "Backup-Verzeichnis: $BACKUP_DIR"
echo "SD-Image          : $IMAGE"
echo "DB-Dump           : $MYSQL_DUMP"
echo "Lockfile          : $LOCKFILE"
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
    if mysqldump \
        --single-transaction \
        --routines \
        --events \
        --triggers \
        --all-databases \
        | gzip > "$MYSQL_DUMP"
    then
        ls -lh "$MYSQL_DUMP"
    else
        echo "⚠️ MariaDB Dump fehlgeschlagen – Backup läuft weiter"
        rm -f "$MYSQL_DUMP" 2>/dev/null || true
    fi
else
    echo "[1/8] MariaDB nicht aktiv – kein Dump"
fi

############################
# [2/8] FREIEN SPEICHER ERMITTELN
############################
FREE_BYTES=$(df -B1 --output=avail "$BACKUP_DIR/." | tail -1)
FREE_MB=$((FREE_BYTES / 1024 / 1024))
FREE_GB=$((FREE_MB / 1024))

echo "[2/8] Freier Speicher: ${FREE_GB} GB (${FREE_MB} MB)"

############################
# [3/8] ZERO-FILL
############################
echo "[3/8] Zero-Fill – absichtliche Vollbelegung (Monitoring pausiert)"

sudo pv -f --size "$FREE_BYTES" /dev/zero \
    2> >(cat >/dev/tty) \
| sudo dd of="$BACKUP_DIR/.zero.fill" bs=1M status=none || true

sync
sudo rm -f "$BACKUP_DIR/.zero.fill"
sync

echo "✔ Zero-Fill abgeschlossen"

############################
# [4/8] PLATZ-PRÜFUNG
############################
FREE_GB_AFTER=$(df -BG --output=avail "$BACKUP_DIR/." | tail -1 | tr -dc '0-9')
echo "[4/8] Freier Speicher nach Zero-Fill: ${FREE_GB_AFTER} GB"

if (( FREE_GB_AFTER < MIN_FREE_GB )); then
    echo "❌ Abbruch: Zu wenig freier Speicher"
    exit 1
fi

############################
# [5/8] SERVICES STOPPEN
############################
echo "[5/8] Stoppe Services..."
for s in "${SERVICES[@]}"; do
    if service_exists "$s"; then
        sudo systemctl stop "$s.service" || true
        echo "→ $s gestoppt"
    fi
done

############################
# [6/8] SD-KARTEN-BACKUP
############################
echo "[6/8] SD-Backup läuft – NICHT abbrechen!"
sudo dd if=/dev/mmcblk0 bs=4M status=progress | gzip > "$IMAGE"

############################
# [7/8] SERVICES STARTEN
############################
echo "[7/8] Starte Services..."
for s in "${SERVICES[@]}"; do
    if service_exists "$s"; then
        sudo systemctl start "$s.service" || true
        echo "→ $s gestartet"
    fi
done

############################
# [8/8] ABSCHLUSS
############################
sudo chown peter:peter "$IMAGE" "$MYSQL_DUMP" 2>/dev/null || true

echo "[8/8] Backup abgeschlossen"
ls -lh "$IMAGE"

if [ -f "$MYSQL_DUMP" ]; then
    ls -lh "$MYSQL_DUMP"
else
    echo "ℹ️ Kein MySQL-Dump in diesem Lauf erzeugt"
fi

echo "Fertig: $(date)"
