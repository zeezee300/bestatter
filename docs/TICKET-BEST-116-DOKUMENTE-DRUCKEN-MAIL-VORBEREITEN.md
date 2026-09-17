# BEST-116 – Dokumente aus den Übersichten drucken und per E-Mail vorbereiten

## Einordnung

- **Priorität:** Soll
- **Aufwand:** M
- **Betroffene Bereiche:** Dokumentenübersicht, Fallakte → Dokumente, Nextcloud-Dateiöffnung, Bedienhinweise
- **Ausgangsversion:** 0.59.1
- **Zielversion:** 0.60.2
- **Status:** in Version 0.60.2 umgesetzt und im installierten Testsystem browserseitig abgenommen. Der geschützte Dateiabruf erzeugt keine CSRF-Meldung mehr; Druck- und Mailaktionen sind korrekt sichtbar. Die abschließende Auswahl eines lokalen Mailprogramms bleibt eine betriebssystem- und browserabhängige manuelle Arbeitsplatzprüfung.

## Bewertung und Codeabgleich

Die Anforderung ist fachlich sinnvoll, weil erzeugte und direkt abgelegte Dokumente bisher nur geöffnet oder in einer schreibgeschützten Vorschau betrachtet werden können. Die beiden zentralen Übersichten verwenden bereits `documentOutputRow(item)`. Auch die separate Dateiliste der Fallakte wird mit dieser gemeinsamen Zeile vereinheitlicht, damit Aktionen und Berechtigungen nicht doppelt implementiert werden.

Ein serverseitiger Mailversand ist ausdrücklich nicht Bestandteil dieses Tickets. Die App lädt die ausgewählte Datei über einen authentifizierten, fallgebundenen Leseendpunkt und übergibt sie mit der Web Share API an den Systemdialog. Dort kann der Benutzer ein lokal installiertes Mailprogramm wählen; die neue Nachricht enthält den Anhang, während Empfänger, Betreff und Text bewusst leer bleiben und manuell ergänzt werden. Unterstützen Browser, Betriebssystem oder Dateityp diese Übergabe nicht, öffnet die App die Datei und eine leere Nachricht über `mailto:` mit einem verständlichen Hinweis zum manuellen Anhängen. Diese lokale Übergabe speichert keine Empfängeradresse, setzt keinen Versandstatus und täuscht keinen vollautomatischen Versand vor.

Die Abgrenzung zu **OP-002 „Automatischer E-Mail-Versand“** bleibt damit eindeutig: BEST-116 übergibt eine Datei ausschließlich an eine vom Benutzer gewählte lokale Anwendung. OP-002 umfasst später Empfängerprüfung, Absenderkonto, Freigabe, serverseitiges Anhängen, tatsächlichen Versand, Wiederholschutz und revisionsnahe Protokollierung.

## Abnahmebefund 0.60.1

Nach der erfolgreichen Installation waren die neuen Aktionen erst in einer frisch geöffneten Browserinstanz sichtbar; der zuvor offene Tab verwendete noch alte Frontend-Assets. Die zentrale Dokumentübersicht zeigte anschließend „Drucken“ nur für vorhandene PDF-Ausgaben und „Mit Mailprogramm teilen“ für alle Dateien korrekt an. Beim ersten realen Dateiabruf antwortete Nextcloud jedoch mit „CSRF check failed“, weil weder der Fetch-Aufruf noch der unsichtbare Druckrahmen das Nextcloud-Requesttoken übermittelten. Patch 0.60.2 ergänzt den Token als Request-Header beziehungsweise URL-Parameter, ohne die Fall- und Dateiberechtigungsprüfung zu lockern.

Direktes Drucken wird nur für nachweislich vorhandene PDFs angeboten. DOCX- oder sonstige Dateien müssen zuerst geöffnet beziehungsweise als PDF erzeugt werden. Ein authentifizierter, nur lesender Endpunkt liefert ausschließlich PDFs aus dem zugehörigen Fallordner mit Inline-Anzeige und ohne Zwischenspeicherung aus; damit werden keine fremden Datei-IDs als Druckquelle akzeptiert. Die PDF wird in einem unsichtbaren Druckrahmen geladen. Falls der Browser den direkten Druck verhindert, öffnet die Anwendung die PDF in Nextcloud und weist auf `Strg+P` beziehungsweise `Cmd+P` hin.

## Abnahmebefund 0.60.2

