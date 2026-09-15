# BEST-115 – Nebenaufträge übersichtlich führen, suchen und mit dem Fallabschluss verzahnen

## Einordnung

- **Priorität:** Muss
- **Aufwand:** L
- **Betroffene Bereiche:** Fallakte → Übersicht/Nebenaufträge, Positionsdaten, Finanzen, Fallliste, Fallsuche, Fallstatus, Vollständigkeitsprüfung, Dashboard
- **Ausgangsversion:** 0.58.2
- **Zielversion:** 0.59.0
- **Status:** in Version 0.59.0 umgesetzt; automatisierte Regressionstests bestanden, reale Fachabnahme nach Installation auf dem Testsystem offen

## Umsetzungsstand 0.59.0

Die kompakte Nebenauftragsnavigation, der durchgängige Auftraggeberkontext, die hierarchische Fallübersicht, die Nebenauftragssuche und -filterung sowie die serverseitige Fallabschlusssperre sind umgesetzt. Das Releasepaket wurde als schlanke Laufzeit-ZIP erzeugt; Entwicklungsdokumente, Tests und Testdaten sind nicht enthalten. Die lokale Testsammlung umfasst 103 bestandene JavaScript-/statische Regressionstests. Eine PHP-Laufzeit steht lokal nicht zur Verfügung. Migration, PHP-Laufzeit und die Abnahme mit zwei offenen Nebenaufträgen müssen deshalb unmittelbar nach der serverseitigen Installation des Pakets im verbundenen Nextcloud-Testsystem geprüft werden.

## Anlass und Codeabgleich

Die Nebenauftragsfunktion ist technisch bereits vorhanden, führt den Benutzer aber noch nicht durchgängig im Kontext eines Nebenauftrags:

- `sideOrdersPanel()` rendert das Anlageformular mit `open`, sobald noch kein Nebenauftrag vorhanden ist. Dadurch wird die leere Fallübersicht unnötig groß und die Anlage wirkt wie ein Pflichtschritt.
- Vorhandene Nebenaufträge werden als vollständig sichtbare Karten statt als kompakte, auswählbare Übersicht dargestellt.
- Die Aktion **„Positionen“** setzt `activeSideOrderId`, wechselt jedoch gleichzeitig auf den allgemeinen Fallreiter `services`. **„Finanzen“** verhält sich entsprechend. Die sichtbare Reiternavigation vermittelt dadurch fälschlich den Eindruck, es würden die Leistungen oder Finanzen des Hauptauftrags bearbeitet.
- In der Positionserfassung wird nur die Nebenauftragsnummer, nicht der Auftraggeber angezeigt. Der Benutzer muss deshalb den Bearbeitungskontext aus dem Gedächtnis ableiten.
- Die Fallliste und `CaseService::searchCases()` berücksichtigen nur Fallnummer, verstorbene Person, Fallstatus und Niederlassung. Nebenauftragsnummer, Nebenauftraggeber und Nebenauftragsstatus sind weder such- noch filterbar.
- `CaseService::updateMasterData()` übernimmt den angeforderten Fallstatus derzeit ohne Abschlussprüfung der Nebenaufträge. Ein Fall kann daher fachlich abgeschlossen erscheinen, obwohl ein Nebenauftrag noch im Entwurf oder beauftragt und damit insbesondere noch nicht abschließend abgerechnet ist.
- Positiv vorhanden: Ein Nebenauftrag kann serverseitig erst nach Freigabe einer Schlussrechnung auf `ABGESCHLOSSEN` gesetzt werden. Diese Regel kann als Grundlage für die Fallabschlussprüfung verwendet werden.

Die Sichtprüfung im Testsystem bestätigt insbesondere den automatisch geöffneten Leerzustand und die Trennung zwischen dem Reiter **„Nebenaufträge“** und den allgemeinen Reitern **„Leistungen“** und **„Finanzen“**.

## Fachliche Bewertung und Zielbild

