# Bedrohungsmodell und Sicherheitsprüfungen

## Schutzgüter und Grenzen

Zu schützen sind Passwort-Hash und Drosselungsgeheimnis, Sitzung und CSRF-Token, Entwürfe/Embargos, private Quellbilder und Dateipfade, der autoritative JSON-Stand, der nicht rücksetzbare Generation-/Sequenz-Ledger sowie Verfügbarkeit und korrekte Öffnungsinformationen. Öffentlich sein dürfen ausschließlich neu kodierte Bildvarianten und die strikt auf den aktuellen Zeitpunkt reduzierte JSON-Antwort.

Vertrauensgrenzen sind Browser ↔ HTTPS/Apache/PHP, Release-Code ↔ externe Konfiguration, PHP ↔ private/öffentliche Dateisysteme und privater Inhalt ↔ öffentlicher Resolver. Der gemeinsame Betreiberzugang ist eine bewusst akzeptierte organisatorische Grenze: Es gibt keine belastbare persönliche Zuordnung.

## Wesentliche Angriffe und Kontrollen

| Risiko | Kontrolle | Verbleibendes Risiko |
|---|---|---|
| Passwort-Raten | Generische Antwort, zeitlich begrenzte Sperre ab fünf Fehlern, HMAC statt roher IP, 24-h-Bereinigung, maximal 500 Schlüssel | Verteilte Angriffe und Host-DoS bleiben möglich |
| Session-Diebstahl/Fixation | HTTPS, Secure/HttpOnly/SameSite=Strict, strikter Modus, ID-Wechsel beim Login, Leerlauf-/Absolutablauf, no-store | Kompromittierter Browser/Host bleibt außerhalb des Schutzes |
| CSRF/unerwünschte Zustandsänderung | Jede Mutation inklusive Logout ist POST plus zufälliges Session-Token; CSP `form-action 'self'` | Ein aktives XSS im gleichen Ursprung könnte handeln; deshalb nur Text und kontextgerechtes Encoding |
| XSS/HTML aus Redaktion | Nur UTF-8-Plain-Text, serverseitiges `htmlspecialchars`, JSON-Encoding, keine Inline-Skripte, enge CSP | Änderungen am Renderer müssen dieselben Regeln behalten |
| Upload-RCE/Trackingdaten | Nur JPEG/PNG/WebP; Uploadstatus, Fileinfo, Decoder, Maße/Pixel/Bytes; öffentliche Dateien werden aus Pixeln als JPEG/WebP neu kodiert; opake Namen | Private Originale können Metadaten enthalten und benötigen denselben Zugriffsschutz wie Entwürfe |
| Pfadmanipulation/Informationsleck | Externe Konfiguration/private Daten außerhalb DocumentRoot, feste Basenames/IDs, Pfadprüfung, Apache-Sperren, generische Fehler ohne Trace/Pfad | Fehlkonfigurierter Webserver wird durch Staging-Gate adressiert, nicht allein durch PHP beweisbar |
| Verlorene/duplizierte Änderungen | Erwartete Revision, gemeinsame `flock`-Sperre, Tempdatei+Flush/fsync+Rename, gültige Sicherung vor Änderung | Speicher-/Dateisystemdefekt verlangt getestetes externes Recovery |
| Falscher Neu-Punkt nach Restore | Separater Ledger, monotoner High-Water, Eintrag-Fingerprint, Reconcile nach jeder Mutation; neue Generation bei unbeweisbarem Disaster | Manuelles unautorisiertes Editieren privater Dateien kann Konsistenz brechen und wird verweigert |
| Stale Öffnungszeiten/Inhalt | Request-Zeitauflösung, ETag und Cachezeit höchstens bis zur nächsten Grenze, 503 statt unsicherem Live-Status | Vollständiger PHP-/Host-Ausfall braucht die konservative Frontend-Anzeige |
| Clickjacking/Indexierung/Back-Cache | `frame-ancestors 'none'`, X-Frame-Options DENY, noindex/noarchive, no-store | Suchmaschinenhinweise sind keine Zugriffskontrolle; Authentifizierung bleibt erforderlich |

Der Zugang bleibt ein Passwort-only-Gemeinschaftskonto ohne 2FA, selektive Sperrung oder personenbezogenes Audit. Bei drittem Bearbeiter, Revokationsbedarf oder Auditpflicht ist ADR 0013 neu zu entscheiden. Das Drosselungs-File enthält HMAC-Pseudonyme und Zeitpunkte bis höchstens 24 Stunden; dies gehört in die finale Datenschutzprüfung.

## Automatisierte Negativtests