Die installierte Version 0.60.2 wurde am 16.09.2026 in der zentralen Dokumentübersicht von Nextcloud 34.0.3.2 geprüft. Es wurden 24 Druckaktionen für vorhandene PDF-Ausgaben und 26 Mailaktionen für vorhandene Dateien angezeigt. Der JSON-Abschlussnachweis erhielt erwartungsgemäß keinen Druckbutton, konnte aber für die Übergabe an ein lokales Programm ausgewählt werden. Damit ist die Trennung zwischen druckbarer PDF und allgemein übergebbarer Datei in den geprüften Daten korrekt.

Der Druckversuch für die Rechnung des Falls `2026-0003` löste keine CSRF-Meldung aus. Da der eingebettete In-App-Browser keinen direkten System-Druckdialog bereitstellt, griff die vorgesehene Rückfallebene: Die korrekte PDF wurde in Nextcloud geöffnet und der Hinweis auf `Strg+P` beziehungsweise `Cmd+P` angezeigt. Auch die Aktion „Mit Mailprogramm teilen“ erzeugte weder eine CSRF-Meldung noch einen Fehler der Bestatter-App. Der native Freigabe- beziehungsweise Mailprogrammdialog ist im In-App-Browser nicht auslesbar; die Auswahl des lokalen Mailprogramms und die sichtbare Anhangsprüfung sind deshalb als arbeitsplatzabhängiger manueller Test in Edge oder Chrome mit eingerichtetem Standard-Mailprogramm durchzuführen. Bei der Abnahme wurde weder gedruckt noch eine E-Mail versendet.

## Akzeptanzkriterien

1. Hauptübersicht und Fallakte verwenden dieselbe Ausgabezeile und dieselben Aktionskomponenten.
2. Jede Zeile mit einer vorhandenen Datei bietet **„Mit Mailprogramm teilen“** an; Empfänger, Betreff und Text bleiben immer leer.
3. **„Drucken“** wird nur bei einer tatsächlichen PDF-Datei angeboten, nicht bei reinen DOCX-/ODT-/Office-Dateien.
4. Der Druckversuch öffnet den Browser-Druckdialog aus einer isolierten PDF-Ansicht; bei Fehlschlag wird die PDF geöffnet und eine verständliche Ausweichanweisung angezeigt.
5. Bei unterstützter Web Share API wird die geschützte Datei als echter Anhang an den Systemdialog übergeben; das lokale Mailprogramm wird vom Benutzer als Ziel gewählt.
6. Bei fehlender Unterstützung werden Datei und leere E-Mail geöffnet und die Oberfläche weist ausdrücklich auf das manuelle Anhängen hin; ein Versand wird nie protokolliert oder behauptet.
7. Finale, unterschriebene oder versendete Dokumente behalten die beiden Ausgabeaktionen, sofern eine Datei beziehungsweise PDF vorhanden ist; ihr Unveränderlichkeitsschutz bleibt bestehen.
8. Fehlende Datei-IDs erzeugen keine Aktionsschaltflächen. Titel und Fallnummer werden als HTML-Attribute sicher maskiert.
9. Druck- und Mailaktionen verwenden frei verfügbare Tabler-Symbole mit zugänglichen Beschriftungen und sichtbaren Texten.
10. Dokumenterzeugung, Vorlagen, Speicherung, Versionierung und Abmeldungsprozesse bleiben unverändert.

## Testfälle

- Finale Ausgabe mit DOCX und PDF: Öffnen, Drucken und Mailvorbereitung sichtbar.
- Bearbeitbarer DOCX-Entwurf ohne PDF: Bearbeiten/Öffnen und Mailvorbereitung sichtbar, Drucken nicht sichtbar.
- Direkt abgelegte PDF-Datei: Vorschau, Öffnen, Drucken und Mailvorbereitung sichtbar.
- Direkt abgelegte Office-Datei: Öffnen und Mailvorbereitung sichtbar, Drucken nicht sichtbar.
- Unterstützte Web Share API: Datei wird als `File` übergeben; Empfänger, Betreff und Nachrichtentext werden nicht vorbelegt.
- Nicht unterstützte Web Share API oder Dateityp: Datei und vollständig leere `mailto:`-Nachricht werden geöffnet; Hinweis zum manuellen Anhang erscheint.
- Gemeinsame Dateizeile in Hauptübersicht und Fallakte sowie unveränderte Status-/Schreibschutzregeln.

## Abgrenzung und Folgeoption

Automatischer Versand mit Empfängervorschlag, serverseitigem Dateianhang, Versandprotokoll, Einwilligungsprüfung und Berechtigungskonzept bleibt OP-002 und erfordert einen eigenen Backend-Prozess. Ein erfolgreicher Aufruf des Systemdialogs ist ausdrücklich kein Versandnachweis.
