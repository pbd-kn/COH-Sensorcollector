#!/bin/bash
# 
# Dieses File /home/peter/scripts/logfile_protokoll.sh wird von der thingsdatei Protokoll logdatei
# /etc/openhab/things/exec.things regelmässig aufgerufen und liefert ob ein Fehler aufgetreten ist.
# es überwacht die log-auschreibe des Heizungsstabs
# chmod +x /home/peter/scripts/logfile_protokoll.sh
#
#!/bin/bash
# Dieses Skript liest die letzten 30 Einträge aus der Logdatei, die "openhab" enthalten
grep iteration /var/log/heizung/heizstab.log | tail -n 5
