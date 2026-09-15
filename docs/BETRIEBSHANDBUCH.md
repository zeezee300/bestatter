# Betriebshandbuch Bestatter

## Geltungsbereich

Version 0.52 ist für den fachlichen Betrieb von der Fallanlage bis einschließlich Rechnungsstellung vorbereitet. Zahlungsüberwachung, Mahnwesen, Storno/Gutschrift und Dienstplanung bleiben außerhalb dieses Freigabestands; die Paperless-Anbindung ist optional.

## Systemvoraussetzungen

- Nextcloud 34 mit den von Nextcloud geforderten PHP-Erweiterungen
- MariaDB 11.4 oder eine von Nextcloud 34 unterstützte Datenbank
- Redis für File Locking und Cache
- Cron-Ausführung mindestens alle fünf Minuten; AJAX-Cron reicht für den Regelbetrieb nicht
- ausreichend dimensionierter Datenordner mit täglicher Speicherüberwachung
- HTTPS sowie korrekt konfigurierte Trusted Proxies, Host und Protokoll
- EuroOffice/ONLYOFFICE nur für DOCX-Bearbeitung/PDF-Konvertierung (optional)
- AppAPI/HaRP und Speech-to-Text nur für KI-Funktionen (optional); ohne KI bleibt die App vollständig bedienbar

## Installationsparameter

Unter **Administration → Systemprüfung → Installationsparameter** werden Mitgliedergruppe, Administrationsgruppen sowie Arbeits-, Fall-, Vorlagen- und Funktionsordner gepflegt. Die Werte liegen updatefest in der Nextcloud-App-Konfiguration.

Wichtige Schlüssel für `occ config:app:set bestatter`: `member_group`, `admin_groups`, `storage_root`, `cases_folder`, `templates_folder`, `case_subfolders`, `default_upload_folder`, `order_folder`, `authorities_folder`, `billing_folder`.

### Neuinstallation ohne Bestatter-Benutzer

Die App legt keine Benutzer automatisch an. Ein Nextcloud-Systemadministrator öffnet **Administration → Ersteinrichtung**, wählt vorhandene Benutzer, Gruppenbezeichnungen, Ablagepfade, Länderprofil und erste Niederlassung aus. Fehlende Gruppen werden ausschließlich nach gesetzter Bestätigung angelegt. Bis die Pflichtprüfungen bestanden sind, bleibt die Fachnavigation gesperrt; der Assistent kann jederzeit wieder geöffnet werden.

### Deaktivierung und Deinstallation

`occ app:disable bestatter` deaktiviert nur die Anwendung. App-Tabellen, Konfiguration, Fallordner, Dokumente und Groupware-Daten bleiben erhalten.

Auch Version 0.52 löscht beim Deaktivieren oder Entfernen des App-Codes keine Fachdaten automatisch. Für eine beabsichtigte Wiederinstallation ist ausdrücklich `occ app:remove --keep-data bestatter` zu verwenden.

Eine endgültige Bereinigung erfolgt ausschließlich kontrolliert: `php occ bestatter:purge` zeigt Tabellen, Aktivitäten, Groupware-Objekte und Dateiwurzeln an, ohne etwas zu verändern. Die Ausführung verlangt `--execute`, den festen Bestätigungscode und den absoluten Pfad eines vollständig geprüften ZIP-Fachpakets. Legal Holds oder unvollständig löschbare Kalender-/Dateiziele brechen den Vorgang ab. Benutzer, Gruppen, Sicherungen und externe Paperless-Originale bleiben erhalten.

### Sicherungsverwaltung

Unter **Administration → Backup & Restore** wird ein relativer Server-Unterordner innerhalb des Nextcloud-Datenverzeichnisses gepflegt; Standard ist `bestatter-backups`. Die Anwendung zeigt zusätzlich ältere Sicherungen am bisherigen Speicherort. JSON-Snapshots und ZIP-Fachpakete haben getrennte, standardmäßig 30 beziehungsweise 90 Tage lange Aufbewahrungsfristen. Fristablauf führt nur zu einer Markierung; Löschung erfolgt nach administrativer Bestätigung. Das letzte gültige ZIP-Fachpaket bleibt geschützt.

„Speichern unter …“ öffnet in unterstützten Chromium-Browsern eine lokale Dateiauswahl. Fehlt diese Browserfunktion, greift der normale Downloadmechanismus. Die App kann aus Sicherheitsgründen keinen lokalen Ordner ohne Benutzerbestätigung festlegen.

## Rollen und Berechtigungen

- Nextcloud-Systemadministratoren und konfigurierte Bestatter-Administrationsgruppen: Fachzugriff plus Administration.
- Konfigurierte Bestatter-Mitgliedergruppe: Fachzugriff ohne Administration.
- Andere Konten: kein Seiten- oder API-Zugriff.
- Die zentrale Middleware schützt auch neue Routen; administrative Endpunkte prüfen zusätzlich die Administratorrolle.

Prüfung:

```bash
docker exec -u www-data nextcloud-bestatter-test php occ bestatter:acceptance-check \
  --admin=Bestatter-Administration --member=Bestatter-User1 --outsider=rainer
```

## Audit

Fachliche und sensible administrative Änderungen werden mit Benutzer, Zeit, Objekt, Aktion und minimierten Alt-/Neu-Werten protokolliert. Geheimnisse, Token, Bankkennungen und große Inhalte werden maskiert oder gekürzt. Neue Einträge sind über SHA-256 verkettet; die Systemprüfung zeigt Kettenbrüche. Das Audit ersetzt keine externe revisionssichere Archivierung.

## Vertragspreise und Nachträge

Katalogpreise werden nur bei der erstmaligen Auswahl einer Leistung in den Fall übernommen. Festgeschriebene Kostenvoranschläge und Aufträge enthalten unveränderliche Positions-Snapshots und werden durch spätere Katalogpflege nicht verändert. Bewusste Abweichungen vor der ersten Rechnungsanlage müssen in der Leistungsauswahl über **Vertragsnachtrag erfassen** begründet werden; die Änderung wird vollständig auditiert. Ab der ersten angelegten Rechnung sind Vertragspositionen gesperrt. Der ausführliche Anwenderablauf steht in `VERTRAGSPREISE-UND-NACHTRAEGE.md`.

## Regelbetrieb und Abnahme

Täglich sind fehlgeschlagene Jobs, freier Speicher und Systemprüfung zu kontrollieren. Wöchentlich werden Backups und fällige Aufbewahrungsfälle geprüft. Ein vollständiger isolierter Restore erfolgt mindestens quartalsweise und vor wichtigen Freigaben.

Die Freigabe nutzt höchstens drei synthetische Fälle: Standardfall mit Schlussrechnung, Teilrechnungs-/Eingangsrechnungsfall und Ausnahmefall mit Terminänderung sowie Dokumentworkflow. Ein Massentest ist eine spätere eigene Freigabestufe.
