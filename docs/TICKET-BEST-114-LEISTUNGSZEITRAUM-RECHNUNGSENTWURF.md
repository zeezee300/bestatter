# BEST-114 – Leistungszeitraum in Rechnungsentwürfen vorbelegen und bearbeiten

## Einordnung

- **Art:** Korrektur
- **Priorität:** Muss
- **Aufwand:** S
- **Betroffene Bereiche:** Fallakte → Finanzen, Teil-/Schlussrechnung, Rechnungsprüfung, Rechnungsdokument, Audit-Protokoll
- **Ausgangsversion:** 0.57.0
- **Umsetzungsstand:** offen

## Fehlerbild

Beim Erzeugen des Rechnungsdokuments wird geprüft, ob `servicePeriodFrom` und `servicePeriodTo` gesetzt sind. Fehlt eines der Daten, erscheint die Meldung:

> Für eine gesetzeskonforme Rechnung muss der vollständige Leistungszeitraum angegeben werden.

Die beiden Felder **„Leistungszeitraum von“** und **„Leistungszeitraum bis“** werden bislang ausschließlich im Dialog zur erstmaligen Anlage einer Teil- oder Schlussrechnung angeboten. Für eine bereits angelegte Rechnung gibt es weder eine Bearbeitungsaktion in der Oberfläche noch eine fachlich abgesicherte Aktualisierungsschnittstelle. Die Meldung beschreibt daher zwar einen gültigen Prüfungsfehler, führt den Benutzer aber in eine Sackgasse. Als einziger Ausweg bliebe das fachlich unnötige Stornieren und erneute Anlegen der Rechnung.

## Ziel

Der Leistungszeitraum wird bei der Rechnungsanlage sinnvoll vorbelegt und bleibt bearbeitbar, solange die Rechnung ein Entwurf ist. Eine unvollständige oder fehlerhafte Rechnung kann ohne Storno korrigiert und erneut geprüft werden.

## Fachliche Festlegung zur Vorbelegung

- **Leistungszeitraum von:** Auftragsdatum beziehungsweise Datum des Auftragseingangs aus den Auftragskopfdaten (`order_date`).
- **Leistungszeitraum bis:** aktuelles Datum in der lokalen Zeitzone der Anwendung beziehungsweise Niederlassung.
- Die Vorbelegung ist ein Vorschlag und kann vor dem Speichern geändert werden.
- Ist kein gültiges Auftragsdatum vorhanden, bleibt das Von-Datum leer. Das Feld wird als Pflichtangabe hervorgehoben; ein Fallanlage-, Sterbe- oder sonstiges Ersatzdatum darf nicht stillschweigend verwendet werden.
- Das aktuelle Datum wird beim Öffnen des Anlagedialogs bestimmt und nicht später unbemerkt verändert.
- Bei einer Schlussrechnung kann der Benutzer den vorbelegten Zeitraum an den tatsächlichen Abschluss der Leistungserbringung anpassen.

## Vorgeschlagene Bedienung

1. Beim Öffnen von **„Teilrechnung auswählen“** oder **„Schlussrechnung vorbereiten“** sind beide Datumsfelder sichtbar und gemäß der fachlichen Festlegung vorbelegt.
2. In der Rechnungsübersicht erhält eine Rechnung im Status `ENTWURF` die Aktion **„Entwurf bearbeiten“**.
3. Der Bearbeitungsdialog zeigt mindestens Rechnungsnummer, Rechnungsart und die beiden Datumsfelder. Er macht deutlich, dass Positionen, Mengen und Summen durch diese Korrektur nicht verändert werden.
4. Bei einer Rechnung im Status `PRUEFUNG` bleibt zunächst die vorhandene Aktion **„Zurück zum Entwurf“** erforderlich. Danach steht **„Entwurf bearbeiten“** zur Verfügung.
5. Eine Prüfungsmeldung wegen eines fehlenden Leistungszeitraums verweist unmittelbar auf die notwendige Aktion und lautet beispielsweise: **„Leistungszeitraum unvollständig. Rechnung zum Entwurf zurückführen und Leistungszeitraum bearbeiten.“**
6. Finale, bezahlte oder stornierte Rechnungen sind über diese Funktion nicht änderbar.

## Validierungs- und Schutzregeln

- Beginn und Ende sind Pflichtangaben, bevor eine Rechnung von `ENTWURF` nach `PRUEFUNG` wechseln oder ein Rechnungsdokument erzeugt werden darf.
- Beide Werte müssen gültige Kalenderdaten sein.
- `Leistungszeitraum von` darf nicht nach `Leistungszeitraum bis` liegen.
- Die Prüfung erfolgt in der Oberfläche und nochmals serverseitig innerhalb der schreibenden Operation.
- Die Aktualisierung ist ausschließlich im Status `ENTWURF` zulässig; parallele Statusänderungen müssen erkannt und mit einer verständlichen Konfliktmeldung abgewiesen werden.
- Die Änderung des Leistungszeitraums verändert weder Rechnungsnummer und Rechnungsart noch Positionssnapshot, Mengen, Steuersätze oder Summen.
- Jede Änderung eines bestehenden Entwurfs protokolliert alten und neuen Zeitraum, Benutzer und Zeitpunkt.

