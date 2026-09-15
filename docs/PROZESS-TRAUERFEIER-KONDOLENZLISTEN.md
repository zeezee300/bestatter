# Prozess: Trauerfeier und Kondolenzlisten

## Ziel und fachliche Leitlinie

Der bestätigte Trauerfeier-Termin ist die einzige verbindliche Quelle für Datum, Uhrzeit, Ort und externe Beteiligte. Aufgaben und Dokumente speichern eine stabile Referenz auf diesen Termin sowie bei der Dokumenterzeugung einen nachvollziehbaren Snapshot. Das vermeidet voneinander abweichende Datumsangaben in Aufgabe, Kalender und Dokument.

Eine Terminart beschreibt, **was** stattfindet. Der Workflow beschreibt, **was daraus folgt**. Deshalb werden keine eigenständigen, parallelen „Termin-Workflows“ eingeführt. Die vorhandene Workflow-Engine erhält stattdessen Terminereignisse als weiteren Auslöser.

## Standardablauf

1. Ein Mitarbeiter legt aus einer Aufgabe oder direkt einen Termin mit der Vorlage `TRAUERFEIER_1` oder `TRAUERFEIER_2` an. Neue Termine beginnen als **Entwurf**.
2. Datum, Uhrzeit, Dauer, Ort, Beteiligte und Zuständigkeiten werden geprüft. Externe Personen und Organisationen werden möglichst per Mehrfachauswahl aus den Nextcloud-Kontakten übernommen; Freitext bleibt für Ausnahmen möglich. Erst danach wird der Termin auf **Bestätigt** gesetzt.
3. Die Bestätigung startet einmalig den Workflow `TRAUERFEIER_VORBEREITEN`. Sein dauerhafter Ausführungs-Claim verhindert doppelte Folgeaufgaben, auch bei erneutem Speichern oder Kalenderabgleich.
4. Relativ zum Trauerfeier-Termin entstehen die Aufgaben:
   - drei Tage vorher: Kondolenzlisten erstellen;
   - zwei Tage vorher: Ablauf der Trauerfeier prüfen;
   - einen Tag vorher: Vorbereitung vor Ort prüfen.
5. In der Aufgabe „Kondolenzlisten erstellen“ steht die manuelle Aktion **Deckblatt und Kondolenzliste erzeugen** bereit. Sie erzeugt das Paket aus `KONDOLENZLISTE_DECKBLATT` und `KONDOLENZLISTE_LISTE` in `05 Trauerdruck`.
6. Die Platzhalter `{{funeral_event.date}}`, `{{funeral_event.time}}`, `{{funeral_event.end_time}}`, `{{funeral_event.location}}`, `{{funeral_event.category}}` und `{{funeral_event.external_participants}}` werden aus dem verknüpften Termin befüllt.
7. Nach fachlicher Prüfung werden die Dokumente über den bestehenden Dokumentstatus finalisiert. Diese Automatisierung versendet weder E-Mails noch Dokumente.

## Terminänderung und Absage

- Eine bestätigte Trauerfeier wird bei fachlich relevanter Änderung auf **Geändert – Folgeaufgaben prüfen** gesetzt. Bereits erzeugte Dokumente behalten den gespeicherten Termin-Snapshot und werden nicht stillschweigend überschrieben.
- Die vorhandenen terminabhängigen Aufgaben bleiben erhalten; ihre bereits ausgeführten manuellen Folgeaktionen werden als überholt markiert und dadurch erneut aktiv. Nach erneuter Bestätigung kann das Kondolenzlisten-Paket kontrolliert mit den neuen Termindaten neu erzeugt werden. Die automatischen Ausgangsaufgaben werden nicht doppelt angelegt.
- Bei **Abgesagt** werden vorhandene Aufgaben nicht automatisch gelöscht. Sie bleiben aus Revisionsgründen erhalten und müssen fachlich storniert beziehungsweise erledigt werden.

## Einrichtung der beiden DOCX-Dateien

Im Nextcloud-Ordner `Bestatter/Vorlagen` werden standardmäßig folgende Dateinamen erwartet:

- `KONDOLENZLISTE_DECKBLATT.docx`
- `KONDOLENZLISTE.docx`

Weichen die vorhandenen Dateinamen ab, werden sie unter **Customizing → Dokumentvorlagen** bei den Schlüsseln `KONDOLENZLISTE_DECKBLATT` und `KONDOLENZLISTE_LISTE` ausgewählt. In den DOCX-Dateien können die oben genannten Platzhalter direkt verwendet werden. Der Feldkatalog im Customizing dokumentiert sie zusätzlich als CSV.

## Technische Sicherungen

- Auslöser: `SCHEDULE` + `CONFIRMED`, eingeschränkt auf `TRAUERFEIER_1` und `TRAUERFEIER_2`.
- Idempotenz: eindeutiger Workflow-Lauf pro Termin-ID, Workflow-ID und Aktionsschlüssel.
- Rückverfolgbarkeit: Folgeaufgaben enthalten `sourceScheduleId`; Dokumente zusätzlich einen Terminsnapshot.
- Nextcloud Calendar: Terminvorlagen-Schlüssel und fachlicher Status werden als eigene iCalendar-X-Felder synchronisiert.
- Kalenderabgleich: bestätigte Termine werden nach erfolgreichem Sync erneut gegen die Workflow-Claims geprüft; nur fehlende Aktionen werden ausgeführt.
