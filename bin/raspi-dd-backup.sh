#!/bin/bash
#
# Raspberry Pi FULL BACKUP – GEHÄRTET & VOLLSTÄNDIG
# SD-Image + DB + System/Konfig + Lockfile + Fehlerbehandlung + Service-Restore
#

set -Eeuo pipefail

############################
# BASIS
############################
PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
umask 077

############################
# KONFIG
############################
BACKUP_BASE="/media/peter/USBBACKUP"
BACKUP_DIR="$BACKUP_BASE/backups"
DATE="$(date +%Y-%m-%d_%H-%M-%S)"

IMAGE="$BACKUP_DIR/${DATE}_raspi.img.gz"
MYSQL_DUMP="$BACKUP_DIR/${DATE}_mysql.sql.gz"

SERVICE_BACKUP="$BACKUP_DIR/${DATE}_services.tar.gz"
LOGROTATE_BACKUP="$BACKUP_DIR/${DATE}_logrotate.tar.gz"
CRON_BACKUP_USER="$BACKUP_DIR/${DATE}_cron_peter.txt"
CRON_BACKUP_ROOT="$BACKUP_DIR/${DATE}_cron_root.txt"
CUSTOM_SCRIPTS_BACKUP="$BACKUP_DIR/${DATE}_custom_scripts.tar.gz"
PKG_LIST="$BACKUP_DIR/${DATE}_packages.list"
PKG_MANUAL="$BACKUP_DIR/${DATE}_packages_manual.txt"
SYSFILES_BACKUP="$BACKUP_DIR/${DATE}_sysfiles.tar.gz"

LOGFILE="$BACKUP_BASE/backup.log"
LOCKFILE="/var/lock/raspi-dd-backup.lock"

STATUS_OK="$BACKUP_BASE/ok_${DATE}.txt"
STATUS_ABORT="$BACKUP_BASE/abgebrochen_${DATE}.txt"

SOURCE_DEVICE="/dev/mmcblk0"
DB_USER_TO_EXPORT_CRON="peter"

SERVICES=(
  collect
  heizstab
  mosquitto
  raspi-lima-tunnel
  raspi-local-tunnel
  mariadb
)

STOPPED_SERVICES=()

############################
# FUNKTIONEN
############################
log() {
  echo "[$(date '+%F %T')] $*" | tee -a "$LOGFILE"
}

fail() {
  local msg="${1:-Unbekannter Fehler}"
  log "FEHLER: $msg"
  echo "ABGEBROCHEN $(date '+%F %T') : $msg" > "$STATUS_ABORT"
  exit 1
}

cleanup_on_exit() {
  local exit_code=$?

  # Services wieder starten, falls vorher gestoppt
  if [ "${#STOPPED_SERVICES[@]}" -gt 0 ]; then
    log "[CLEANUP] Starte gestoppte Services wieder"
    for s in "${STOPPED_SERVICES[@]}"; do
      if systemctl list-unit-files | grep -q "^${s}\.service"; then
        if systemctl start "$s"; then
          log " -> Service gestartet: $s"
        else
          log " -> WARNUNG: Service konnte nicht gestartet werden: $s"
        fi
      fi
    done
  fi

  sync || true

  # Lock freigeben
  flock -u 9 || true
  rm -f "$LOCKFILE" || true

  if [ "$exit_code" -eq 0 ]; then
    log "Backup erfolgreich beendet"
  else
    log "Backup mit Fehler beendet (Exit-Code $exit_code)"
    [ -f "$STATUS_ABORT" ] || echo "ABGEBROCHEN $(date '+%F %T') : Exit-Code $exit_code" > "$STATUS_ABORT"
  fi

  exit "$exit_code"
}

on_error() {
  local line="$1"
  local cmd="$2"
  log "FEHLER in Zeile $line: $cmd"
  echo "ABGEBROCHEN $(date '+%F %T') : Zeile $line : $cmd" > "$STATUS_ABORT"
  exit 1
}

