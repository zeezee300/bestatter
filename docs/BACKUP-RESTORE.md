# Backup und Restore

## Zwei Sicherungsebenen

`bestatter:backup` erstellt standardmäßig einen prüfbaren JSON-Snapshot aller App-Tabellen und der App-Konfiguration. Mit `--include-files` entsteht ein ZIP-Fachpaket mit Datenbanksnapshot, Fallakten, Dokumentvorlagen und Artikelbildern. Ein Manifest enthält für jede Datei Pfad, Größe, MIME-Typ und SHA-256-Prüfsumme.

Ein vollständiges Backup umfasst gemeinsam Datenbank-Dump, Nextcloud `config/`, kompletten Datenordner, `custom_apps/bestatter` beziehungsweise das Installationspaket, Compose-Datei, separat geschützte Secrets sowie Versionsliste und SHA-256-Prüfsummen.

## App-Snapshot

Bestatter-Administratoren können unter **Administration → Backup & Restore** einen Snapshot erstellen, vorhandene Snapshots anhand ihrer eingebetteten SHA-256-Prüfsumme prüfen und herunterladen. Die Oberfläche zeigt ausschließlich von der App verwaltete Dateien im Nextcloud-Datenverzeichnis an.

Neue Sicherungen liegen standardmäßig im Unterordner `bestatter-backups` des konfigurierten Nextcloud-Datenverzeichnisses, beispielsweise `/var/www/html/data/bestatter-backups/`. Der relative Unterordner kann in der Administration geändert werden, darf das Datenverzeichnis aber auch über symbolische Verknüpfungen nicht verlassen. Ältere Sicherungen aus dem bisherigen Stamm des Datenverzeichnisses bleiben als „bisheriger Speicherort“ sichtbar.

Der tatsächliche Stamm wird mit `php occ config:system:get datadirectory` angezeigt. Der Serverordner ist nicht mit dem lokalen Downloadordner zu verwechseln: Bei unterstützten Edge-/Chromium-Versionen öffnet **Speichern unter …** die Betriebssystemauswahl. Andernfalls verwendet die App den normalen Browserdownload; dessen Rückfrage lässt sich in den Browser-Downloadeinstellungen aktivieren.

Der Snapshot enthält die App-Konfiguration einschließlich dort gespeicherter Integrationsgeheimnisse. Exportierte Dateien sind daher wie Zugangsdaten zu behandeln, verschlüsselt abzulegen und nur dem festgelegten Administrationskreis zugänglich zu machen.

Alternativ steht OCC für beide Sicherungsarten zur Verfügung:

```bash
docker exec -u www-data nextcloud-bestatter-test php occ bestatter:backup
docker exec -u www-data nextcloud-bestatter-test php occ bestatter:backup --include-files
```

Die Ausgabe nennt Sicherungsdatei, externe `.sha256`, Tabellen- beziehungsweise Dateizähler und Scope. `--output=/absoluter/pfad/datei.json` gilt nur für den JSON-Snapshot.

## Aufbewahrung und Löschen

Standardmäßig werden JSON-Schnellsnapshots nach 30 Tagen und ZIP-Fachpakete nach 90 Tagen als fristabgelaufen markiert. `0` bedeutet unbegrenzte Aufbewahrung. Die Frist allein löscht nichts: Ein Administrator kann einzelne Dateien oder alle markierten Altbestände nach einer Sicherheitsabfrage löschen. Sicherungsdatei und zugehörige `.sha256` werden gemeinsam entfernt und die Aktion wird im Audit-Protokoll dokumentiert. Das letzte gültige vollständige Fachpaket ist gegen Löschung geschützt.

Die Sicherungsverwaltung ersetzt keine externe, verschlüsselte und gegen Veränderung geschützte Backup-Ablage. Vor dem Löschen sollte mindestens ein geprüftes ZIP-Fachpaket außerhalb des Nextcloud-Servers vorhanden sein.

## Vollständige Sicherung

Ziel und freien Speicher prüfen, Wartungsmodus aktivieren, konsistenten MariaDB-Dump und die genannten Verzeichnisse sichern, Prüfsummen getrennt ablegen, Wartungsmodus deaktivieren und Status prüfen. Pfade und Zugangsdaten sind immer aus der jeweiligen Compose-/`.env`-Konfiguration zu entnehmen und gehören nicht in Shell-Historien.

## App-Restore

Die Administrationsoberfläche erläutert und vorbereitet den Restore, führt ihn aber absichtlich nicht gegen eine laufende Installation aus. Das verhindert ein versehentliches Überschreiben vorhandener Fach- und Auditdaten.

Nur in einer leeren, isolierten Zielinstallation:

```bash
docker exec -u www-data nextcloud-bestatter-test php occ bestatter:restore /pfad/bestatter-package.zip
docker exec -u www-data nextcloud-bestatter-test php occ bestatter:restore /pfad/bestatter-package.zip \
  --execute --confirmation=BESTATTER-RESTORE-LEERES-ZIEL
```

Der erste Aufruf prüft Manifest, eingebetteten Snapshot, jede Dateiprüfsumme und das leere Ziel ohne Schreibzugriff. Der zweite stellt Dateien und App-Daten wieder her; bei einem Fehler werden neu erzeugte Dateien entfernt und die Datenbanktransaktion zurückgerollt. Danach `occ maintenance:repair`, `occ status`, `bestatter:acceptance-check` und Browser-Abnahme ausführen.

Auch ein JSON-Schnellsnapshot kann mit demselben Befehl restauriert werden. Er enthält jedoch ausschließlich App-Tabellen und App-Konfiguration. Für eine fachlich vollständige Wiederherstellung ist das ZIP-Fachpaket zu verwenden. Der JSON-Snapshot bleibt als kleiner, schneller Prüfpunkt vor Updates oder Konfigurationsänderungen sinnvoll, wenn die Dateien bereits durch das reguläre Nextcloud-Backup geschützt sind.

Für den vollständigen Restore eine isolierte Umgebung mit identischen Versionen anlegen, Datenbank und Dateisystem gemeinsam zurückspielen, Eigentümer/Rechte prüfen und erst nach Abnahme umschalten. Niemals zuerst in die produktive Instanz restaurieren.
