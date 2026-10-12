# Bestatter

**Fachakte und Vorgangssteuerung für Bestattungsunternehmen – als native [Nextcloud](https://nextcloud.com)-App.**

„Bestatter" bildet den gesamten Ablauf eines Sterbefalls – von der Erstaufnahme über Termine, Aufgaben und Dokumente bis zur Abrechnung – an einem einzigen Ort ab, eingebettet in eine bereits vorhandene Nextcloud-Umgebung. Es entsteht kein zweites System, keine zusätzliche Cloud und keine doppelte Datenpflege: Dateien, Kalender, Kontakte, Aufgaben, Benachrichtigungen und Berechtigungen laufen über die ohnehin vorhandenen Nextcloud-Bausteine.

Eine ausführliche, bebilderte Beschreibung aller Funktionen enthält das **Anwenderhandbuch**: in der App über „Handbuch“ in der Navigationsleiste, als PDF unter [`resources/handbuch/Bestatter-Handbuch.pdf`](resources/handbuch/Bestatter-Handbuch.pdf). Es umfasst auch Installation (Server und Docker), Ersteinrichtung und Betrieb.

> **Sprache und Rechtsraum:** Die App ist auf Bestattungsunternehmen in Deutschland ausgerichtet (Abmeldungen, Dokumentvorlagen, ZUGFeRD/XRechnung). Oberfläche, Vorlagen und Handbuch sind deutschsprachig.

## Inhalt

- [Funktionsumfang](#funktionsumfang)
- [Voraussetzungen](#voraussetzungen)
- [Installation](#installation)
- [Aktualisierung](#aktualisierung)
- [Dokumentation](#dokumentation)
- [Ersteinrichtung](#ersteinrichtung)
- [CLI-Kommandos](#cli-kommandos-occ)
- [Hintergrund-Jobs](#hintergrund-jobs)
- [Konfiguration](#konfiguration)
- [Aus dem Quellcode bauen](#aus-dem-quellcode-bauen)
- [Installationspaket bauen und signieren](#installationspaket-bauen-und-signieren)
- [Codequalität](#codequalität)
- [Projektstruktur](#projektstruktur)
- [Sicherheit & Datenschutz](#sicherheit--datenschutz)
- [Lizenz](#lizenz)

## Funktionsumfang

- **Digitale Fallakte** – Stammdaten, Aufgaben, Termine, Dokumente, Leistungen und Rechnungen eines Sterbefalls an einem Ort, inklusive Fallübersicht, globaler Nextcloud-Suche und mobiler Ansicht.
- **Geführte Schnellerfassung** – optionale, KI-gestützte Transkription von Aufnahmegesprächen mit strukturierter Erkennung von Namen, Daten und weiteren Angaben; jede Übernahme erfordert eine ausdrückliche Bestätigung. Vollständig ohne KI-Anbindung nutzbar.
- **Termine & Ressourcen** – Terminarten mit Standarddauern, serverseitige Konfliktprüfung für Mitarbeitende und Ressourcen (Fahrzeuge, Räume), lückenlose Terminhistorie, verlustfreie CalDAV-Synchronisation.
- **Abläufe in 7 Phasen** – eine pflegbare Ablaufvorlage beschreibt je Schritt in Klartext, wann er entsteht, für welche Fälle er gilt, welche Rolle (Nextcloud-Gruppe) zuständig ist, bis wann er fällig ist und wann er sich selbst erledigt; Aufgaben entstehen automatisch als vollwertige Nextcloud-Tasks, der Fortschritt ist im Fallcockpit sichtbar (siehe Handbuch, Kapitel Customizing). Die 7 Phasen erscheinen in der Übersicht, im Reiter „Aufgaben“ und im Reiter „Termine“. 26 Auslöser, darunter Terminänderung, Dokument, Abmeldung, Stammdatenfeld und die zeitgesteuerten „Datum erreicht“ und „Rechnung überfällig“ (Hintergrundjob).
- **Dokumente auf Knopfdruck** – Vorlagenbibliothek (Bestattungsauftrag, -vollmacht, Sterbefallanzeige, Kondolenzliste, Abmeldungen u. a.), automatische Befüllung aus den Falldaten, Ausgabe als DOCX/PDF, Revisionssicherheit gegen Änderung finaler Dokumente, optionaler Direktversand über ein hinterlegtes Geschäfts-Postfach.
- **Abrechnung** – Kostenvoranschlag und verbindlicher Auftrag mit geführter Leistungsauswahl, Teil- und Schlussrechnungen, native ZUGFeRD-/XRechnung-Erstellung inkl. EPC-SEPA-Zahlcode, Abrechnungssicherheitsprüfung, Eingangsrechnungsprüfung sowie eigenständige Nebenaufträge für weitere Beteiligte im selben Fall.
- **Paperless-ngx-Anbindung (optional)** – automatischer, wiederanlauffähiger Beleg-Import inklusive OCR; fachliche Prüfung und Freigabe bleiben vollständig in „Bestatter".
- **Benachrichtigungen & Auswertungen** – native Nextcloud-Benachrichtigungen, geschützte CSV-Auswertungen je Niederlassung sowie ein rein lesendes Betriebs-Cockpit für die Administration.
- **Datenschutz, Datensicherheit & Vertrauen** – vollständiges, unveränderliches Audit-Log, eingebaute Systemprüfung (inklusive Nextcloud-Kompatibilitätsüberwachung), administrative Backup-/Restore-/Retention-/Purge-Kommandos, Schutz vor Wettlaufsituationen bei gleichzeitiger Bearbeitung sowie inhaltsbasierte Prüfung von Datei-Uploads.
- **Mehrere Standorte** – eigene Bankverbindungen, Absenderdaten, Kennzahlen und Länderprofile (Mehrwertsteuersätze, Rechnungsprofil, Zahl-Standard) je Niederlassung bei gemeinsamer Datenbasis.
- **Aufgeräumte Oberfläche** – einklappbare Navigation links; Administration und Customizing stehen für Bestatter-Administratoren im eigenen Abschnitt „Einstellungen“.

## Voraussetzungen

- Eine selbst betriebene oder gehostete **Nextcloud-Instanz**, Version 34 oder 35 (siehe `<dependencies>` in `appinfo/info.xml`; die App warnt über die eingebaute Systemprüfung rechtzeitig, bevor eine neue Nextcloud-Hauptversion sie automatisch deaktivieren würde).
- PHP 8.2, 8.3, 8.4 oder 8.5 (Nextcloud 35: ab 8.3) mit den Erweiterungen `gd` und `zip`.
- Nextcloud-Cron (System-Cron, mindestens alle 5 Minuten) sowie die Nextcloud-Apps **Activity**, **Kalender**, **Kontakte** und empfohlen **Aufgaben** (Tasks).
- Optional, aber empfohlen:
  - [**Paperless-ngx**](https://docs.paperless-ngx.com/) für die automatische Belegverarbeitung,
  - **Collabora Online** oder **OnlyOffice** für die Bearbeitung erzeugter DOCX-Dokumente direkt im Browser,
  - **Nextcloud Talk** mit lokaler Spracherkennung sowie ein in Nextcloud registrierter Task-Processing-Anbieter (`OCP\TaskProcessing`) für die KI-gestützte Schnellerfassung und den Bestatter-Assistenten.

Alle produktiv benötigten PHP-Abhängigkeiten (u. a. `endroid/qr-code`, `bacon/bacon-qr-code`) sind im Installations-Release bereits im Ordner `vendor/` enthalten – ein `composer install` ist für den reinen Betrieb nicht erforderlich.

## Installation

1. Das Installationspaket `bestatter-<version>.tar.gz` (oder `.zip`) herunterladen oder selbst bauen (siehe [Installationspaket bauen](#installationspaket-bauen-und-signieren)). Sobald die App im Nextcloud App Store veröffentlicht ist, genügt dort „Herunterladen und aktivieren“.
2. Das Paket nach `custom_apps/` der Nextcloud-Installation entpacken, sodass `custom_apps/bestatter/appinfo/info.xml` existiert, und die Dateien dem Webserver-Benutzer zuordnen (z. B. `chown -R www-data:www-data custom_apps/bestatter`; im offiziellen Docker-Image UID 33).
3. Aktivieren: `occ app:enable bestatter` oder in Nextcloud unter **Apps → Deaktivierte Apps**.
4. Die App als Nextcloud-Administrator öffnen und der Ersteinrichtung folgen (siehe unten). Rollen und Zugriffsrechte werden ausschließlich über reguläre Nextcloud-Gruppen gesteuert, es entsteht keine zweite Benutzerverwaltung.

Docker (offizielles Image und Nextcloud All-in-One) beschreibt das Handbuch im Kapitel Installation.

## Aktualisierung

Vorher Nextcloud sichern (Datenbank, Datenverzeichnis). Ab 0.83.1 liegt jedem Paket das Update-Skript `bin/bestatter-update.sh` bei; es prüft die Prüfsumme (`<paket>.sha256`), Version und Nextcloud-Kompatibilität, legt eine Sicherung an (`occ bestatter:backup --include-files`), bewahrt den bisherigen App-Ordner auf, tauscht ihn im Wartungsmodus aus, führt `occ upgrade` aus und prüft das Ergebnis mit `occ bestatter:update-check`:

```bash
tar -xzf bestatter-<version>.tar.gz bestatter/bin/bestatter-update.sh
bash bestatter/bin/bestatter-update.sh bestatter-<version>.tar.gz            # Probelauf
bash bestatter/bin/bestatter-update.sh bestatter-<version>.tar.gz --execute  # Update
# Docker: zusätzlich --docker <container>, z. B. nextcloud-aio-nextcloud
```

Von Hand: den Ordner `custom_apps/bestatter` vollständig durch den neuen Paketinhalt ersetzen und `occ upgrade` ausführen, danach `occ bestatter:update-check`. Migrationen laufen dabei automatisch; neue Hintergrundjobs registriert Nextcloud beim Update. Einzelheiten: Handbuch, Kapitel Technik → Update einspielen.

## Dokumentation

- **Anwenderhandbuch** (in der App und als PDF in `resources/handbuch/`) – Fallarbeit, Administration, Customizing, Dokumentvorlagen und Platzhalter, Installation und technischer Anhang.
- **Änderungsstand** – [`CHANGELOG.md`](CHANGELOG.md), je Version ein Abschnitt `## x.y.z – Titel`.

## Ersteinrichtung

Nach der Aktivierung führt ein eigener Einrichtungsassistent (Navigation → Einstellungen → Administration → Ersteinrichtung) durch Benutzergruppen, Niederlassungen und weitere Pflichteinstellungen und zeigt jederzeit, welche Punkte für den produktiven Einsatz noch offen sind.

## CLI-Kommandos (`occ`)

| Kommando | Zweck |
|---|---|
| `occ bestatter:acceptance-check` | Prüft Rollen, Audit-Kette und Systemzustand (nur lesend). |
| `occ bestatter:paperless-acceptance-check` | Prüft die Paperless-ngx-Anbindung (nur lesend). |
| `occ bestatter:backup [--include-files]` | App-Snapshot bzw. vollständiges Sicherungspaket mit Fallakten und Vorlagen. |
| `occ bestatter:restore <datei>` | Wiederherstellung, nur in eine leere Installation. |
| `occ bestatter:retention` | Wendet die Aufbewahrungsrichtlinie an (nur bei eingeschalteter Richtlinie, mit Hinweisen auf laufende Aufbewahrungsfristen). |
| `occ bestatter:purge [--remove-app]` | Entfernt alle Daten der App; mit `--remove-app` die App vollständig aus Nextcloud. |
| `occ bestatter:groupware-repair` | Bereinigt Altlasten der Nextcloud-Anbindung (doppelte Termine aus Kopien, Kontaktadressen, verlorene App-Daten). |
| `occ bestatter:setup-package` | Einrichtungspaket (Katalog, Preise, Vorlagen, Formulare, Marker, Abläufe, Logos) exportieren oder mit Vorschau und Sicherung einspielen. |
| `occ bestatter:update-check [--expect-version=x.y.z]` | Prüft nach einem Update Version, Systemprüfung und Audit-Kette (nur lesend; `--record` vermerkt den Update-Lauf). |
| `occ bestatter:labels [--activate=all\|KEY,…]` | Marker auflisten bzw. mitgelieferte Marker aktivieren (z. B. auf Testinstallationen). |
| `occ bestatter:storage-migrate --account=…` | Zieht persönliche Ablagen in die zentrale Fallablage um. |
| `occ bestatter:case-folder-rename <ordner> <name>` | Benennt einen Unterordner in allen Fallakten um. |
| `occ bestatter:development-reset` | Setzt Testdaten zurück; läuft nur nach `occ config:app:set bestatter development_reset_enabled --value=yes`. |

Alle datenverändernden Kommandos zeigen ohne weitere Angaben nur eine Vorschau und verlangen `--execute` sowie einen Bestätigungscode.

## Hintergrund-Jobs

Alle Jobs laufen über den Nextcloud-Cron, nie parallel.

- `MaintenanceJob` (6 Stunden) – Kennzahlen für die Systemprüfung (Integrität, Audit-Kette); löscht nur nicht abgeholte Sprachaufnahmen nach 24 Stunden.
- `PaperlessSyncJob` (5 Minuten) – wiederanlauffähige Übertragung und Statusabfrage von Belegen an Paperless-ngx.
- `ProcessScheduleJob` (6 Stunden) – zeitgesteuerte Ablaufschritte („Datum erreicht“, „Rechnung überfällig“).
- `GroupwareRetryJob` (30 Minuten) – wiederholt fehlgeschlagene Übertragungen von Aufgaben und Terminen nach Nextcloud, sobald Kalender bzw. Aufgabenliste der Person existiert.
- `ScheduledBackupJob` (15 Minuten) – legt nach dem Zeitplan unter Administration → Backup & Restore automatisch Sicherungen an und räumt abgelaufene auf (ab 0.78.0).

## Konfiguration

Konfigurationswerte liegen als App-Config in der Nextcloud-Datenbank (`occ config:app:*`), nicht in Dateien. Relevante Schlüssel u. a.:

- `development_reset_enabled` – muss explizit auf `yes` gesetzt sein, damit `occ bestatter:development-reset` überhaupt läuft.

Die eigentliche KI-/Spracherkennungs-Anbindung wird zentral in Nextcloud selbst über das Task-Processing-Framework konfiguriert, nicht innerhalb von „Bestatter".

## Aus dem Quellcode bauen

Voraussetzung: Node.js ≥ 22 und pnpm 10 (siehe `package.json`, `pnpm-lock.yaml`).

```bash
pnpm install
pnpm run build     # produktiver, minifizierter Build nach js/main.js
pnpm run dev       # Entwicklungs-Build ohne Minifizierung
pnpm run watch     # Entwicklungs-Build mit automatischem Rebuild bei Änderungen
```

Frontend-Quelldateien liegen unter `src/` (Einstiegspunkt `src/main.js`, Module unter `src/modules/`); `js/main.js` ist ein generiertes Build-Artefakt, wird aber eingecheckt (die CI prüft, dass es zu `src/` passt).

Die PHP-Laufzeitabhängigkeiten (`vendor/`, u. a. für ZUGFeRD und QR-Code) sind nicht Teil dieses Repositorys – für eine lokale Entwicklungsumgebung einmalig `composer install` ausführen. Das Installationspaket enthält `vendor/` fertig gebaut.

## Installationspaket bauen und signieren

```bash
bash tools/release/build-package.sh                     # releases/bestatter-<version>.tar.gz und .zip (unsigniert)
bash tools/release/build-package.sh --vendor-from ../vendor   # ohne Netz: vorhandenes vendor/ übernehmen
```

Das Skript prüft vorher, dass `appinfo/info.xml`, `package.json`, `Application::VERSION` und der Abschnitt in `CHANGELOG.md` dieselbe Version tragen, nimmt nur Laufzeitdateien auf (keine Quellen der Oberfläche, kein `.git`) und installiert die PHP-Bibliotheken ohne Entwicklungsabhängigkeiten.

Für den Nextcloud App Store wird zusätzlich signiert. Dazu braucht es den privaten Schlüssel `bestatter.key` und das von Nextcloud ausgestellte Zertifikat `bestatter.crt` sowie eine laufende Nextcloud für `occ integrity:sign-app`:

```bash
bash tools/release/build-package.sh --key ~/.nextcloud/certificates/bestatter.key --cert ~/.nextcloud/certificates/bestatter.crt \
  --container nextcloud --occ "docker exec -u www-data nextcloud php occ"
```

Das erzeugt `appinfo/signature.json` im Paket und die App-Store-Signatur des Archivs in `releases/bestatter-<version>.tar.gz.sig`. Der private Schlüssel wird nicht ins Paket aufgenommen und im Container sofort wieder gelöscht.

## Codequalität

Statische Analyse und Formatierung laufen über Composer-Skripte (siehe `composer.json`):

```bash
composer analyse   # PHPStan (lib/)
composer cs:check   # Code-Style prüfen (php-cs-fixer, --dry-run)
composer cs:fix      # Code-Style automatisch korrigieren
```

## Projektstruktur

```
appinfo/     Nextcloud-App-Manifest, Routen, Migrationen (via lib/Migration)
css/         Stylesheet der Anwendung
img/         App-Icon
js/          Gebautes Frontend-Bundle (Build-Artefakt, siehe oben)
lib/         PHP-Backend (Controller, Service, Middleware, Command, BackgroundJob, Migration …)
resources/   Mitgelieferte Dokumentvorlagen, Hilfetexte, Stammdaten (Leistungskatalog, Ablaufvorlagen …)
src/         Frontend-Quellcode (unminifiziert)
templates/   Server-seitiges PHP-Template der App-Seite
vendor/      PHP-Laufzeitabhängigkeiten (nicht im Repository, siehe oben)
tools/       Release-Paket (tools/release), Update-Skript (tools/update), CI-Konfiguration (tools/ci)
```

## Sicherheit & Datenschutz

„Bestatter" läuft vollständig innerhalb der eigenen Nextcloud-Infrastruktur; es findet kein Datenabfluss an Dritte statt (die Paperless- und KI-Anbindungen sind optional und werden ausschließlich gegen selbst konfigurierte, eigene Dienste angesprochen). Sicherheitsrelevante Konstruktionsmerkmale umfassen durchgängig parametrisierte Datenbankzugriffe, eine zentrale Zugriffs-Middleware auf Nextcloud-Gruppenbasis, Schutz vor Wettlaufsituationen bei gleichzeitiger Bearbeitung derselben Datensätze, inhaltsbasierte (nicht nur endungsbasierte) Prüfung hochgeladener Dateien sowie ein unveränderliches, hash-verkettetes Audit-Log.

Wird eine Sicherheitslücke vermutet, bitte **nicht** als öffentliches Issue melden, sondern vertraulich über GitHub („Security“ → „Report a vulnerability“), siehe [`SECURITY.md`](SECURITY.md).

## Lizenz

Copyright © 2026 Rainer Lüdeke („Bestatterplattform“ ist der Projektname).

Dieses Programm ist freie Software: Sie können es unter den Bedingungen der **GNU Affero General Public License, Version 3** (AGPL-3.0-only), wie von der Free Software Foundation veröffentlicht, weitergeben und/oder verändern – ausschließlich in Version 3, nicht in späteren Versionen. Der vollständige Lizenztext steht in `LICENSE`.

Dieses Programm wird in der Hoffnung bereitgestellt, dass es nützlich ist, aber OHNE JEDE GEWÄHRLEISTUNG – sogar ohne die implizite Gewährleistung der MARKTREIFE oder der EIGNUNG FÜR EINEN BESTIMMTEN ZWECK. Einzelheiten in der GNU Affero General Public License.

Wer eine veränderte Fassung über ein Netzwerk für andere bereitstellt, muss diesen Nutzern den Quellcode dieser Fassung anbieten (§ 13 AGPL). Mitgelieferte Fremdbestandteile behalten ihre eigenen Lizenzen (BSD-2-Clause, MIT), siehe `THIRD-PARTY-NOTICES.md`; sie sind mit der AGPL-3.0 vereinbar.