Nebenaufträge bleiben fachlich eigenständige kaufmännische Vorgänge, werden aber immer unter dem zugehörigen Sterbefall geführt. Der Fallstatus wird nicht automatisch aus einem einzelnen Nebenauftrag überschrieben. Stattdessen erhält der Fall einen abgeleiteten Nebenauftrags-/Abschlussindikator und eine serverseitige Abschlussprüfung.

Die empfohlene Bedienlogik lautet:

1. Der Hauptreiter **„Nebenaufträge“** bleibt aktiv, solange ein Nebenauftrag bearbeitet wird.
2. Innerhalb dieses Reiters gibt es eine kompakte Liste und – nach Auswahl – einen Detailbereich mit den Unteransichten **„Kopfdaten“**, **„Positionen“** und **„Finanzen / Abrechnung“**.
3. Der allgemeine Fallreiter **„Leistungen“** bleibt ausschließlich dem Hauptauftrag vorbehalten. Damit ist jederzeit eindeutig, welchem Auftrag Positionen zugeordnet werden.
4. Die Fallliste zeigt Nebenaufträge hierarchisch unter ihrem Fall, ohne für jeden Nebenauftrag einen eigenständigen Fall vorzutäuschen.
5. Ein Fall darf erst abgeschlossen werden, wenn alle nicht stornierten Nebenaufträge abgeschlossen sind. Allein das Abschließen der Nebenaufträge schließt den Fall jedoch nicht automatisch ab, weil weitere fallbezogene Aufgaben offen sein können.

## Vorgeschlagene Bedienung

### 1. Nebenauftragsbereich in der Fallakte

- Ist kein Nebenauftrag vorhanden, bleibt **„Neuen Nebenauftrag erfassen“** geschlossen. Sichtbar sind nur ein kurzer Leerhinweis und eine kompakte Schaltfläche beziehungsweise aufklappbare Zeile **„Nebenauftrag anlegen“**.
- Nach bewusstem Öffnen erscheint das Anlageformular. Abbrechen oder erneutes Anklicken schließt es wieder, ohne Daten zu speichern.
- Sind Nebenaufträge vorhanden, werden sie zunächst kompakt mit mindestens folgenden Spalten aufgelistet: **Auftragsnummer**, **Auftraggeber**, **Status**. Ergänzend sinnvoll sind **„Benötigt bis“** und ein kurzer Bearbeitungshinweis wie **„Abrechnung offen“**.
- Kein Detailformular wird allein durch das Öffnen des Reiters automatisch aufgeklappt. Eine vom Benutzer ausgewählte Zeile öffnet genau einen Nebenauftrag; optional darf diese Auswahl nur für die laufende Sitzung gemerkt werden.
- Nach Neuanlage wird genau der neu erzeugte Nebenauftrag ausgewählt und dessen Kopfdaten werden angezeigt.
- Beim Anklicken einer Zeile erscheint unterhalb oder rechts neben der Liste der Auftragskopf mit Auftraggeber, Anschrift, Beziehung, Kontaktdaten, Termin und Status. Im Entwurf ist die Bearbeitung dort möglich.
- Eine stornierte Zeile bleibt sichtbar, ist klar gekennzeichnet und schreibgeschützt.

### 2. Positionen und Finanzen im richtigen Kontext

- Der Hauptreiter **„Nebenaufträge“** bleibt aktiv. Die Auswahl eines Nebenauftrags steuert eine interne Unteransicht `header`, `positions` oder `finances`.
- In **„Positionen“** steht im Kopf sichtbar: `Nebenauftrag {Nummer} · {Vorname Nachname}`. Optional folgt die Beziehung zum Verstorbenen als Sekundärinformation.
- In **„Finanzen / Abrechnung“** wird derselbe Kontextkopf verwendet.
- Ein deutlich sichtbarer Rückweg **„Zur Nebenauftragsübersicht“** setzt nur die interne Auswahl zurück und führt nicht in die allgemeine Fallübersicht.
- Beim Wechsel zwischen Kopfdaten, Positionen und Finanzen bleiben ausgewählter Nebenauftrag und Scrollposition nachvollziehbar erhalten.
- Der allgemeine Reiter **„Leistungen“** zeigt weiterhin ausschließlich Positionen des Hauptauftrags (`sideOrderId = 0`).

