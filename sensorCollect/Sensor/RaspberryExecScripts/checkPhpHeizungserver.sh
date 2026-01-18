prozess_name="json-heizung.php"

if pgrep -f "$prozess_name" > /dev/null; then
    echo "1"
else
    echo "0"
fi
