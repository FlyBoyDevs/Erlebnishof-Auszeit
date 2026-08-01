# Redaktionsbereich: Betrieb und Paketgrenzen

Der Redaktionsbereich verwaltet bewusst nur Neuigkeiten, öffentliche Termine, Öffnungs-Ausnahmen, Saison-Themen und je ein optionales Beitragsbild. Er speichert redaktionelle Absichten (`draft`, `approved`, `archived`, `trashed`); Geplant, Veröffentlicht und Abgelaufen werden in `Europe/Berlin` abgeleitet.

## Einmalige Einrichtung

1. `sample.config.php` an einen Ort **außerhalb** des öffentlichen Dokumentstamms kopieren.
2. Benutzername und bestehenden Passwort-Hash manuell in diese private Datei übernehmen. `admin/config.php` ist nur der unveränderte Altbestand und wird vom neuen Code weder geladen noch benötigt.
3. Ein zufälliges `throttle_secret` mit mindestens 32 Byte erzeugen.
4. Für Produktion und Staging vollständig getrennte private Verzeichnisse anlegen. Öffentliche Medienverzeichnisse ebenfalls trennen. Die private Konfiguration auf `0600` und jedes private Verzeichnis auf `0700` setzen (z. B. `chmod 600 /privat/config.php` und `chmod 700 /privat/hofladen/{data,uploads,ledger,backups,trash,throttle,cache}`). Im IONOS-Panel/SSH prüfen, dass genau der PHP-Benutzer Eigentümer ist; wenn FTP- und PHP-Benutzer nicht kompatibel sind, den Betrieb vor Freigabe mit IONOS klären statt Rechte zu erweitern.
5. Den absoluten privaten Konfigurationspfad setzen. Bevorzugt wird `HOFLADEN_CONFIG`. Falls der konkrete IONOS-Tarif benutzerdefinierte Umgebungsvariablen nicht zuverlässig an Web-PHP und CLI weitergibt, alternativ `admin/.hofladen-config-path` als **nicht ausführbare Ein-Zeilen-Datei** mit genau diesem absoluten Pfad anlegen und auf `0600` setzen. Der Locator enthält keine PHP-Anweisung und die Anwendung prüft Pfad, Symlinks und Rechte vor dem Laden der echten Konfiguration. Locator und echte Konfiguration sind umgebungsspezifisch und dürfen nicht in Git oder ein Release-Paket geraten. Welche PHP-Einstellungen IONOS pro Tarif übernimmt, ist vorab in der [aktuellen IONOS-Anleitung](https://www.ionos.de/hilfe/hosting/php-fuer-web-projekt-verwenden/anpassen-der-php-einstellungen-mittels-phpini-datei/) und anschließend auf dem isolierten Staging praktisch zu prüfen.
6. `php admin/preflight.php` mit demselben Locator ausführen. Die Ausgabe enthält absichtlich keine vollständigen Pfade, Hashes oder Geheimnisse. Die Vorprüfung verlangt unter anderem ausgeschaltete PHP-Fehleranzeige, die vollständige Bild-/Sitzungspipeline und einen web-lesbaren, aber nicht gruppen-/weltbeschreibbaren Medienordner (typisch `0755`).
7. `/content/current.json`, Rewrite, Header, private Pfadsperren, Upload-Limits und Rechte auf Staging prüfen. Erst dann `public_route_verified` nur für diese Umgebung auf `true` setzen und die Vorprüfung wiederholen.

Die Anwendung verweigert unsichere private Pfade, eine Konfiguration im Dokumentstamm, gemischte Staging-/Produktionsverzeichnisse, fehlende Bilddecoder und eine nicht bestätigte öffentliche Route. HTTPS ist standardmäßig Pflicht.

## Release-Paket

Diese Laufzeitdateien gehören in das IONOS-Paket:

- `admin/index.php`, `admin/img.php`, `admin/admin.css`, `admin/admin.js`, `admin/robots.txt`, `admin/.htaccess`;
- `admin/lib/*.php`;
- `admin/preflight.php` und `admin/maintenance.php` (CLI-only und zusätzlich per Apache gesperrt);
- `content/current.php` und `content/.htaccess`;
- die übrigen statischen Website-Dateien des freigegebenen Releases.

Entwicklungsdateien, die ausdrücklich **nicht** in das Produktionspaket gehören:

- `admin/sample.config.php`;
- `admin/.hofladen-config-path`;
- `admin/schemas/`, `admin/fixtures/`, `admin/tests/`;
- `admin/README.md`, `admin/SECURITY.md`;
- Docker-, Test- und lokale Werkzeugdateien.

Die öffentliche, bereinigte Docker-Fixture `admin/fixtures/public-v1.json` darf lokal auf `/content/current.json` gemappt werden. Sie darf niemals als Produktionsinhalt ausgeliefert werden.

## Mutable/private Daten: niemals spiegeln oder löschen

Diese Pfade kommen ausschließlich aus der privaten Umgebungskonfiguration und sind kein Release-Inhalt:

- `private_data_dir`: autoritatives `editorial-v1.json`;
- `ledger_dir`: dauerhafter Generation-/Sequenzstand und gemeinsame Sperre;
- `private_upload_dir`: private Quellbilder;
- `cache_dir`: abgeleitete öffentliche Antwort und Dirty-Marker;
- `backup_dir`: gültige Daten-/Disaster-Sicherungen;
- `trash_dir`: wiederherstellbare Bilddateien;
- `throttle_dir`: kurzlebige HMAC-pseudonymisierte Anmeldeversuche;
- `public_media_dir`: aus Pixeln neu kodierte, inhaltsgehashte öffentliche Varianten.

Zusätzlich ist `admin/.hofladen-config-path` eine umgebungsspezifische, durch Apache gesperrte Locator-Datei. Ein Code-Deploy muss sie erhalten, darf sie aber nie aus dem Repository erzeugen oder zwischen Staging und Produktion kopieren.

Deploy und Rollback müssen eine explizite Allowlist für Code verwenden. Kein `--delete`, rekursives Spiegeln oder Rollback darf einen der obigen Pfade ersetzen. Insbesondere darf `news-ledger-v1.json` bei normalem Restore, Deploy oder Rollback nie aus einer Inhalts-Sicherung zurückkopiert werden.

Automatisches Löschen alter Sicherungen/Papierkorb-Inhalte bleibt aus, solange Aufbewahrung, Datenschutz und Offsite-Recovery nicht freigegeben sind. Die konfigurierten Zahlen dokumentieren nur die beabsichtigten späteren Grenzen.

## Veröffentlichung und Wiederherstellung

Jede Änderung verlangt den im Formular gelesenen `writeRevision`. Ein zweiter veralteter Tab erhält einen Konflikt statt still zu überschreiben. Nach einem gültigen Schreibvorgang wird der öffentliche Snapshot unter derselben Sperre abgeglichen. Schlägt nur dieser abgeleitete Schritt fehl, bleibt die redaktionelle Änderung erhalten, ein Dirty-Marker wird gesetzt und der nächste Admin-/Gastaufruf versucht den Abgleich erneut.

Sicherungen lassen sich im angemeldeten Bereich wiederherstellen. Der unabhängige Neuigkeiten-Zähler bleibt dabei erhalten. Nur wenn nach einem echten Disaster der neueste Ledger-Stand nicht beweisbar ist, darf ausgeführt werden:

```sh
php admin/maintenance.php rotate-ledger I_UNDERSTAND_NEW_GENERATION
```

Das erzeugt absichtlich eine neue Generation; alle aktuell sichtbaren Einträge gelten danach auf Geräten als ungelesen. Für normalen Cacheverlust oder Rollback ist dieser Befehl falsch.

## Prüfungen

```sh
php admin/preflight.php
php admin/tests/run.php
find admin content -name '*.php' -print0 | xargs -0 -n1 php -l
```

Vor Produktion außerdem die manuellen HTTP-/Browserprüfungen in `SECURITY.md` auf Staging durchführen. Fehlerantworten dürfen weder PHP-Traces noch Pfade enthalten. Die Eigentümer-Anleitung muss das Kopieren eigener Texte vor einer Konflikt-Neuladung und die demonstrierte Sicherungswiederherstellung enthalten.

Der aktuelle v1-Code erfindet noch keine fiktive v2-Migration. Vor der ersten privaten Schemaerhöhung muss die konkrete additive v1→v2-Transformation zusammen mit dem vorherigen Rollback-Reader implementiert und gegen Vorwärts-/Rückwärtsbetrieb getestet werden. Bis dahin bleibt eine Schemaerhöhung ein Release-Blocker.