check_cmd() {
  command -v "$1" >/dev/null 2>&1 || fail "Befehl nicht gefunden: $1"
}

############################
# TRAPS
############################
trap cleanup_on_exit EXIT
trap 'on_error "${LINENO}" "${BASH_COMMAND}"' ERR

############################
# LOCK
############################
mkdir -p /var/lock
exec 9>"$LOCKFILE"
if ! flock -n 9; then
  fail "Es läuft bereits ein Backup"
fi

############################
# START
############################
mkdir -p "$BACKUP_BASE"
touch "$LOGFILE" || fail "Logdatei kann nicht geschrieben werden: $LOGFILE"

log "===================================="
log "Backup START"
log "===================================="

############################
# VORCHECKS
############################
[ "$(id -u)" -eq 0 ] || fail "Script muss als root laufen"

check_cmd mountpoint
check_cmd mkdir
check_cmd tar
check_cmd gzip
check_cmd dd
check_cmd sync
check_cmd sleep
check_cmd df
check_cmd awk
check_cmd grep
check_cmd sed
check_cmd tee
check_cmd flock
check_cmd blockdev
check_cmd mysqldump
check_cmd dpkg
check_cmd apt-mark
check_cmd crontab
check_cmd systemctl
check_cmd getent

[ -b "$SOURCE_DEVICE" ] || fail "Quellgerät nicht gefunden: $SOURCE_DEVICE"

if ! mountpoint -q "$BACKUP_BASE"; then
  fail "USB-Stick nicht gemountet: $BACKUP_BASE"
fi

touch "$BACKUP_BASE/.write_test" || fail "USB-Stick nicht beschreibbar: $BACKUP_BASE"
rm -f "$BACKUP_BASE/.write_test"

mkdir -p "$BACKUP_DIR"

############################
# SPEICHERPLATZ PRÜFEN
############################
SOURCE_SIZE_BYTES="$(blockdev --getsize64 "$SOURCE_DEVICE")"
TARGET_FREE_BYTES="$(df -PB1 "$BACKUP_BASE" | awk 'NR==2 {print $4}')"

log "Quellgröße: $(numfmt --to=iec "$SOURCE_SIZE_BYTES" 2>/dev/null || echo "$SOURCE_SIZE_BYTES Bytes")"
log "Freier Platz: $(numfmt --to=iec "$TARGET_FREE_BYTES" 2>/dev/null || echo "$TARGET_FREE_BYTES Bytes")"

# Gz-Image ist kleiner als Rohdevice, aber wir prüfen konservativ auf 80%
REQUIRED_BYTES=$(( SOURCE_SIZE_BYTES * 80 / 100 ))

if [ "$TARGET_FREE_BYTES" -lt "$REQUIRED_BYTES" ]; then
  fail "Zu wenig freier Speicher am Ziel. Benötigt grob >= 80% der SD-Größe."
fi

############################
# [1] MariaDB Dump
############################
log "[1] MariaDB Dump startet"
mysqldump --single-transaction --routines --events --triggers --all-databases \
  | gzip -1 > "$MYSQL_DUMP"
log " -> DB Dump fertig: $MYSQL_DUMP"

############################
# [2] System & Konfiguration sichern
############################
log "[2] System & Konfiguration sichern"

tar -czf "$SERVICE_BACKUP" /etc/systemd/system
log " -> systemd gesichert"

tar -czf "$LOGROTATE_BACKUP" /etc/logrotate.d
log " -> logrotate gesichert"

# root crontab
crontab -l > "$CRON_BACKUP_ROOT" 2>/dev/null || true
log " -> root crontab gesichert"

# user crontab von peter, falls vorhanden
if getent passwd "$DB_USER_TO_EXPORT_CRON" >/dev/null 2>&1; then
  crontab -u "$DB_USER_TO_EXPORT_CRON" -l > "$CRON_BACKUP_USER" 2>/dev/null || true
  log " -> user crontab gesichert: $DB_USER_TO_EXPORT_CRON"
