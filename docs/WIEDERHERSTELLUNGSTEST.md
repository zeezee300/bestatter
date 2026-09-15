# Wiederherstellungstest

## Ziel und Prüffälle

Nachweis der gemeinsamen Wiederherstellung von App-Daten, Nextcloud-Dateien und Konfiguration in eine isolierte Umgebung. Der Umfang ist auf höchstens drei synthetische Fälle begrenzt:

1. Standardfall mit Auftrag, Aufgaben, Termin, Dokumenten und Schlussrechnung samt QR-Code.
2. Teilrechnungsfall mit erbrachten Teilmengen, Teilrechnung, Restmenge und Eingangsrechnung.
3. Ausnahmefall mit Terminverschiebung, Workflow-Folgeaktion, Legal Hold und Audit-Historie.

## Protokoll

| Schritt | Soll | Nachweis | Ergebnis |
|---|---|---|---|
| Backup | DB, Dateien, Config und App-Snapshot vollständig | Pfade, Größen, SHA-256 | offen |
| Isoliertes Ziel | keine Verbindung zum Produktivbetrieb | Compose-Projekt und Netze | offen |
| Restore | kein unkontrollierter Teilzustand | Logs und Exit-Code | offen |
| Integrität | Checksummen und Audit-Kette OK | Restore-Vorschau/Systemprüfung | offen |
| Rollen | Admin, Mitglied, Außenstehender korrekt | `bestatter:acceptance-check` | offen |
| Fall 1–3 | Datensätze, Dokumente und Summen stimmen | Vier-Augen-Browserprüfung | offen |
| Groupware | Aufgaben/Termine ohne Duplikate | Kalender/Tasks und Deep-Link | offen |
| Abschluss | Quellsystem unverändert | unterschriebenes Protokoll | offen |

Dieses Dokument ist die Prüfanweisung. Ein realer Restore gilt erst als bestanden, wenn Nachweise und Ergebnisse ausgefüllt und von einer zweiten Person geprüft wurden. Automatisierte Quellcode-Tests allein sind kein Produktiv-Restore.
