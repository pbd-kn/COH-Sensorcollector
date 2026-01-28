#!/bin/bash
# 
# Dieses File /home/peter/scripts/coh/sensorcollect/Sensor/RapberrryExecScripts/logfile_protokoll.sh wird von der thingsdatei Protokoll logdatei
# /etc/openhab/things/exec.things regelmässig aufgerufen und liefert ob ein Fehler aufgetreten ist.
# es überwacht die log-auschreibe des Heizungsstabs
# chmod +x /home/peter/scripts/logfile_protokoll.sh
#
#!/bin/bash
# Dieses Skript liest die letzten 5 Einträge aus der Logdatei, die "openhab" enthalten
tail -n 100 /home/peter/coh/logs/heizstabserver.log | grep -i -e "Info" -e "error" || echo "Keine Treffer"


