#!/bin/bash
#
# Raspberry Pi Full SD Backup – USB Automount Version
#
# Aufbewahrung:
#   Statusdateien : 15 Tage
#   SD-Backups    : 15 Tage
#   MySQL-Dumps   : 15 Tage
#   Logfiles      : 15 Tage
#   Zusatzdaten   : 15 Tage
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

# Zusatz-Backups (NEU – nur lesen)
SERVICE_BACKUP="$BACKUP_DIR/services_${DATE}.tar.gz"
LOGROTATE_BACKUP="$BACKUP_DIR/logrotate_${DATE}.tar.gz"
CRON_BACKUP="$BACKUP_DIR/cron_${DATE}.txt"
CUSTOM_SCRIPTS_BACKUP="$BACKUP_DIR/custom_scripts_${DATE}.tar.gz"
PKG_LIST="$BACKUP_DIR/packages_${DATE}.list"
PKG_MANUAL="$BACKUP_DIR/packages_manual_${DATE}.txt"
SYSFILES_BACKUP="$BACKUP_DIR/sysfiles_${DATE}.tar.gz"

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
# AUFRÄUMEN – STATUS
############################
find "$BACKUP_BASE" -maxdepth 1 -type f \
  \( -name 'ok_*.txt' -o -name 'abgebrochen_*.txt' \) \
  -mtime +15 -delete 2>/dev/null || true

############################
# AUFRÄUMEN – BACKUPS
############################
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
cleanup() { rm -f "$LOCKFILE"; }
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
service_exists() { systemctl show "$1.service" >/dev/null 2>&1; }
service_active() { systemctl is-active --quiet "$1.service"; }

############################
# [1/9] MARIA DB DUMP
############################
if service_exists mariadb && service_active mariadb; then
    echo "[1/9] MariaDB Dump"
    if ! mysqldump --single-transaction --routines --events --triggers --all-databases \
        | gzip > "$MYSQL_DUMP"; then
        echo "WARNUNG – MariaDB Dump fehlgeschlagen"
        rm -f "$MYSQL_DUMP" || true
    fi
else
    echo "[1/9] MariaDB nicht aktiv – übersprungen"
fi

############################
# [2/9] SYSTEM & SERVICES SICHERN (RISIKOFREI)
############################
echo "[2/9] Sichere Konfiguration & Systeminfos"

tar -czf "$SERVICE_BACKUP" /etc/systemd/system 2>/dev/null || true
tar -czf "$LOGROTATE_BACKUP" /etc/logrotate.d 2>/dev/null || true
crontab -l > "$CRON_BACKUP" 2>/dev/null || true

CUSTOM_PATHS=()
[ -d /home/peter/scripts ] && CUSTOM_PATHS+=("/home/peter/scripts")
[ -d /opt ] && CUSTOM_PATHS+=("/opt")
[ -d /usr/local/bin ] && CUSTOM_PATHS+=("/usr/local/bin")

[ "${#CUSTOM_PATHS[@]}" -gt 0 ] && tar -czf "$CUSTOM_SCRIPTS_BACKUP" "${CUSTOM_PATHS[@]}" 2>/dev/null || true

dpkg --get-selections > "$PKG_LIST" 2>/dev/null || true
apt-mark showmanual > "$PKG_MANUAL" 2>/dev/null || true

SYSFILES=(/etc/fstab /etc/hostname /etc/hosts /etc/passwd /etc/group /etc/shadow /etc/sudoers)
EXISTING=()
for f in "${SYSFILES[@]}"; do [ -f "$f" ] && EXISTING+=("$f"); done
[ "${#EXISTING[@]}" -gt 0 ] && tar -czf "$SYSFILES_BACKUP" "${EXISTING[@]}" 2>/dev/null || true

############################
# [3/9] FREIER SPEICHER
############################
FREE_BYTES=$(df -B1 --output=avail "$BACKUP_DIR/." | tail -1)
FREE_GB=$((FREE_BYTES / 1024 / 1024 / 1024))
echo "[3/9] Freier Speicher: ${FREE_GB} GB"

############################
# [4/9] ZERO-FILL
############################
echo "[4/9] Zero-Fill"
pv -f --size "$FREE_BYTES" /dev/zero | dd of="$BACKUP_DIR/.zero.fill" bs=1M status=none || true
sync
rm -f "$BACKUP_DIR/.zero.fill"
sync

############################
# [5/9] PLATZ-PRÜFUNG
############################
FREE_GB_AFTER=$(df -BG --output=avail "$BACKUP_DIR/." | tail -1 | tr -dc '0-9')
if (( FREE_GB_AFTER < MIN_FREE_GB )); then
    echo "ABGEBROCHEN $(date) – Zu wenig freier Speicher (${FREE_GB_AFTER} GB)" > "$STATUS_ABORT"
    exit 1
fi

############################
# [6/9] SERVICES STOPPEN
############################
echo "[6/9] Stoppe Services"
for s in "${SERVICES[@]}"; do
    service_exists "$s" && systemctl stop "$s.service" || true
done

############################
# [7/9] SD-KARTEN-BACKUP
############################
echo "[7/9] SD-Backup läuft – NICHT abbrechen"
if ! dd if=/dev/mmcblk0 bs=4M status=progress | gzip > "$IMAGE"; then
    echo "ABGEBROCHEN $(date) – Fehler beim SD-Backup" > "$STATUS_ABORT"
    exit 1
fi

############################
# [8/9] SERVICES STARTEN
############################
echo "[8/9] Starte Services"
for s in "${SERVICES[@]}"; do
    service_exists "$s" && systemctl start "$s.service" || true
done

############################
# [9/9] ABSCHLUSS
############################
chown peter:peter "$BACKUP_DIR"/* 2>/dev/null || true
echo "OK $(date) – Backup erfolgreich" > "$STATUS_OK"

echo "Backup abgeschlossen: $(date)"
ls -lh "$IMAGE"
[ -f "$MYSQL_DUMP" ] && ls -lh "$MYSQL_DUMP"
