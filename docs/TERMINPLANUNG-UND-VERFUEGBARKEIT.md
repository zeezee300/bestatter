# Terminplanung und Verfügbarkeit

## Zielbild

Die Bestatter-App bleibt führend für fallbezogene Termine, Terminarten, den betrieblichen Ressourcenbedarf und die revisionsfähige Historie. Nextcloud Calendar bleibt führend für persönliche Kalendertermine und Abwesenheiten. Arbeitszeitmodelle werden installationsbezogen in der Bestatter-App oder später über ein angebundenes Personal-/Dienstplansystem gepflegt. So werden dieselben Informationen nicht an mehreren Stellen manuell erfasst.

## In 0.44 umgesetzt

- Terminarten enthalten Standarddauer, Vor- und Nachbereitungszeit sowie Mindestbedarf für Mitarbeitende, Fahrzeuge, Räume, Kapellen und Ausstattung.
- Konkrete betriebliche Ressourcen können je Terminart vorbelegt werden.
- Der Server verhindert eine Speicherung, wenn der Pflichtbedarf nicht disponiert wurde.
- Überschneidungen berücksichtigen Beginn, Dauer, Vor-/Nachlauf, zugewiesene Mitarbeitende und konfliktrelevante Ressourcen.
- Bei Konflikten werden bis zu drei freie Ersatzzeiten im 30-Minuten-Raster innerhalb des vorläufigen Dispositionsfensters 08:00–18:00 Uhr vorgeschlagen und können im Formular übernommen werden.
- Wesentliche Änderungen benötigen einen Grund. Historieneinträge zeigen Benutzer, Zeitpunkt, Grund sowie Alt-/Neu-Werte.
- `ABGESAGT` wird als iCalendar `CANCELLED` übertragen; `ERLEDIGT` bleibt ein durchgeführter, bestätigter Kalendereintrag.

## Empfohlenes Verfügbarkeitsmodell

### 1. Persönliche Soll-Arbeitszeit

Pflege unter **Administration → Personal und Verfügbarkeit** in der Bestatter-App:

- regelmäßige Wochenarbeitszeit je Mitarbeiter und Wochentag;
- optional Niederlassung und Bereitschaftsdienst;
- Gültigkeitszeitraum für wechselnde Modelle;
- individuelle Ausnahmen nur, wenn kein externes Dienstplansystem angebunden ist.

Eine universelle Arbeitszeitdefinition steht in Nextcloud Calendar nicht zuverlässig zur Verfügung. Deshalb benötigt die App hierfür eine kleine eigene Konfiguration oder später einen Import aus dem führenden Dienstplansystem.

### 2. Abwesenheiten und persönliche Belegung

Quelle sind ausgewählte Nextcloud-Kalender des Mitarbeiters. Die Verbindung erfolgt über die bereits vorhandene CalDAV-/Kalenderintegration. Für private Kalender übernimmt die Bestatter-App ausschließlich `frei/belegt`, Zeitraum und optional die Abwesenheitsart – niemals vertrauliche Titel oder Beschreibungen.

Empfohlen werden:

- persönlicher Bestatter-Kalender für betriebliche Termine;
- optional Kalender „Abwesenheiten“ für Urlaub, Krankheit und Fortbildung;
- weitere vom Benutzer oder Administrator freigegebene Kalender nur als Free/Busy-Quelle.

### 3. Fahrzeuge, Räume, Kapellen und Ausstattung

Diese Ressourcen werden unter **Customizing → Terminplanung → Ressourcen** gepflegt. Optional erhält jede Ressource später einen eigenen Nextcloud-Ressourcenkalender. Bis dahin ist die Bestatter-App für deren Buchung führend.

### 4. Entscheidungsreihenfolge

Eine Ressource oder Person gilt als nicht verfügbar, wenn mindestens eine Regel zutrifft:

1. außerhalb der gültigen Soll-Arbeitszeit;
2. Abwesenheit oder belegter freigegebener Nextcloud-Kalender;
3. Kollision mit einem Bestatter-Termin einschließlich Pufferzeiten;
4. betriebliche Sperrzeit, Wartung oder manuelle Ressourcensperre.

Eine berechtigte Übersteuerung bleibt möglich, benötigt aber einen Änderungsgrund und wird protokolliert.

## Datenschutz und Betrieb

- Kalenderinhalte werden nur für Mitglieder der konfigurierten Bestatter-Gruppe verarbeitet.
- Private Ereignisse werden ausschließlich als belegter Zeitraum ausgewertet.
- Ergebnisse dürfen kurzzeitig zwischengespeichert werden; Titel und Beschreibungen privater Termine werden nicht persistiert.
- Fällt Nextcloud Free/Busy aus, wird dies sichtbar angezeigt. Die App darf die Verfügbarkeit dann nicht irrtümlich als frei bewerten.

## Folgeausbau

1. Arbeitszeitmodelle und Bereitschaftspläne je Mitarbeiter.
2. Auswahl der für Free/Busy freigegebenen Nextcloud-Kalender.
3. CalDAV-Free/Busy-Abfrage und Abwesenheitskalender.
4. Öffnungs- und Sperrzeiten betrieblicher Ressourcen.
5. Konfigurierbare Dispositionsfenster statt des vorläufigen Fensters 08:00–18:00 Uhr.
6. Optionaler Import aus einem Personal-/Dienstplansystem.
