#!/bin/bash
#
# Raspberry Pi Full SD Backup – ENDGÜLTIG & STABIL
#

set -euo pipefail

############################
# KONFIGURATION
############################
BACKUP_BASE="/media/peter/USBBACKUP"
BACKUP_DIR="$BACKUP_BASE/backups"
LOCKFILE="/home/peter/backup-running"

DATE="$(date +%Y-%m-%d_%H-%M)"
STATUS_OK="${DATE}_$BACKUP_BASE/ok.txt"
STATUS_ABORT="$BACKUP_BASE/${DATE}_abgebrochen.txt"

LOGFILE="$BACKUP_DIR/${DATE}_backup.log"
IMAGE="$BACKUP_DIR/${DATE}_raspi.img.gz"
MYSQL_DUMP="$BACKUP_DIR/${DATE}_mysql.sql.gz"

SERVICE_BACKUP="$BACKUP_DIR/${DATE}_services.tar.gz"
LOGROTATE_BACKUP="$BACKUP_DIR/${DATE}_logrotate.tar.gz"
CRON_BACKUP="$BACKUP_DIR/${DATE}_cron.txt"
CUSTOM_SCRIPTS_BACKUP="$BACKUP_DIR/${DATE}_custom_scripts.tar.gz"
PKG_LIST="$BACKUP_DIR/${DATE}_packages.list"
PKG_MANUAL="$BACKUP_DIR/${DATE}_packages_manual.txt"
SYSFILES_BACKUP="$BACKUP_DIR/${DATE}_sysfiles.tar.gz"

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
    echo "ABGEBROCHEN $(date) – Keine Schreibrechte auf USB-Stick" > "$STATUS_ABORT"
    exit 1
fi

############################
# AUFRÄUMEN
############################
find "$BACKUP_BASE" -maxdepth 1 -type f \
  \( -name 'ok_*.txt' -o -name 'abgebrochen_*.txt' \) \
  -mtime +15 -delete 2>/dev/null || true

find "$BACKUP_DIR" -type f \
  \( -name 'raspi_*.img.gz' -o -name 'mysql_*.sql.gz' -o -name '*.log' \
     -o -name 'services_*.tar.gz' -o -name 'logrotate_*.tar.gz' \
     -o -name 'custom_scripts_*.tar.gz' -o -name 'cron_*.txt' \
     -o -name 'packages_*.list' -o -name 'packages_manual_*.txt' \
     -o -name 'sysfiles_*.tar.gz' \) \
  -mtime +15 -delete 2>/dev/null || true

############################
# VORBEREITUNG
############################
mkdir -p "$BACKUP_DIR"
touch "$LOCKFILE"
trap 'rm -f "$LOCKFILE"' EXIT

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

stop_service() {
  local s="$1"
  if service_exists "$s"; then
    echo "  -> stop $s"
    timeout 30s sudo -n systemctl stop "$s.service" \
      || echo "WARNUNG: Stop von $s nicht sauber"
  fi
}

start_service() {
  local s="$1"
  if service_exists "$s"; then
    echo "  -> start $s"
    timeout 30s sudo -n systemctl start "$s.service" \
      || echo "WARNUNG: Start von $s nicht sauber"
  fi
}

############################
# [1/9] MARIA DB DUMP
############################
echo "[1/9] MariaDB Dump"
if ! mysqldump --single-transaction --routines --events --triggers --all-databases \
    | gzip > "$MYSQL_DUMP"; then
    echo "WARNUNG – MariaDB Dump fehlgeschlagen"
    rm -f "$MYSQL_DUMP" || true
fi

############################
# [2/9] SYSTEM & KONFIG
############################
echo "[2/9] Systemkonfiguration sichern"

tar -czf "$SERVICE_BACKUP" /etc/systemd/system 2>/dev/null || true
tar -czf "$LOGROTATE_BACKUP" /etc/logrotate.d 2>/dev/null || true
crontab -l > "$CRON_BACKUP" 2>/dev/null || true

CUSTOM_PATHS=()
[ -d /home/peter/scripts ] && CUSTOM_PATHS+=("/home/peter/scripts")
[ -d /opt ] && CUSTOM_PATHS+=("/opt")
[ -d /usr/local/bin ] && CUSTOM_PATHS+=("/usr/local/bin")

[ "${#CUSTOM_PATHS[@]}" -gt 0 ] \
  && tar -czf "$CUSTOM_SCRIPTS_BACKUP" "${CUSTOM_PATHS[@]}" 2>/dev/null || true

dpkg --get-selections > "$PKG_LIST" 2>/dev/null || true
apt-mark showmanual > "$PKG_MANUAL" 2>/dev/null || true

SYSFILES=(/etc/fstab /etc/hostname /etc/hosts /etc/passwd /etc/group /etc/shadow /etc/sudoers)
EXISTING=()
for f in "${SYSFILES[@]}"; do
  [ -f "$f" ] && EXISTING+=("$f")
done
[ "${#EXISTING[@]}" -gt 0 ] \
  && tar -czf "$SYSFILES_BACKUP" "${EXISTING[@]}" 2>/dev/null || true

############################
# [3/9] FREIER SPEICHER
############################
FREE_BYTES=$(df -B1 --output=avail "$BACKUP_DIR/." | tail -1)
FREE_GB=$((FREE_BYTES / 1024 / 1024 / 1024))
echo "[3/9] Freier Speicher: ${FREE_GB} GB"

if (( FREE_GB < MIN_FREE_GB )); then
    echo "ABGEBROCHEN $(date) – Zu wenig freier Speicher (${FREE_GB} GB)" > "$STATUS_ABORT"
    exit 1
fi

############################
# [4/9] ZERO-FILL (KOMPRESSION)
############################
echo "[4/9] Zero-Fill (best effort) derzeit nicht aktiv"
#(
#  set +e
#  sudo -n dd if=/dev/zero of="$BACKUP_DIR/.zero.fill" bs=1M status=none
#  sync
#  rm -f "$BACKUP_DIR/.zero.fill"
#  sync
#) || true

############################
# [5/9] SERVICES STOPPEN
############################
echo "[5/9] Stoppe Services"
for s in "${SERVICES[@]}"; do
  stop_service "$s"
done

############################
# [6/9] SD-KARTEN-BACKUP
############################
echo "[6/9] SD-Backup läuft – NICHT abbrechen"
if ! sudo -n dd if=/dev/mmcblk0 bs=4M status=progress | gzip > "$IMAGE"; then
    echo "ABGEBROCHEN $(date) – Fehler beim SD-Backup" > "$STATUS_ABORT"
    exit 1
fi

############################
# [7/9] SERVICES STARTEN
############################
echo "[7/9] Starte Services"
for s in "${SERVICES[@]}"; do
  start_service "$s"
done

############################
# [8/9] ABSCHLUSS
############################
sudo -n chown peter:peter "$BACKUP_DIR"/* 2>/dev/null || true
echo "OK $(date) – Backup erfolgreich" > "$STATUS_OK"

echo "Backup abgeschlossen: $(date)"
ls -lh "$IMAGE"
[ -f "$MYSQL_DUMP" ] && ls -lh "$MYSQL_DUMP"