## Technischer Umsetzungsvorschlag

1. Im Rechnungsanlagedialog `servicePeriodFrom` aus `masterData.order_date` und `servicePeriodTo` aus dem aktuellen lokalen Datum vorbelegen.
2. Einen autorisierten Aktualisierungsendpunkt für Rechnungsentwürfe ergänzen, der ausschließlich die ausdrücklich freigegebenen Entwurfsfelder übernimmt und den Status unmittelbar vor dem Schreiben erneut prüft.
3. Im kaufmännischen Service eine zentrale Methode zur Datumsnormalisierung und Zeitraumvalidierung verwenden, damit Anlage, Aktualisierung, Statuswechsel und Dokumenterzeugung dieselben Regeln anwenden.
4. In der Rechnungsübersicht die Aktion **„Entwurf bearbeiten“** nur für `ENTWURF` anzeigen und nach erfolgreichem Speichern Übersicht sowie Prüfstatus neu laden.
5. Den Audit-Eintrag mit Rechnungs-ID/-nummer sowie Alt-/Neuwerten erzeugen. Leere oder unveränderte Aktualisierungen dürfen keinen irreführenden Änderungseintrag anlegen.
6. Die bestehende Schutzprüfung in der Dokumenterzeugung als letzte Sicherheitsstufe beibehalten, ihre Meldung aber um den konkreten Korrekturweg ergänzen.

## Akzeptanzkriterien

1. Bei einer neuen Teilrechnung ist der Beginn mit dem vorhandenen Auftragsdatum und das Ende mit dem aktuellen lokalen Datum vorbelegt.
2. Bei einer neuen Schlussrechnung gilt dieselbe Vorbelegung; beide Werte können vor Anlage geändert werden.
3. Fehlt das Auftragsdatum, bleibt der Beginn leer und wird als Pflichtangabe angezeigt; es wird kein anderes Falldatum als Ersatz übernommen.
4. Eine Rechnung im Status `ENTWURF` kann geöffnet und ihr Leistungszeitraum ohne Storno geändert werden.
5. Eine Rechnung im Status `PRUEFUNG` kann nach **„Zurück zum Entwurf“** bearbeitet werden; in `PRUEFUNG` selbst ist keine verdeckte Änderung möglich.
6. Ein fehlendes Datum, ein ungültiges Datum oder ein Ende vor dem Beginn verhindert serverseitig Speicherung beziehungsweise Statuswechsel mit einer feldbezogenen Meldung.
7. Die Korrektur lässt Rechnungsnummer, Positionen, Mengen, Preise, Steuern und Summen unverändert und wird mit Altwert, Neuwert, Benutzer und Zeitpunkt auditiert.
8. Nach der Korrektur kann die Rechnung erneut in die Prüfung überführt und das Rechnungsdokument ohne die bisherige Fehlermeldung erzeugt werden.
9. Finale, bezahlte und stornierte Rechnungen können weder über die Oberfläche noch über einen direkten API-Aufruf geändert werden.
10. Die Bearbeitung ist per Tastatur erreichbar; Fokus und Fehlermeldungen werden verständlich an den betroffenen Datumsfeldern ausgegeben.

## Testfälle

1. Auftrag mit Auftragsdatum öffnen und Teilrechnung anlegen: Vorbelegung entspricht Auftragsdatum bis heutigem Datum.
2. Schlussrechnung anlegen und beide Vorbelegungen manuell ändern: Gespeicherte Werte entsprechen der Eingabe.
3. Auftrag ohne Auftragsdatum verwenden: Beginn bleibt leer, Anlage beziehungsweise Prüfung wird mit konkretem Feldhinweis verhindert.
4. Bestehenden Entwurf ohne Leistungszeitraum bearbeiten: vollständigen Zeitraum speichern, erneut prüfen und Rechnungsdokument erfolgreich erzeugen.
5. Rechnung aus `PRUEFUNG` zurückführen, Zeitraum ändern und erneut nach `PRUEFUNG` überführen.
6. Zeitraum mit Beginn nach Ende speichern: Oberfläche und direkter API-Aufruf werden abgewiesen.
7. Bearbeitungsversuch für eine finale, bezahlte oder stornierte Rechnung: Aktion fehlt in der Oberfläche und Server antwortet ablehnend.
8. Vor und nach der Zeitraumkorrektur Positionssnapshot und Summen vergleichen: keine Abweichung; genau ein nachvollziehbarer Audit-Eintrag ist vorhanden.

## Nicht Bestandteil

- Änderung bereits finalisierter Rechnungsdokumente.
- Automatische Ableitung des Leistungszeitraums aus einzelnen Leistungsdaten.
- Storno, Gutschrift oder Neuerstellung einer bereits finalen Rechnung.
