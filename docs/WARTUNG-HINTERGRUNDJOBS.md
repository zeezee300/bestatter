# Wartung und Hintergrundjobs

## Betriebsmodell

Die Bestatter-App registriert `OCA\Bestatter\BackgroundJob\MaintenanceJob` als Nextcloud-`TimedJob`. Er läuft frühestens alle sechs Stunden, nie parallel und ist als zeitunkritisch gekennzeichnet. Nextcloud darf ihn deshalb in das konfigurierte Wartungsfenster verschieben.

Der Job liest ausschließlich kompakte Betriebskennzahlen: verwaiste Fallaktivitäten, gespeicherte Synchronisationsfehler, fehlgeschlagene oder laufende Workflows und ungeprüfte Assistentenregeln. Ergebnis, Zeitpunkt und Status werden in der App-Konfiguration gespeichert und unter **Administration → Systemprüfung** angezeigt. Der Job löscht keine Daten.

## Erforderliche Serverkonfiguration

Für den produktionsnahen Betrieb muss Nextcloud den Modus `cron` verwenden. Der vorhandene Compose-Service `cron` mit `/cron.sh` ist dafür geeignet. Nach dem Upgrade prüfen:

```bash
docker exec -u www-data nextcloud-bestatter-test php occ background:cron
docker exec -u www-data nextcloud-bestatter-test php occ background-job:list -c 'OCA\Bestatter\BackgroundJob\MaintenanceJob'
```

Der einzelne Job kann für die Abnahme mit seiner in der Liste angezeigten ID einmalig ausgeführt werden:

```bash
docker exec -u www-data nextcloud-bestatter-test php occ background-job:execute --force-execute JOB_ID
```

## Empfohlene Aufgabenteilung

- App-Job: fachliche Integritätskennzahlen und Warnungen ohne Löschung.
- Nextcloud-Core: Papierkorb, Dateiversionen, Vorschauen, Aktivitäten und weitere Nextcloud-eigene Pflege.
- Container/Host: tägliche Datenbank- und Dateisicherung, Restore-Test, Image-/Sicherheitsupdates, Speicherplatz- und Zertifikatsüberwachung.
- Separater Malware-Scanner: eingehende Dokumente prüfen; dies gehört nicht in die Fachlogik der App.
- Manuell/freigegeben: fehlgeschlagene Workflows, Synchronisationsfehler und offene Assistentenregeln bearbeiten.
- Nextcloud Activity: fachliche Bestatter-Ereignisse gemäß den persönlichen Schaltern ausliefern und E-Mails in der gewählten Standardhäufigkeit schnellstmöglich, stündlich, täglich oder wöchentlich bündeln. Ein eigener Bestatter-Versandjob ist dafür nicht erforderlich.

## Nicht automatisieren ohne Richtlinie

Fälle, Dokumente, Rechnungen, Workflowprotokolle und Auditdaten dürfen erst nach Festlegung von Aufbewahrungsfrist, Sperrgründen, Archivierung, Löschfreigabe und Nachweis automatisch bereinigt werden. Auch pauschale Datenbankoptimierungen wie `OPTIMIZE TABLE` gehören in ein abgestimmtes Wartungsfenster und nicht in einen App-Job.