fi

CUSTOM_PATHS=()
[ -d /home/peter/scripts ] && CUSTOM_PATHS+=("/home/peter/scripts")
[ -d /opt ] && CUSTOM_PATHS+=("/opt")
[ -d /usr/local/bin ] && CUSTOM_PATHS+=("/usr/local/bin")

if [ "${#CUSTOM_PATHS[@]}" -gt 0 ]; then
  tar -czf "$CUSTOM_SCRIPTS_BACKUP" "${CUSTOM_PATHS[@]}"
  log " -> Custom Scripts gesichert"
else
  log " -> Keine Custom-Script-Pfade gefunden"
fi

dpkg --get-selections > "$PKG_LIST"
apt-mark showmanual > "$PKG_MANUAL"
log " -> Paketlisten gesichert"

SYSFILES=(
  /etc/fstab
  /etc/hostname
  /etc/hosts
  /etc/passwd
  /etc/group
  /etc/shadow
  /etc/sudoers
)

EXISTING=()
for f in "${SYSFILES[@]}"; do
  [ -f "$f" ] && EXISTING+=("$f")
done

if [ "${#EXISTING[@]}" -gt 0 ]; then
  tar -czf "$SYSFILES_BACKUP" "${EXISTING[@]}"
  log " -> Sysfiles gesichert"
fi

log " -> System & Konfig gesichert"

############################
# [3] Zero-Fill
############################
log "[3] Zero-Fill"

# Optional aktivieren:
# dd if=/dev/zero of=/zero.fill bs=1M status=progress || true
# sync
# rm -f /zero.fill
# sync

log " -> Zero-Fill fertig"

############################
# [4] Services stoppen
############################
log "[4] Stoppe Services"

for s in "${SERVICES[@]}"; do
  if systemctl list-unit-files | grep -q "^${s}\.service"; then
    if systemctl is-active --quiet "$s"; then
      log " -> stop $s"
      systemctl stop "$s"
      STOPPED_SERVICES+=("$s")
    else
      log " -> Service bereits inaktiv: $s"
    fi
  else
    log " -> Service nicht gefunden, übersprungen: $s"
  fi
done

sync
sleep 2

############################
# [5] SD-Backup
############################
log "[5] SD-Backup läuft – NICHT abbrechen"
dd if="$SOURCE_DEVICE" bs=4M status=progress | gzip -1 > "$IMAGE"
sync
log " -> SD-Backup fertig: $IMAGE"

############################
# [6] Services starten
############################
log "[6] Starte Services"

if [ "${#STOPPED_SERVICES[@]}" -gt 0 ]; then
  for s in "${STOPPED_SERVICES[@]}"; do
    log " -> start $s"
    systemctl start "$s"
  done
  STOPPED_SERVICES=()
else
  log " -> Keine Services mussten neu gestartet werden"
fi

############################
# [7] Prüfen
############################
log "[7] Prüfe Backup-Dateien"

[ -s "$MYSQL_DUMP" ] || fail "MySQL-Dump ist leer"
[ -s "$IMAGE" ] || fail "Image-Datei ist leer"

IMAGE_SIZE="$(stat -c %s "$IMAGE")"
MYSQL_SIZE="$(stat -c %s "$MYSQL_DUMP")"

log " -> Image-Größe: $(numfmt --to=iec "$IMAGE_SIZE" 2>/dev/null || echo "$IMAGE_SIZE Bytes")"
log " -> MySQL-Größe: $(numfmt --to=iec "$MYSQL_SIZE" 2>/dev/null || echo "$MYSQL_SIZE Bytes")"

############################
# [8] Status schreiben
############################
echo "OK $(date '+%F %T')" > "$STATUS_OK"

############################
# ENDE
############################
log "===================================="
log "Backup FERTIG"
log "===================================="
ls -lh "$IMAGE" | tee -a "$LOGFILE"
