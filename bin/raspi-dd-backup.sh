#!/bin/bash
set -Eeuo pipefail

PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
umask 027

BACKUP_BASE="/media/peter/USBBACKUP"
BACKUP_ROOT="$BACKUP_BASE/backups"
DATE="$(date +%Y-%m-%d_%H-%M-%S)"
BACKUP_DIR="$BACKUP_ROOT/$DATE"
MAX_BACKUPS=15

SOURCE_DEVICE="/dev/mmcblk0"
DB_USER="peter"
DB_PASS="sql666sql"

IMAGE="$BACKUP_DIR/${DATE}_raspi.img.gz"
MYSQL_DUMP="$BACKUP_DIR/${DATE}_mysql.sql.gz"
LOGFILE="$BACKUP_BASE/backup.log"
LOCKFILE="/var/lock/raspi-dd-backup.lock"
STATUS_OK="$BACKUP_BASE/ok_${DATE}.txt"
STATUS_ABORT="$BACKUP_BASE/abgebrochen_${DATE}.txt"

SERVICES=(collect.service heizstab.service mosquitto.service mariadb.service)

SERVICES_STOPPED=0
SERVICES_RESTORED=0

log() {
    echo "[$(date '+%F %T')] $*" | tee -a "$LOGFILE"
}

start_services() {
    log "[SERVICE] Starte wichtige Services"

    for s in "${SERVICES[@]}"; do
        if systemctl start "$s" >/dev/null 2>&1; then
            log " -> Service gestartet: $s"
        else
            log " -> WARNUNG: Service konnte nicht gestartet werden: $s"
        fi
    done
}