### 3. Nebenaufträge in Fallübersicht und Suche

Die Fallliste bleibt eine Liste von Sterbefällen. Nebenaufträge werden als untergeordnete Datensätze eingeblendet:

- Eine Fallzeile mit Nebenaufträgen erhält einen Aufklapp-Pfeil und einen Zusatz, zum Beispiel **„2 Nebenaufträge · 1 offen“**.
- Aufgeklappt erscheinen eingerückte Nebenauftragszeilen mit Nummer, Auftraggeber, Status und gegebenenfalls offenem Bearbeitungsschritt.
- Ein Klick auf die Fallzeile öffnet wie bisher die Fallübersicht. Ein Klick auf eine Nebenauftragszeile öffnet direkt `Fallakte → Nebenaufträge` mit ausgewähltem Nebenauftrag und zunächst dessen Kopfdaten.
- Auf kleinen Bildschirmen werden die untergeordneten Zeilen als kompakte Karten statt als breiter Tabellenanhang dargestellt.
- Die bestehende Suche findet zusätzlich Nebenauftragsnummer, Vorname/Nachname des Nebenauftraggebers und Nebenauftragsstatus. Ein Treffer zeigt weiterhin den zugehörigen Fall und markiert den passenden Nebenauftrag als Suchtreffer.
- Die Suche arbeitet fallbezogen: Ein Fall erscheint trotz mehrerer passender Nebenaufträge nur einmal. Pagination und Gesamtzahl zählen Fälle, nicht Trefferzeilen aus einem Datenbank-Join.
- Ergänzt werden die gespeicherte Ansicht **„Fälle mit offenen Nebenaufträgen“** und ein Filter **„Nebenaufträge“** mit mindestens `Alle`, `Vorhanden`, `Offen`, `Abrechnung offen`, `Erledigt` und `Keine`.
- `Offen` umfasst `ENTWURF` und `BEAUFTRAGT`; `Abrechnung offen` umfasst beauftragte Nebenaufträge ohne freigegebene Schlussrechnung; `Erledigt` umfasst `ABGESCHLOSSEN` und optional separat filterbare `STORNIERT`-Datensätze.

### 4. Korrelation von Fall- und Nebenauftragsstatus

- `ENTWURF` und `BEAUFTRAGT` eines nicht stornierten Nebenauftrags blockieren den Wechsel des Falls auf `ABGESCHLOSSEN`.
- `ABGESCHLOSSEN` und `STORNIERT` blockieren den Fallabschluss nicht.
- Die vorhandene Regel bleibt bestehen: Ein beauftragter Nebenauftrag kann erst nach Freigabe seiner Schlussrechnung abgeschlossen werden. Dadurch wird eine noch offene Abrechnung mittelbar zum Fallabschluss-Hindernis.
- Beim Versuch, den Fall abzuschließen, wird serverseitig erneut geprüft. Die Meldung nennt jeden blockierenden Nebenauftrag mit Nummer, Auftraggeber, Status und Grund, zum Beispiel **„2026-0007-N1 · Max Mustermann · Beauftragt · Schlussrechnung noch nicht freigegeben“**.
- Sind alle Nebenaufträge erledigt, wird der Fall nicht automatisch abgeschlossen. Die übrigen Abschlussregeln des Falls müssen weiterhin erfüllt und der Abschluss bewusst bestätigt werden.
- In einem bereits `ABGESCHLOSSENEN` oder `STORNIERTEN` Fall darf kein neuer Nebenauftrag angelegt werden. Der Fall muss mit Berechtigung und Begründung zunächst wieder geöffnet werden.
- Für Bestandsfälle, die bereits abgeschlossen sind und offene Nebenaufträge enthalten, wird kein stiller Statuswechsel vorgenommen. Eine Diagnose kennzeichnet sie als inkonsistent; sie müssen kontrolliert wieder geöffnet oder fachlich bereinigt werden.
- Fallübersicht, Fallliste und Dashboard zeigen neben dem eigentlichen Fallstatus einen abgeleiteten Hinweis, beispielsweise **„Nebenaufträge: 2 offen“**. Fallstatus und Nebenauftragsstatus bleiben getrennte, verständliche Begriffe.

