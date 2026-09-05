# Zugriffsschutz für das SYR-Dashboard

Diese Dateien gehören zum lokalen Browser-Dashboard:

`/var/www/html/SyrApiDashboard.php`

Der Konfigurationsordner liegt absichtlich außerhalb des öffentlich erreichbaren
Webroots `/var/www/html`.

## Enthaltene Datei

`apache-auth.conf` schützt ausschließlich `SyrApiDashboard.php` mit der
HTTP-Basic-Authentifizierung von Apache. Die Kennwörter stehen nicht in dieser
Konfigurationsdatei, sondern in `/etc/apache2/.coh-dashboard-users`.

## Datei vom PC auf den Raspberry Pi übertragen

Die Datei `apache-auth.conf` wird nicht durch einen Apache-Befehl erzeugt. Sie
liegt im lokalen Git-Projekt und muss zunächst auf den Raspberry Pi übertragen
werden.

In PowerShell auf dem PC ausführen:

```powershell
scp -o KexAlgorithms=curve25519-sha256 `
  -i C:\Users\pbd\.ssh\id_ed25519_codex_raspi `
  C:\wampneu\www\co5\co5Bundles\COH-Sensorcollector\var\www\coh-config\syr-dashboard\apache-auth.conf `
  peter@192.168.178.49:/home/peter/apache-auth.conf
```

Danach per SSH auf dem Raspberry Pi anmelden und die Datei außerhalb des
öffentlichen Webroots ablegen:

```bash
sudo mkdir -p /var/www/coh-config/syr-dashboard
sudo cp /home/peter/apache-auth.conf /var/www/coh-config/syr-dashboard/apache-auth.conf
sudo chmod 644 /var/www/coh-config/syr-dashboard/apache-auth.conf
rm /home/peter/apache-auth.conf
```

Erst jetzt ist die in den folgenden Befehlen verwendete Quelldatei
`/var/www/coh-config/syr-dashboard/apache-auth.conf` auf dem Raspberry Pi
vorhanden.

## Einmalige Einrichtung auf dem Raspberry Pi

Das für `htpasswd` benötigte Paket installieren, falls es noch fehlt:

```bash
sudo apt install apache2-utils
```

Die Passwortdatei mit dem gewünschten Benutzernamen anlegen. `peter` kann durch
einen anderen Namen ersetzt werden. Das Kennwort wird anschließend interaktiv
abgefragt:

```bash
sudo htpasswd -c /etc/apache2/.coh-dashboard-users peter
```

Für weitere Benutzer darf `-c` nicht mehr verwendet werden, weil es die
vorhandene Datei neu anlegen würde:

```bash
sudo htpasswd /etc/apache2/.coh-dashboard-users weiterer_benutzer
```

Die mitgelieferte Apache-Konfiguration installieren und aktivieren:

```bash
sudo cp /var/www/coh-config/syr-dashboard/apache-auth.conf /etc/apache2/conf-available/coh-dashboard-auth.conf
sudo a2enmod auth_basic
sudo a2enconf coh-dashboard-auth
sudo apache2ctl configtest
sudo systemctl reload apache2
```

`apache2ctl configtest` muss vor dem Reload `Syntax OK` melden.

## Prüfen

Danach im Browser öffnen:

```text
https://<Raspberry-Adresse>/SyrApiDashboard.php
```

Apache muss vor der Anzeige Benutzername und Kennwort anfordern. Andere Dateien
unter `/var/www/html` werden durch diese Regel nicht geschützt.

## Deaktivieren

```bash
sudo a2disconf coh-dashboard-auth
sudo apache2ctl configtest
sudo systemctl reload apache2
```

Die Passwortdatei wird dabei nicht gelöscht und kann später erneut verwendet
werden.
