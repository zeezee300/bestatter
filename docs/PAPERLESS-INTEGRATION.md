# Optionale Paperless-ngx-Integration

## Zielbild

Die Bestatter-App bleibt ohne Paperless-ngx vollständig nutzbar. Fall, KVA/Auftrag, Fallleistungen, Eingangsrechnungsprüfung, Klassifikation, Übernahme und Ausgangsrechnung werden ausschließlich in der Bestatter-App geführt. Das unveränderte, unabhängig verfügbare Original liegt im Nextcloud-Fallordner. Paperless ist ein optionaler Eingang-, OCR-, Vorschau- und Volltextdienst.

Version 0.48 fasst die ursprünglich getrennten Integrationsstufen 0.47 und 0.48 zusammen. Automatische Rechnungs- und Positionserkennung gehört ausdrücklich **nicht** zu diesem Release. Version 0.49 ergänzt davon unabhängig den OCR-Kanal für Auftragserfassungsbögen; Rechnungs-OCR bleibt weiterhin zurückgestellt.

## Betriebsarten

- `Deaktiviert`: keinerlei Paperless-Aufrufe; der bisherige manuelle Prozess bleibt unverändert.
- `Nur Kopie an Paperless`: direkt in der Bestatter-App hochgeladene Eingangsbelege werden nach ihrer sicheren Nextcloud-Ablage asynchron an Paperless übertragen.
- `Beidseitiger Belegeingang`: zusätzlich können Paperless-Workflows neue Belege an den zentralen Bestatter-Belegeingang melden.

Die Betriebsart kann unter `Administration → Paperless` geändert werden. API-Token und Webhook-Geheimnis werden serverseitig verschlüsselt gespeichert und nie über die Lese-API an den Browser zurückgegeben.

## Prozess A – direkter Upload im Sterbefall

1. Benutzer öffnet `Fallakte → Finanzen → Eingangsrechnung erfassen`.
2. Originaldatei und manuell geprüfte Kopf-/Positionsdaten werden erfasst.
3. Die Datei wird zuerst unter dem konfigurierten Rechnungsordner der Nextcloud-Fallakte gespeichert.
4. Die Eingangsrechnung wird unabhängig von Paperless als Entwurf angelegt.
5. Bei aktiver Integration wird eine Verknüpfung mit Status `QUEUED` angelegt.
6. Der Hintergrundjob überträgt eine Kopie an `/api/documents/post_document/`.
7. Die von Paperless zurückgegebene Task-ID wird persistiert; `/api/tasks/?task_id=…` wird bis zum Abschluss abgefragt.
8. Paperless-Dokument-ID, Prüfsumme, Status und Zeitpunkt werden mit dem Eingangsbeleg verknüpft.
9. Bei einem Fehler bleibt der Nextcloud-Beleg nutzbar. Nach höchstens fünf automatischen Versuchen wird `FAILED` angezeigt und eine gezielte Wiederholung angeboten.

Eine Paperless-Störung führt niemals zum Rollback der bereits erfolgreichen Nextcloud-Ablage oder der Eingangsrechnung.

## Prozess B – Eingang über Paperless

1. Paperless übernimmt einen Beleg per E-Mail-Regel, Scanner/Consume-Verzeichnis, Weboberfläche oder eigener API.
2. Ein Paperless-Workflow ordnet mindestens den Dokumenttyp `Eingangsrechnung` zu.
3. Der Workflow sendet nach erfolgreicher Verarbeitung einen POST-Webhook an die in `Administration → Paperless` angezeigte URL.
4. Der Webhook verwendet den Header `X-Bestatter-Webhook-Token` mit dem separat erzeugten Geheimnis.
5. Minimaler JSON-Inhalt:

```json
{
  "event": "document_added",
  "document_id": "{{ document.id }}",
  "title": "{{ document.title }}"
}
```

6. Wiederholte Meldungen derselben Paperless-Dokument-ID werden idempotent beantwortet und erzeugen keinen zweiten Eingang.
7. Der Beleg erscheint für Bestatter-Mitglieder unter `Belegeingang`.
8. Ein Benutzer öffnet bei Bedarf das Paperless-Original, wählt den Sterbefall und bestätigt Lieferant, Rechnungsnummer, Daten, Betrag und zunächst mindestens eine Position.
9. Die Bestatter-App lädt das Original serverseitig aus Paperless und legt es im Nextcloud-Fallordner ab.
10. Erst danach wird eine normale Eingangsrechnung mit Quelle `PAPERLESS` angelegt. Sie durchläuft unverändert Rechnungsdaten, Positionsabgleich, Prüfung, Übernahme und Freigabe.

## Technische Architektur

`bestatter_external_documents` hält die anbieterneutrale Quellenkette zwischen Paperless-Dokument, Nextcloud-Datei und fachlichem Eingangsbeleg. `bestatter_integration_jobs` ist eine persistente Warteschlange für Upload, Statusabfrage, Wiederholung und Fehlerdiagnose. Die fachlichen Tabellen enthalten weiterhin keine Paperless-Abhängigkeit.