cleanup() {
    rc=$?
    trap - EXIT ERR INT TERM

    if [ "$SERVICES_STOPPED" -eq 1 ] && [ "$SERVICES_RESTORED" -eq 0 ]; then
        start_services || true
    fi

    if [ -d "$BACKUP_DIR" ]; then
        chown -R peter:peter "$BACKUP_DIR" 2>/dev/null || true
        find "$BACKUP_DIR" -type d -exec chmod 0750 {} \; 2>/dev/null || true
        find "$BACKUP_DIR" -type f -exec chmod 0640 {} \; 2>/dev/null || true
    fi

    if [ -d "$BACKUP_ROOT" ]; then
        chown root:peter "$BACKUP_ROOT" 2>/dev/null || true
        chmod 2770 "$BACKUP_ROOT" 2>/dev/null || true
    fi

    [ -f "$LOGFILE" ] && chown peter:peter "$LOGFILE" 2>/dev/null || true
    [ -f "$LOGFILE" ] && chmod 0644 "$LOGFILE" 2>/dev/null || true
    [ -f "$STATUS_OK" ] && chown peter:peter "$STATUS_OK" 2>/dev/null || true
    [ -f "$STATUS_OK" ] && chmod 0644 "$STATUS_OK" 2>/dev/null || true
    [ -f "$STATUS_ABORT" ] && chown peter:peter "$STATUS_ABORT" 2>/dev/null || true
    [ -f "$STATUS_ABORT" ] && chmod 0644 "$STATUS_ABORT" 2>/dev/null || true

    sync 2>/dev/null || true
    flock -u 9 2>/dev/null || true
    rm -f "$LOCKFILE" 2>/dev/null || true

    if [ "$rc" -eq 0 ]; then
        log "Backup erfolgreich beendet"
    else
        echo "ABGEBROCHEN $(date '+%F %T') : Exit-Code $rc" > "$STATUS_ABORT" 2>/dev/null || true
        chown peter:peter "$STATUS_ABORT" 2>/dev/null || true
        chmod 0644 "$STATUS_ABORT" 2>/dev/null || true
        log "=== Backup mit Fehler beendet (Exit-Code $rc) ==="
    fi

    exit "$rc"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

if [ "$(id -u)" -ne 0 ]; then
    echo "FEHLER: Script muss mit sudo gestartet werden."
    exit 1
fi

for cmd in mountpoint tar gzip dd sync df awk tee flock blockdev mysqldump dpkg apt-mark crontab systemctl find sort stat numfmt; do
    command -v "$cmd" >/dev/null 2>&1 || {
        echo "FEHLER: Befehl fehlt: $cmd"
        exit 1
    }
done

[ -b "$SOURCE_DEVICE" ] || {
    echo "FEHLER: Quellgerät fehlt: $SOURCE_DEVICE"
    exit 1
}

mountpoint -q "$BACKUP_BASE" || {
    echo "FEHLER: USB-Stick nicht gemountet: $BACKUP_BASE"
    exit 1
}

mkdir -p /var/lock
exec 9>"$LOCKFILE"

if ! flock -n 9; then
    echo "FEHLER: Es läuft bereits ein Backup."
    exit 1
fi

touch "$BACKUP_BASE/.write_test"
rm -f "$BACKUP_BASE/.write_test"

touch "$LOGFILE"
chown peter:peter "$LOGFILE"
chmod 0644 "$LOGFILE"

mkdir -p "$BACKUP_ROOT"
chown root:peter "$BACKUP_ROOT"
chmod 2770 "$BACKUP_ROOT"

log "=== Backup START ==="

log "Backup-Rotation (maximal $MAX_BACKUPS Backups)"

mapfile -t BACKUPS < <(
    find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' |
    awk '/^[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}$/' |
    sort
)

DELETE_COUNT=$(( ${#BACKUPS[@]} - MAX_BACKUPS + 1 ))

if [ "$DELETE_COUNT" -gt 0 ]; then
    for ((i=0; i<DELETE_COUNT; i++)); do
        OLD="$BACKUP_ROOT/${BACKUPS[$i]}"
        log " -> Entferne altes Backup: $OLD"
        rm -rf -- "$OLD"
    done
else
    log " -> Keine alten Backups zu entfernen (${#BACKUPS[@]}/$MAX_BACKUPS)"
fi

mkdir -p "$BACKUP_DIR"
chown root:peter "$BACKUP_DIR"
chmod 2750 "$BACKUP_DIR"

log "Backup-Verzeichnis: $BACKUP_DIR"

SOURCE_SIZE="$(blockdev --getsize64 "$SOURCE_DEVICE")"
FREE_SIZE="$(df -PB1 "$BACKUP_BASE" | awk 'NR==2 {print $4}')"
REQUIRED=$(( SOURCE_SIZE * 80 / 100 ))

log "Quellgröße: $(numfmt --to=iec "$SOURCE_SIZE")"
log "Freier Platz: $(numfmt --to=iec "$FREE_SIZE")"

[ "$FREE_SIZE" -ge "$REQUIRED" ] || {
    log "=== FEHLER: Zu wenig freier Speicher."
    exit 1
}

log "[1] MariaDB Dump"

systemctl start mariadb.service

MYSQL_PWD="$DB_PASS" mysqldump \
    -u "$DB_USER" \
    --single-transaction \
    --routines \
    --events \
    --triggers \
    --all-databases |
    gzip -1 > "$MYSQL_DUMP"

log " -> DB Dump fertig"

log "[2] System und Konfiguration sichern"

tar -czf "$BACKUP_DIR/${DATE}_services.tar.gz" /etc/systemd/system
tar -czf "$BACKUP_DIR/${DATE}_logrotate.tar.gz" /etc/logrotate.d
crontab -l > "$BACKUP_DIR/${DATE}_cron_root.txt" 2>/dev/null || true
crontab -u peter -l > "$BACKUP_DIR/${DATE}_cron_peter.txt" 2>/dev/null || true
dpkg --get-selections > "$BACKUP_DIR/${DATE}_packages.list"
apt-mark showmanual > "$BACKUP_DIR/${DATE}_packages_manual.txt"

CUSTOM_PATHS=()

[ -d /home/peter/scripts ] && CUSTOM_PATHS+=("/home/peter/scripts")
[ -d /opt ] && CUSTOM_PATHS+=("/opt")
[ -d /usr/local/bin ] && CUSTOM_PATHS+=("/usr/local/bin")

if [ "${#CUSTOM_PATHS[@]}" -gt 0 ]; then
    tar -czf "$BACKUP_DIR/${DATE}_custom_scripts.tar.gz" "${CUSTOM_PATHS[@]}"
fi

SYSFILES=()

for f in /etc/fstab /etc/hostname /etc/hosts /etc/passwd /etc/group /etc/shadow /etc/sudoers; do
    [ -f "$f" ] && SYSFILES+=("$f")
done

if [ "${#SYSFILES[@]}" -gt 0 ]; then
    tar -czf "$BACKUP_DIR/${DATE}_sysfiles.tar.gz" "${SYSFILES[@]}"
fi

log " -> System und Konfiguration gesichert"

log "[3] Stoppe Services"

for s in "${SERVICES[@]}"; do
    if systemctl stop "$s" >/dev/null 2>&1; then
        log " -> stop $s"
    else
        log " -> WARNUNG: $s konnte nicht gestoppt werden"
    fi
done

SERVICES_STOPPED=1

sync
sleep 2

log "[4] SD-Backup läuft - NICHT abbrechen"

dd if="$SOURCE_DEVICE" bs=4M status=progress | gzip -1 > "$IMAGE"

sync

log " -> SD-Backup fertig: $IMAGE"

log "[5] Services starten"

start_services
SERVICES_RESTORED=1

log "[6] Prüfe Backup-Dateien"

[ -s "$MYSQL_DUMP" ] || {
    log "===FEHLER: MySQL-Dump ist leer"
    exit 1
}

[ -s "$IMAGE" ] || {
    log "=== FEHLER: Image-Datei ist leer"
    exit 1
}

IMAGE_SIZE="$(stat -c %s "$IMAGE")"
MYSQL_SIZE="$(stat -c %s "$MYSQL_DUMP")"

log " -> Image-Größe: $(numfmt --to=iec "$IMAGE_SIZE")"
log " -> MySQL-Größe: $(numfmt --to=iec "$MYSQL_SIZE")"

echo "OK $(date '+%F %T')" > "$STATUS_OK"
chown peter:peter "$STATUS_OK"
chmod 0644 "$STATUS_OK"

ls -lh "$IMAGE" 2>&1 | tee -a "$LOGFILE"

log "=== Backup FERTIG ==="

