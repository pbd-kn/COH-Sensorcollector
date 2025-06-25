prozess_name="json-heizung.php"

# Überprüfen, ob der Prozess läuft
if ps aux | grep -v grep | grep -q "$prozess_name"; then
    echo "1"
else
    echo "0"
fi