Der `PaperlessSyncJob` läuft über die Nextcloud-Hintergrundjobs alle fünf Minuten, sofern Nextcloud-Cron regelmäßig ausgeführt wird. Verwaiste `RUNNING`-Claims werden nach 15 Minuten wieder freigegeben. Netzwerkaufrufe haben Verbindungs- und Gesamttimeouts; Fehlermeldungen werden gekürzt und Zugangstoken aus Meldungen entfernt.

## Paperless-Grundkonfiguration

Empfohlen werden:

- ein eigener technischer Paperless-Benutzer ohne Superuser-Rechte;
- global nur die benötigten API-Berechtigungen;
- objektbezogene Sicht-/Änderungsrechte für die Bestatter-Gruppe;
- Dokumenttyp `Eingangsrechnung`;
- Tags `Bestatter`, `Eingangsrechnung`, `Ungeprüft`, `Fall zugeordnet`;
- HTTPS zwischen Nextcloud und Paperless;
- eine Paperless-Webhook-Regel nur für den Dokumenttyp Eingangsrechnung;
- keine Fall- oder Personendaten in Webhook-URLs und technischen Logs.

Die numerischen IDs des Dokumenttyps und der Standard-Tags können in der Administration hinterlegt werden. Der API-Token wird im Paperless-Benutzerprofil des technischen Benutzers erzeugt.

Der Paperless-Workflow verwendet den Trigger `Dokument hinzugefügt`. Als Filter dient der Dokumenttyp `Eingangsrechnung` oder ein ausschließlich dafür verwendetes Eingangstag. Die Webhook-Aktion sendet JSON an die in der Bestatter-Administration angezeigte URL, setzt den Header `X-Bestatter-Webhook-Token` und verwendet diesen Body:

```json
{"event":"document_added","document_id":"{{doc_id}}","title":"{{doc_title}}"}
```

`{{doc_id}}` und `{{doc_title}}` sind Paperless-Workflow-Platzhalter. Der Dokumenttyp muss bereits durch Uploadparameter, E-Mail-Regel, Matching oder einen vorgelagerten Workflow gesetzt sein, damit der Filter zuverlässig greift.

Empfohlene Trennung der Tags:

- `Bestatter-Archivkopie`: wird in der Bestatter-Administration als Standard-Tag-ID hinterlegt und kennzeichnet Dokumente, die bereits einem Fall zugeordnet sind und nur an Paperless exportiert werden.
- `Bestatter-Zuzuordnen`: wird ausschließlich bei neu über Paperless eingehenden Rechnungen gesetzt. Nur dieses Tag löst den Webhook zur Bestatter-App aus.

Damit erzeugt eine aus der Bestatter-App exportierte Archivkopie keinen neuen unzugeordneten Beleg im Belegeingang.

## Ausfall, Dubletten und Löschung

- Paperless deaktiviert oder nicht erreichbar: manuelle Erfassung bleibt vollständig verfügbar.
- Mehrfacher Webhook: gleiche Paperless-ID wird nur einmal angenommen.
- Mehrfacher Direktupload: bestehende SHA-256- und Lieferant/Rechnungsnummer-Prüfung bleibt aktiv.
- Container-Neustart: persistierte Jobs werden fortgesetzt; alte laufende Claims werden wiederholbar.
- Paperless-Dokument gelöscht: Nextcloud-Original und Fachvorgang bleiben erhalten; Systemprüfung zeigt Integrationsfehler.
- Nextcloud-Datei gelöscht: kein automatisches Nachladen oder Überschreiben; Wiederherstellung muss bewusst ausgelöst werden.
- Falllöschung/Anonymisierung: lokale Verknüpfungen und Jobs werden entfernt. Paperless-Dokumente werden in 0.48 niemals automatisch gelöscht.

## Erweiterung in Version 0.49

0.49 übernimmt OCR-Werte aus eindeutig klassifizierten Auftragserfassungsbögen als bestätigungspflichtige Vorschläge in die Schnellerfassung. Die vollständige Beschreibung steht in `AUFTRAGSSCHNELLERFASSUNG-OCR.md`.

Weiterhin zurückgestellt bleiben für Eingangsrechnungen:

- Abruf des OCR-Volltexts und strukturierter Paperless-Metadaten;
- Vorschläge für Lieferant, Rechnungsnummer, Rechnungs-/Fälligkeitsdatum und Summen;
- bevorzugte Extraktion vorhandener XRechnung-/ZUGFeRD-Daten;
- Positionsvorschläge mit Konfidenz und sichtbarer Herkunft;
- Lieferantenabgleich und Lernkorrekturen.

Jeder Vorschlag bleibt bestätigungspflichtig. Ungeprüfte Erkennung darf weder Fallleistungen erzeugen noch Beträge für eine Kundenrechnung freigeben.

## Referenzen

- Paperless-ngx REST API: https://docs.paperless-ngx.com/api/
- Paperless-ngx Nutzung, E-Mail-Eingang, Workflows und Berechtigungen: https://docs.paperless-ngx.com/usage/
