# Nextcloud 35 – Kompatibilitätsvorbereitung 0.65.0

Status: Schritt 1 umgesetzt, **nicht als Nextcloud-35-laufzeitgeprüft freigegeben**. Der bestehende Nextcloud-34-Testcontainer bleibt bis zur isolierten Abnahme unverändert.

## Codeabgleich

- `appinfo/info.xml` unterstützt 34–35. `composer.json` lässt OCP 34 oder die derzeit nur als `35.0.0.x-dev` veröffentlichte OCP-35-Linie als Entwicklungsabhängigkeit zu; das bestehende `composer.lock` enthält noch OCP 34.0.3. Im NC35-Testzweig muss Composer mit OCP 35 neu aufgelöst werden; der Release-ZIP enthält weder `composer.json` noch `composer.lock`, sondern den unveränderten Laufzeit-`vendor`.
- Nextcloud 35 benötigt PHP mindestens 8.3. Der `php`-Constraint der App bleibt für Nextcloud 34 bewusst auch mit PHP 8.2 kompatibel; beim NC35-Test muss tatsächlich PHP 8.3 oder neuer eingesetzt werden.
- Alle sieben App-OCC-Befehle besitzen bereits `execute(...): int`. Die dokumentiert entfernten globalen Browser-Aliasse und Migrationsmethoden wurden im App-Quellcode nicht gefunden. Der einzelne Aufruf `setType()` in `BestatterActivityService` betrifft Activity, nicht die entfernte Datenbankschema-Methode.
- Doctrine-DBAL-Ausnahmen werden noch gefangen; dies ist kein Nachweis der vollständigen NC35-Laufzeitkompatibilität und muss im Integrationstest beobachtet werden.

## Vor Freigabe des Containerwechsels

1. Eine vom bisherigen Testsystem getrennte NC35-Instanz mit PHP 8.3+ und MariaDB 11.4 aufbauen; Datenbank, Fallakten, Konfiguration und App-Code nur aus einer geprüften Kopie übernehmen.
2. In einer PHP-/Composer-Umgebung gegen OCP 35 auflösen und PHP-Lint, statische Analyse, PHPUnit sowie die 118+ MJS-Tests ausführen. Die alte `composer.lock` nicht als Nachweis für OCP 35 verwenden.
3. Neuinstallation und Upgrade von App und Nextcloud in der Kopie testen; `occ status`, Migrationsverlauf, App-Aktivierung und Logs prüfen. Keine Kompatibilitätssperre mit `--force` umgehen.
4. Fallanlage/-suche, Aufträge, Nebenaufträge, Katalog, Dokumenterzeugung/PDF, Mailversand, Rechte, Cron und die installierten Zusatz-Apps mit Testdaten abnehmen. Auch Nextcloud 34 muss mit dem neuen App-Paket weiterlaufen.
5. Erst nach dokumentiertem Bestehen beider Versionen und gesicherter Rückfallstrategie den regulären Testcontainer auf ein festes `nextcloud:35.0.x-apache`-Image umstellen. Das gemeinsame Image-Tag für `nextcloud` und `cron` verwenden; niemals den ungebundenen `latest`-Fallback nutzen.

Referenzen: [kritische Änderungen für App-Entwickler](https://docs.nextcloud.com/server/stable/developer_manual/release_notes/critical_changes.html), [Upgrade auf Nextcloud 35](https://docs.nextcloud.com/server/stable/admin_manual/release_notes/upgrade_to_35.html).