`php admin/tests/run.php` prüft mindestens: externe Locator-/Konfigurationsgrenzen vor PHP-Ausführung, HTTPS-/Medienpfadregeln, unbekannte private Felder, CAS-Konflikt, Entwurf-/Embargo-Leak, sichtbare Materialrevision, Archivieren/Wiederveröffentlichen, Cache-/Ledgerverlust, Generation-Rotation, Terminende an lokaler Mitternacht, abgelehnte Neu-Freigabe nach dem Ende, Ablehnung der 51. Ausnahme, unterbrochene Bildstatuswechsel, pseudonymisierte Drosselung sowie gültiges/ungültiges CSRF. PHP-8.1-Syntax wird für jede Laufzeit-PHP-Datei separat geprüft; `admin/config.php` wird dabei ausdrücklich ausgeschlossen.

## Manuelle Staging-Prüfung vor Freigabe

1. Ohne Anmeldung `admin/index.php`, `admin/img.php?id=…`, `admin/preflight.php`, `admin/.hofladen-config-path`, `admin/lib/…`, `admin/schemas/…`, private Konfiguration und alle privaten Datenpfade abrufen: keine Daten; erwartete 404/403/generische Loginseite. Der Locator darf selbst dann nicht ausgeliefert werden, wenn Apache und PHP unter demselben Benutzer laufen.
2. HTTP statt HTTPS prüfen: Produktion verweigert Anmeldung. Cookies im Browser als Secure, HttpOnly, SameSite=Strict kontrollieren; Session-ID vor/nach Login muss wechseln.
3. Mutation ohne, mit falschem und mit Token einer alten Sitzung senden: jeweils Ablehnung. Logout per GET darf nichts tun; POST-Logout macht Zurück-Navigation unlesbar/nicht authentifiziert.
4. Fünf falsche Logins ausführen: zeitlich begrenzte generische Sperre; nach 15 Minuten automatische Freigabe. Gefälschtes `X-Forwarded-For` von einer nicht ausdrücklich vertrauenswürdigen Quell-IP darf keinen anderen Schlüssel wählen.
5. Zwei Tabs auf demselben Bearbeitungsstand öffnen, zuerst A und dann B speichern: B zeigt Konflikt, behält sichtbaren Eingabetext und fordert Kopieren/Neuladen/Abgleichen.
6. `<script>`, Anführungszeichen, Unicode und URL-artigen Text als Entwurf/Freigabe speichern: weder Adminvorschau noch Website führt Markup aus; öffentliche JSON bleibt gültig.
7. Umbenannte Text-/SVG-/GIF-/HEIC-Datei, defektes Rasterbild, >12 MiB, >8.000 px und >40 MP hochladen: jeweils keine Teildateien/Eintragsänderung. JPEG mit Rotation/GPS hochladen: öffentliche Variante korrekt orientiert und ohne Metadaten/Originalnamen.
8. Ein Bild an zwei Einträge hängen: Löschen wird verhindert, solange irgendeine Referenz besteht. Papierkorb und Wiederherstellung auf Staging demonstrieren.
9. Geplante Veröffentlichung/Ablauf, Termin ohne Ende, DST-Datum, Ausnahme am 366-Tage-Horizont, 51. Ausnahme und Themenfenster an Zeitgrenzen mit kontrollierter Uhr testen. Die erste Anfrage nach der Grenze darf keinen alten Stand als aktuell liefern.
10. Cache löschen: Sequenz bleibt. Inhaltssicherung wiederherstellen: Sequenz sinkt nicht. Nur im isolierten Disaster-Test Ledger als unbeweisbar behandeln und Generation rotieren.
11. `/content/current.json` mit `If-None-Match` testen: 304 bei Übereinstimmung; `max-age` reicht nie über `nextTransitionAt`. Entwurfstext, private Pfade und Originaldateinamen dürfen nicht vorkommen. Fehler liefert 503 ohne Trace/Pfad.
12. CSP-, Cache-, Frame-, MIME-, Referrer- und Robots-Header sowohl für 200 als auch Fehlerantworten prüfen. Tastatur, Screenreader, 200–400 % Zoom, schmalen Bildschirm und Fokus auf Fehlerzusammenfassung testen.

Upload-Decoder, EXIF-Ausrichtung, HTTP-Header/Methoden und Apache-Deny-Regeln bleiben bewusst echte Staging-Gates: lokale Unit-Tests allein beweisen die IONOS-Module, `disable_functions`, Rewrite-Vererbung und Dateirechte nicht. Ebenso bleiben Aufbewahrungs-/Offsite-Recovery-Freigabe, eine konkrete Schema-Migration und der Altinhalt-Import vor dem ersten Produktions-Cutover blockierend.

Ein gezieltes Entsperren vor Ablauf erfolgt nur durch einen autorisierten Serverbetreiber, indem bei gestoppter Anmeldung das private Drosselungsfile gesichert und entfernt wird; niemals über eine öffentliche URL. Normalfall ist das automatische 15-Minuten-Fenster.
