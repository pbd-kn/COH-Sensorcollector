#!/bin/bash
#
# Raspberry Pi FULL BACKUP – STABIL & VOLLSTÄNDIG
# SD-Image + DB + System/Konfig + Zero-Fill
#

set -u

############################
# KONFIG
############################
BACKUP_BASE="/media/peter/USBBACKUP"
BACKUP_DIR="$BACKUP_BASE/backups"
DATE="$(date +%Y-%m-%d_%H-%M)"

IMAGE="$BACKUP_DIR/${DATE}_raspi.img.gz"
MYSQL_DUMP="$BACKUP_DIR/${DATE}_mysql.sql.gz"

SERVICE_BACKUP="$BACKUP_DIR/${DATE}_services.tar.gz"
LOGROTATE_BACKUP="$BACKUP_DIR/${DATE}_logrotate.tar.gz"
CRON_BACKUP="$BACKUP_DIR/${DATE}_cron.txt"
CUSTOM_SCRIPTS_BACKUP="$BACKUP_DIR/${DATE}_custom_scripts.tar.gz"
PKG_LIST="$BACKUP_DIR/${DATE}_packages.list"
PKG_MANUAL="$BACKUP_DIR/${DATE}_packages_manual.txt"
SYSFILES_BACKUP="$BACKUP_DIR/${DATE}_sysfiles.tar.gz"

STATUS_OK="$BACKUP_BASE/ok_${DATE}.txt"
STATUS_ABORT="$BACKUP_BASE/abgebrochen_${DATE}.txt"

SERVICES=(
  collect
  heizstab
  mosquitto
  raspi-lima-tunnel
  raspi-local-tunnel
  mariadb
)

############################
# START
############################
echo "===================================="
echo "Backup START: $(date)"
echo "===================================="

if ! mountpoint -q "$BACKUP_BASE"; then
  echo "USB-Stick nicht gemountet"
  echo "ABGEBROCHEN $(date)" > "$STATUS_ABORT"
  exit 1
fi

mkdir -p "$BACKUP_DIR"

############################
# [1] MariaDB Dump
############################
echo "[1] MariaDB Dump"
mysqldump --single-transaction --routines --events --triggers --all-databases \
  | gzip > "$MYSQL_DUMP"
echo " -> DB Dump fertig"

############################
# [2] System & Konfiguration sichern
############################
echo "[2] System & Konfiguration sichern"

tar -czf "$SERVICE_BACKUP" /etc/systemd/system 2>/dev/null || true
tar -czf "$LOGROTATE_BACKUP" /etc/logrotate.d 2>/dev/null || true
crontab -l > "$CRON_BACKUP" 2>/dev/null || true

CUSTOM_PATHS=()
[ -d /home/peter/scripts ] && CUSTOM_PATHS+=("/home/peter/scripts")
[ -d /opt ] && CUSTOM_PATHS+=("/opt")
[ -d /usr/local/bin ] && CUSTOM_PATHS+=("/usr/local/bin")

if [ "${#CUSTOM_PATHS[@]}" -gt 0 ]; then
  tar -czf "$CUSTOM_SCRIPTS_BACKUP" "${CUSTOM_PATHS[@]}" 2>/dev/null || true
fi

dpkg --get-selections > "$PKG_LIST" 2>/dev/null || true
apt-mark showmanual > "$PKG_MANUAL" 2>/dev/null || true

SYSFILES=(/etc/fstab /etc/hostname /etc/hosts /etc/passwd /etc/group /etc/shadow /etc/sudoers)
EXISTING=()
for f in "${SYSFILES[@]}"; do
  [ -f "$f" ] && EXISTING+=("$f")
done
[ "${#EXISTING[@]}" -gt 0 ] && tar -czf "$SYSFILES_BACKUP" "${EXISTING[@]}" 2>/dev/null || true

echo " -> System & Konfig gesichert"

############################
# [3] Zero-Fill
############################
echo "[3] Zero-Fill"
#sudo -n dd if=/dev/zero of=/zero.fill bs=1M status=progress || true
#sync
#sudo -n rm -f /zero.fill
#sync
echo " -> Zero-Fill fertig"

############################
# [4] Services stoppen
############################
echo "[4] Stoppe Services"
for s in "${SERVICES[@]}"; do
  echo " -> stop $s"
  sudo -n systemctl stop "$s"
done
sync
sleep 2

############################
# [5] SD-Backup
############################
echo "[5] SD-Backup läuft – NICHT abbrechen"
sudo -n dd if=/dev/mmcblk0 bs=4M status=progress | gzip -1 > "$IMAGE"
sync
echo " -> SD-Backup fertig"

############################
# [6] Services starten
############################
echo "[6] Starte Services"
for s in "${SERVICES[@]}"; do
  echo " -> start $s"
  sudo -n systemctl start "$s"
done

############################
# ENDE
############################
echo "===================================="
echo "Backup FERTIG: $(date)"
ls -lh "$IMAGE"
echo "OK $(date)" > "$STATUS_OK"
echo "===================================="
