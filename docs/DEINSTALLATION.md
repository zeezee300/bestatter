# Deaktivierung, Deinstallation und Purge

`occ app:disable bestatter` und `occ app:remove --keep-data bestatter` sind datenerhaltende Vorgänge. Sie löschen keine Fälle, Rechnungen, Auditdaten, Dateien oder Groupware-Objekte.

Vor einer endgültigen Bereinigung wird ein vollständiges Fachpaket erstellt und extern gesichert:

```bash
php occ bestatter:backup --include-files
php occ bestatter:purge
```

Der zweite Befehl ist zunächst nur ein Trockenlauf. Er listet Datenbankzeilen, Aktivitäten, Kalenderobjekte, App-Konfiguration, Hintergrundjobs und die präzisen verwalteten Dateiwurzeln. Legal Holds müssen fachlich geklärt werden.

Erst anschließend darf der angezeigte Befehl mit `--execute`, `--backup-package=/absoluter/pfad/bestatter-package-….zip` und `--confirmation=BESTATTER-ENDGUELTIG-LOESCHEN` ausgeführt werden. Das Paket muss vollständig und prüfbar sein. Fehler bei Kalender- oder Dateibereinigung stoppen den Datenbank-Purge.

Erhalten bleiben Nextcloud-Benutzer und Gruppen, Sicherungspakete, externe Paperless-Originale sowie nicht eindeutig von der App verwaltete Dateien. Das Ergebnisprotokoll ist gemeinsam mit Sicherungsprüfsumme und Freigabe aufzubewahren.