## Technischer Umsetzungsvorschlag

1. Im Frontend `state.activeSideOrderId` um eine interne Ansicht, zum Beispiel `state.sideOrderView = 'list' | 'header' | 'positions' | 'finances'`, ergänzen. Bei Nebenauftragsaktionen `caseTab = 'side-orders'` beibehalten.
2. `sideOrdersPanel()` in eine kompakte Liste, einen selektierten Detailbereich und ein standardmäßig geschlossenes Anlage-`details` aufteilen. Für Zeilenauswahl echte Buttons beziehungsweise Disclosure-Controls mit `aria-expanded` und `aria-controls` verwenden.
3. Die bestehende Positions- und Finanzkomponente weiterhin wiederverwenden, aber innerhalb des Nebenauftragsbereichs rendern. Der Scope wird unverändert serverseitig über `sideOrderId` getrennt. Kontextkopf um Auftraggeber ergänzen.
4. Eine serverseitige Projektion für Nebenauftragszusammenfassungen bereitstellen, zum Beispiel `sideOrderCount`, `openSideOrderCount`, `billingOpenSideOrderCount` und eine begrenzte Liste `sideOrders`. Keine N+1-Abfragen je Fallzeile erzeugen.
5. `CaseService::searchCases()` mit `EXISTS`-Unterabfragen oder einer gruppierten Projektion erweitern. Notwendige Indizes für `case_id`, `side_order_number`, `status`, `last_name` und gegebenenfalls normalisierte Suchfelder prüfen. Die Zählabfrage muss dieselben Filterbedingungen verwenden.
6. Eine zentrale `CaseClosureService`- beziehungsweise `CaseService::assertClosable()`-Prüfung einführen und auf jedem serverseitigen Statuspfad nach `ABGESCHLOSSEN` verwenden. Das betrifft neben der Stammdaten-API auch Workflow-Aktionen; ein direkter API-Aufruf darf die Regel nicht umgehen.
7. `OperationalService::completeness()` um eine Abschluss-/Nebenauftragsprüfung ergänzen, ohne die allgemeine Datenerfassungsquote durch stornierte Nebenaufträge zu verfälschen.
8. Legacy-Inkonsistenzen im Betriebs-Cockpit rein lesend ausweisen. Eine Reparatur erfolgt nur als gesonderte, auditierte Benutzeraktion.
9. Bestehende Berechtigungs-, Vertrags-, Rechnungs- und Auditregeln unverändert anwenden. Es werden keine personenbezogenen Nebenauftragsdaten außerhalb der bereits berechtigten Fallansicht ausgegeben.

## API-/Datenvorschlag

Für Falllisten kann jedes Fallobjekt folgende zusätzliche Projektion enthalten:

```json
{
  "sideOrderSummary": {
    "total": 2,
    "open": 1,
    "billingOpen": 1,
    "items": [
      {
        "id": 17,
        "sideOrderNumber": "2026-0007-N1",
        "customerName": "Max Mustermann",
        "status": "BEAUFTRAGT",
        "nextAction": "Schlussrechnung freigeben"
      }
    ]
  }
}
```

Die Liste darf für hohe Fallzahlen zunächst nur eine begrenzte Vorschau enthalten. Beim Aufklappen kann bei Bedarf ein vorhandener beziehungsweise erweiterter Nebenauftragsendpunkt nachladen. Die Abschlussprüfung arbeitet stets direkt auf dem aktuellen Datenbankstand und nicht auf dieser Projektion.

## Akzeptanzkriterien

