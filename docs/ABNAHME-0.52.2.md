# Abnahme Version 0.52.2

Stand: 08.09.2026

## Ergebnis

Version 0.52.2 ist für den weiteren Betrieb in der isolierten Testumgebung freigegeben. Die Installation ist vollständig, Nextcloud befindet sich nicht im Wartungsmodus und die Bestatter-Fachoberfläche bleibt mit vorhandenen Falldaten bedienbar.

Die produktive Betriebsfreigabe bleibt bis zu einem dokumentierten Wiederherstellungstest in einer leeren Zielinstallation ausgesetzt.

## Durchgeführte Prüfungen

- 89 automatisierte Front-Testdateien erfolgreich ausgeführt.
- Bestatter-App als Administrator geöffnet; angezeigte Version: 0.52.2.
- Dashboard, Fallliste, Fallakte, Dokumentenübersicht und Belegeingang lesend geprüft.
- Fall 2026-0003 geöffnet; Stammdaten und Prozessvollständigkeit wurden geladen.
- Fall-Export ausgelöst; kein CSRF- oder Zugriffsfehler angezeigt.
- Systemprüfung erneut ausgeführt: 12 Prüfungen in Ordnung, 6 Hinweise, 0 Fehler.
- Schnellen App-Snapshot unter Version 0.52.2 erstellt.
- Vollständiges Sicherungspaket unter Version 0.52.2 erstellt.
- Beide neuen Sicherungen liegen im aktiven Sicherungsordner `/var/www/html/data/bestatter-backups` und werden mit gültiger Prüfsumme angezeigt.
- Das vollständige Testpaket enthält 58 Dateien und 3.421.846 Byte Nutzdaten.
- Frühere Sicherungen aus dem bisherigen Datenverzeichnis werden weiterhin als „Bisheriger Speicherort“ angezeigt.
- Restore-Schutz geprüft: Eine Wiederherstellung in der mit Produktiv-/Testdaten gefüllten Installation ist gesperrt.

## Offene Hinweise ohne Abnahmeblockade

Die Systemprüfung meldet sechs Konfigurationshinweise:

1. Keine konfigurierte Bestatter-Administrationsgruppe gefunden; Nextcloud-Systemadministratoren bleiben berechtigt.
2. Die Niederlassung „Stammhaus“ ist für Rechnungen noch unvollständig.
3. Für die Disposition sind noch keine konfliktgeprüften Fahrzeuge, Räume oder Kapellen gepflegt.
4. Eine normative EN16931-/PDF/A-3-Prüfung benötigt weiterhin einen Mustang-/KoSIT-Validator.
5. E-Mail-/Push-Zustellung und Nextcloud-Cron müssen betrieblich kontrolliert werden.
6. Der letzte automatische Wartungslauf stammt vom 02.09.2026; die regelmäßige Cron-Ausführung ist zu prüfen.

## Nicht destruktiv geprüfte Funktionen

- Es wurde keine vorhandene Sicherung gelöscht.
- Die automatische Fristbereinigung wurde nicht auf vorhandene Sicherungen angewendet.
- Es wurde kein Restore in die aktuell gefüllte Installation ausgeführt.
- Es wurden keine Fall-, Aufgaben-, Termin- oder Rechnungsdaten verändert.

## Noch erforderlicher Wiederherstellungstest

Das neu erzeugte ZIP-Fachpaket ist gemäß `WIEDERHERSTELLUNGSTEST.md` in einer leeren, isolierten Zielinstallation zunächst als Vorschau und anschließend mit Bestätigungscode wiederherzustellen. Danach sind Tabellenzahlen, Fallakten, Vorlagen, Artikelbilder und Prüfsummen mit dem Manifest zu vergleichen.

