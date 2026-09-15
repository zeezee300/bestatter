# Interne Entwicklung und Qualitätssicherung

Dieses Dokument bündelt die internen Hinweise zur Entwicklungsinstallation, Datenbankentwicklung und Abnahme. Es beschreibt keinen öffentlichen Upgradepfad.

## Entwicklungsinstallation prüfen

1. PHP-Dateien im Zielcontainer mit `php -l` prüfen.
2. `php occ upgrade` ausführen und den Container neu starten.
3. `php occ status` kontrollieren: Wartungsmodus aus, kein ausstehendes Datenbankupgrade.
4. JavaScript-Regressionstests und produktiven Frontend-Build ausführen.
5. Zwei bis drei Musterfälle mit Aufgaben, Terminen, Dokumenten, Auftrag und Rechnung prüfen.
6. Rollenprüfung mit Administrator, Bestatter-Mitglied und außenstehendem Benutzer durchführen.

Die detaillierten fachlichen Prüffälle ergeben sich aus den aktuellen Fachkonzepten und der OP-Liste. Alte versionsbezogene Abnahmeprotokolle werden nicht fortgeführt.

## Datenbankentwicklung

- `Version1800Date20260906000000` ist die konsolidierte Ausgangsbasis des deutschen Entwicklungsstands.
- `Version3000Date20260907000000` ergänzt als erster neuer Schritt die Länderprofile und Niederlassungsregeln.
- Änderungen nach dieser Basis werden wieder als vorwärtsgerichtete Migrationen ergänzt. So bleibt die Testdatenbank aktualisierbar und die Länderentwicklung nachvollziehbar.
- Vor einer ersten externen Veröffentlichung werden Baseline und öffentlicher Upgradepfad erneut festgelegt. Ab diesem Zeitpunkt dürfen veröffentlichte Migrationen nicht mehr verändert oder entfernt werden.
- Migrationen müssen idempotent mit bereits vorhandenen Tabellen und Spalten umgehen, soweit dies für die Entwicklungsinstallationen erforderlich ist.

## Sicherung

Vor Schemaänderungen sind Datenbank, Nextcloud-Datenverzeichnis, Konfiguration und App-Verzeichnis gemeinsam zu sichern. Das App-eigene JSON-Backup ersetzt kein vollständiges Nextcloud-Backup.