1. Bei einem Fall ohne Nebenauftrag ist das Anlageformular nach Öffnen von Übersicht oder Reiter **„Nebenaufträge“** geschlossen; es öffnet sich nur nach bewusster Benutzeraktion.
2. Bei vorhandenen Nebenaufträgen zeigt der Reiter eine kompakte Liste mit Auftragsnummer, Auftraggeber und verständlichem Status. Details sind nicht alle gleichzeitig geöffnet.
3. Ein Klick auf einen Nebenauftrag öffnet dessen Kopfdaten. Genau ein Nebenauftrag ist ausgewählt und die Auswahl ist per Tastatur erreichbar.
4. Beim Wechsel zu Positionen bleibt der Reiter **„Nebenaufträge“** aktiv. Der Kopf zeigt Nebenauftragsnummer und Auftraggeber; der allgemeine Reiter **„Leistungen“** bleibt dem Hauptauftrag vorbehalten.
5. Positionen, Katalogsuche, Summen, Vertragsnachträge und Rechnungsdaten werden weiterhin strikt über `sideOrderId` dem ausgewählten Nebenauftrag zugeordnet. Beim Wechsel des Nebenauftrags werden keine Daten des vorherigen Auftrags angezeigt oder gespeichert.
6. Die Fallliste zeigt Anzahl und Anzahl offener Nebenaufträge. Nach Aufklappen werden diese unter dem Fall eingerückt dargestellt; ein Direktklick öffnet den korrekten Nebenauftrag.
7. Die Fallsuche findet eine Nebenauftragsnummer sowie Vor- oder Nachnamen des Nebenauftraggebers. Der zugehörige Fall erscheint nur einmal und der passende Nebenauftrag wird hervorgehoben.
8. Filter `Vorhanden`, `Offen`, `Abrechnung offen`, `Erledigt` und `Keine` liefern fachlich korrekte, paginierte Fallmengen. Die Gesamtzahl zählt Fälle.
9. Ein Fall mit einem Nebenauftrag in `ENTWURF` oder `BEAUFTRAGT` kann weder über die Oberfläche noch über direkte API- oder Workflow-Aufrufe auf `ABGESCHLOSSEN` gesetzt werden.
10. Die Abschlussmeldung nennt alle blockierenden Nebenaufträge mit Nummer, Auftraggeber, Status und konkretem offenen Schritt. Sie führt den Benutzer in den Nebenauftragsbereich.
11. Sind alle nicht stornierten Nebenaufträge abgeschlossen, darf der Fallabschluss nach Erfüllung der übrigen Fallregeln bewusst ausgeführt werden; der Fall wird nicht automatisch abgeschlossen.
12. In abgeschlossenen oder stornierten Fällen ist die Anlage eines Nebenauftrags serverseitig gesperrt. Nach kontrollierter Wiedereröffnung ist sie wieder möglich.
13. Bereits inkonsistente Bestandsfälle werden im Betriebs-Cockpit gemeldet und nicht automatisch verändert.
14. Desktop-, schmale und Tastaturbedienung funktionieren ohne abgeschnittene Aktionsbereiche. Status wird nicht ausschließlich durch Farbe vermittelt; Lade- und Fehlermeldungen sind per `aria-live` zugänglich.

## Testfälle

