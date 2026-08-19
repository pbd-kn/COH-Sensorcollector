#!/bin/bash
# 
# Dieses File /home/peter/scripts/coh/sensorcollect/Sensor/RapberrryExecScripts/logfile_protokoll.sh 
# es liefert die log-auschreibe des Heizungsstabs
# chmod +x /home/peter/scripts/logfile_protokoll.sh
#
#!/bin/bash
# Dieses Skript liest die letzten 20 Einträge aus der Logdatei, die "openhab" enthalten
OUT=$(grep -i -e "Info" -e "error" /home/peter/coh/logs/heizstabserver.log | tail -n 20)

if [ -z "$OUT" ]; then
    echo "Info: Datei /home/peter/coh/logs/heizstabserver.log ist leer (wg. rotate)"
else
    echo "$OUT"
fi



