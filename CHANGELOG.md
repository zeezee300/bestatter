# Änderungsstand

Je Version ein Abschnitt `## x.y.z – Titel` (Format des Nextcloud App Stores).

## 0.83.3 – Erste öffentliche Fassung

Erste Veröffentlichung des Quellcodes unter der GNU Affero General Public License, Version 3 (AGPL-3.0-only). Funktionsumfang dieser Fassung:

- **Digitale Fallakte** mit Stammdaten, Kontakten, Aufgaben, Terminen, Dokumenten, Leistungen und Rechnungen; Fallübersicht, globale Nextcloud-Suche, Marker mit Notizen.
- **Schnellerfassung** eines Auftrags – auf Wunsch mit Texterkennung eines Erfassungsbogens und Spracherkennung über Nextcloud-Anbieter; jede Übernahme wird bestätigt.
- **Abläufe in 7 Phasen** mit Auslösern, Rollen (Nextcloud-Gruppen), Fristen und automatisch erzeugten Nextcloud-Aufgaben; Prüfhinweise nennen fehlende oder umbenannte Rollengruppen.
- **Termine und Ressourcen** mit Konfliktprüfung und Kalenderabgleich.
- **Dokumente** aus Vorlagen (DOCX/PDF), Dokumentarten, Abmeldungen mit Fallnummer als „Unser Zeichen“.
- **Abrechnung** mit Leistungskatalog, Kostenvoranschlag, Auftrag, Teil- und Schlussrechnungen, ZUGFeRD/XRechnung und EPC-Zahlcode, Nebenaufträge.
- **Paperless-ngx (optional)** – Belegeingang mit Zuordnungsvorschlägen, Archivkopien und Erfassungsbögen steuerbar.
- **Betrieb** – Systemprüfung, unveränderliches Audit-Log, Sicherung und Wiederherstellung (auch automatisch), Aufbewahrung, Einrichtungspakete, Update-Skript `bin/bestatter-update.sh` mit `occ bestatter:update-check`.
- **Oberfläche** – einklappbare Navigation links, Handbuch (HTML und PDF) und Bedienhilfe in der App.