1. Fall ohne Nebenauftrag öffnen: Anlagebereich bleibt geschlossen; öffnen, schließen und erneut öffnen verändert keine Daten.
2. Drei Nebenaufträge in den Status `ENTWURF`, `BEAUFTRAGT` und `STORNIERT` anlegen: kompakte Liste, Sortierung, Statusbezeichnungen und genau eine Detailauswahl prüfen.
3. Nacheinander Kopfdaten und Positionen zweier Nebenaufträge öffnen: Nummer und Auftraggeber stimmen; kein Scope-Leak zum Hauptauftrag oder anderen Nebenauftrag.
4. Aus einer eingerückten Nebenauftragszeile in der Fallliste direkt öffnen: Fall, Reiter, ausgewählter Nebenauftrag und Kopfdaten sind korrekt.
5. Nach Nebenauftragsnummer sowie Vor- und Nachnamen suchen; bei mehreren Treffern im selben Fall erscheint der Fall genau einmal. Pagination und Gesamtzahl bleiben korrekt.
6. Alle Nebenauftragsfilter einzeln und kombiniert mit Fallstatus, Niederlassung und Verantwortlichem testen.
7. Fallabschluss mit Nebenauftrag im Entwurf versuchen: UI, API und Workflow müssen blockieren und denselben fachlichen Grund liefern.
8. Fallabschluss mit beauftragtem Nebenauftrag und unfertiger Rechnung versuchen: Abschluss blockiert. Nach Freigabe der Schlussrechnung Nebenauftrag abschließen und Fallprüfung wiederholen.
9. Fall mit ausschließlich abgeschlossenen/stornierten Nebenaufträgen und sonst vollständigen Daten abschließen: bewusste Bestätigung ist möglich; kein automatischer Abschluss.
10. In abgeschlossenem Fall Nebenauftrag per UI und direkter API anlegen: beide Wege werden abgewiesen und auditiert.
11. Einen künstlich inkonsistenten Bestandsfall prüfen: Cockpit meldet ihn, verändert aber keine Daten.
12. Desktop, schmale Ansicht, Tabulatorbedienung, Screenreader-Namen, Fokusführung und Statusdarstellung prüfen.
13. Lasttest mit mindestens 1.000 Fällen und mehreren Nebenaufträgen durchführen: Suche, Zählung, Filter und Aufklappen bleiben ohne N+1-Abfragen nutzbar.

## Risiken und fachliche Abstimmung

- **Begriffsklarheit:** `ABGESCHLOSSEN` beim Nebenauftrag bedeutet nach heutiger Regel „Schlussrechnung freigegeben“, nicht zwingend „vollständig bezahlt“. Falls Zahlungseingang den Fallabschluss ebenfalls blockieren soll, ist dies vor Umsetzung ausdrücklich mit der Buchhaltung festzulegen.
- **Statusautomatismen:** Ein automatisches Überschreiben des Fallstatus aus Nebenauftragsstatus wäre riskant, weil Aufgaben, Dokumente und Termine ebenfalls fallabschlussrelevant sind. Deshalb wird nur eine Sperre plus abgeleiteter Hinweis empfohlen.
- **Bestandsdaten:** Bereits abgeschlossene Fälle mit offenen Nebenaufträgen müssen sichtbar diagnostiziert und fachlich entschieden werden; eine automatische Wiedereröffnung ist nicht zulässig.
- **Performance:** Freitextsuche über personenbezogene Nebenauftragsfelder und hierarchische Ergebnislisten benötigen passende Abfragen und Indizes. Joins dürfen Fallzahlen und Pagination nicht vervielfachen.
- **Datenschutz:** Nebenauftraggeber sind personenbezogene Daten. Suchtreffer und Unterzeilen dürfen nur im bestehenden Fallberechtigungsumfang sichtbar sein.

## Nicht Bestandteil

- Eigenständige, vom Sterbefall losgelöste Nebenauftragsakten.
- Automatische Schließung eines Falls allein aufgrund erledigter Nebenaufträge.
- Vollständige Aufgabenverwaltung je Nebenauftrag; hierfür wäre eine zusätzliche Zuordnung von Aufgaben/Terminen zu `sideOrderId` als eigenes Ticket zu bewerten.
- Änderung der bestehenden steuerlichen Positions- und Fakturierungslogik.

## Realisierungs-Prompt

```text
Setze BEST-115 „Nebenaufträge übersichtlich führen, suchen und mit dem Fallabschluss verzahnen“ auf Basis des vorhandenen Bestatter-App-Codes vollständig um. Lies zuerst docs/TICKET-BEST-115-NEBENAUFTRAEGE-NAVIGATION-STATUS-SUCHE.md und gleiche jede Anforderung mit dem aktuellen Code ab. Ausgangsversion ist 0.58.2; die vorgesehene Zielversion ist 0.59.0.

Wichtige fachliche Leitplanken:
- Der Reiter „Nebenaufträge“ bleibt während der Bearbeitung eines Nebenauftrags aktiv. Verwende darin interne Ansichten für Liste, Kopfdaten, Positionen und Finanzen/Abrechnung. Der allgemeine Reiter „Leistungen“ bleibt ausschließlich dem Hauptauftrag vorbehalten.
- Zeige in Positionen und Finanzen immer Nebenauftragsnummer und Auftraggeber. Halte die Trennung über sideOrderId serverseitig unverändert strikt ein.
- Das Anlageformular ist auch ohne vorhandene Nebenaufträge standardmäßig geschlossen. Vorhandene Nebenaufträge werden kompakt mit Nummer, Auftraggeber und Status gelistet; beim Anklicken öffnet sich genau ein Auftragskopf.
- Erweitere Fallliste, Suche und Filter um hierarchisch dargestellte Nebenaufträge. Zähle und paginiere weiterhin Fälle, vermeide Duplikate und N+1-Abfragen.
- Verhindere jeden Statuswechsel eines Falls nach ABGESCHLOSSEN, solange ein nicht stornierter Nebenauftrag nicht ABGESCHLOSSEN ist. Die Prüfung muss in allen UI-, API- und Workflow-Pfaden serverseitig gelten. Gib konkrete Blocker aus.
- Lege in abgeschlossenen oder stornierten Fällen keine neuen Nebenaufträge an. Verändere inkonsistente Bestandsfälle nicht automatisch; melde sie rein lesend im Betriebs-Cockpit.
- Ein erledigter Nebenauftrag schließt den Fall niemals automatisch. Fallstatus und abgeleiteter Nebenauftragsindikator bleiben getrennt.
- Beachte bestehende Berechtigungen, Auditierung, Vertrags-/Nachtragslogik, Rechnungsregeln und CSRF-Schutz. Führe keine destruktive oder pauschale Datenmigration aus.

Erwartete Arbeitsschritte:
1. Frontendzustand und Navigation für Nebenaufträge refaktorieren und die kompakte, barrierearme Liste samt Detailansichten umsetzen.
2. Wiederverwendete Positions- und Finanzansichten im Nebenauftragsreiter rendern und Kontextkopf/Rücknavigation korrigieren.
3. Falllisten-API um aggregierte Nebenauftragsdaten sowie Suche/Filter erweitern; notwendige Indizes migrationssicher ergänzen.
4. Eine zentrale, transaktionsnahe Fallabschlussprüfung implementieren und auch aus Workflow-Statuswechseln verwenden.
5. Vollständigkeitsanzeige, Dashboard-Hinweise und Betriebs-Cockpit um den abgeleiteten Nebenauftrags-/Inkonsistenzstatus ergänzen.
6. Bestehende und neue automatisierte Tests ausführen. Ergänze Unit-/Integrationstests für Suche, Pagination, Berechtigungen, Scope-Trennung, Statussperren und Legacy-Inkonsistenzen sowie Frontendtests für Navigation, Leerzustand und Tastaturbedienung.
7. Erstelle einen echten, nicht abgeschlossenen Testfall mit mindestens zwei Nebenaufträgen unterschiedlicher Status und teste den vollständigen Ablauf im verbundenen Nextcloud-Testsystem. Schließe den Fall nicht ab, damit weitere fachliche Prüfungen möglich bleiben.
8. Aktualisiere CHANGELOG, technische Dokumentation und Versionsangaben auf 0.59.0. Erzeuge anschließend eine schlanke App-ZIP, die nur laufzeitnotwendige App-Dateien enthält; Tickets, OP-Listen, Testdaten und Testergebnisse dürfen nicht enthalten sein.

Arbeite alle 14 Akzeptanzkriterien und 13 Testfälle des Tickets nachweisbar ab. Dokumentiere verbleibende fachliche Entscheidungen – insbesondere, ob erst eine freigegebene oder bereits bezahlte Schlussrechnung einen Nebenauftrag als erledigt gelten lässt – klar und ändere diese Regel nicht ohne Freigabe der Buchhaltung.
```
