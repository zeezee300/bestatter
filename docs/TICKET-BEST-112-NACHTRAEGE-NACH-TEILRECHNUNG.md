# BEST-112 – Nachtragsfähigkeit nach erster Teilrechnung

## 4. Anforderungsübersicht

| ID | Anforderung | SAP-Referenz / Best Practice | Priorität |
|---|---|---|---|
| REQ-12 | Nachtragsfähigkeit der Auftragspositionen auch nach einer bereits erstellten Teilrechnung, mit Pflichtbegründung und Audit-Protokoll je Ergänzung | Fakturierungsstatus je Position statt je Beleg (VA02-Auftragsänderung/Nachtrag) | Muss |

## 5. Ticket-Übersicht

| Ticket-ID | Titel | Priorität | Aufwand | Abhängigkeit |
|---|---|---|---|---|
| BEST-112 | Nachtragsfähigkeit der Auftragspositionen nach erster Teilrechnung (SAP-analog) | Muss | M | – |

## 6. Vollständiges Ticket-Detail

### Kontext und Ist-Zustand

SAP führt den Fakturierungsstatus in der Auftragserfassung positionsbezogen. Nicht fakturierte Positionen können ergänzt oder geändert werden, obwohl andere Positionen desselben Auftrags bereits teilfakturiert sind. Fakturierte Positionen bleiben geschützt.

Die bisherige bestatter-App setzte in `CommercialStateService::state()` `amendmentsAllowed` bereits beim Vorhandensein irgendeiner nicht stornierten Rechnung auf `false`. Diese harte Belegsperre blockierte auch neue, noch nicht fakturierte Leistungen. Ein alternativer Erfassungsweg war nicht vorhanden, während Rechnungspositionen zwingend auf eine Fallleistung verweisen. Dadurch konnten nach einer Teilrechnung neu entstehende Leistungen nicht mehr ordnungsgemäß in Auftrag und Folgeabrechnung aufgenommen werden.

### Bewertung

REQ-12 ist fachlich notwendig und technisch mit moderatem Aufwand umsetzbar. Das vorhandene Modell führt `invoicedQuantityMilli`, Leistungsstatus und Abrechenbarkeit bereits je Position. Die Sperre kann daher auf die aktive Schlussrechnung und fakturierte Einzelpositionen begrenzt werden. Für `origin = NACHTRAG` ist wegen des vorhandenen String-Feldes keine Datenbankmigration erforderlich.

### Akzeptanzkriterien

1. `amendmentsAllowed` wird nicht durch irgendeine Rechnung, sondern erst durch eine aktive, nicht stornierte Schlussrechnung aufgehoben.
2. Solange ausschließlich Teilrechnungen vorliegen, können neue Auftragspositionen als Nachtrag ergänzt werden.
3. Positionen mit `invoicedQuantityMilli > 0` bleiben in ihren Vertragsdaten geschützt und können insbesondere nicht in Menge oder Preis geändert oder entfernt werden.
4. Jede Änderung nach Festschreibung benötigt weiterhin eine mindestens fünf Zeichen lange Nachtragsbegründung und erzeugt einen Audit-Eintrag `CONTRACT_AMENDMENT` mit Vorher-/Nachherstand.
5. Mit einer aktiven Schlussrechnung bleibt die gesamte Leistungsliste vollständig eingefroren.
6. Neu ergänzte Positionen werden mit `origin = NACHTRAG` gespeichert und in der Positionsübersicht als `NTR – Nachtragsposition` gekennzeichnet.
7. Neue Nachtragspositionen benötigen keinen Sonderprozess: Sobald sie erbracht und abrechenbar sind, berücksichtigt die bestehende Restmengenlogik sie in der nächsten Teil- oder Schlussrechnung.
8. Bestehende Fälle mit Teilrechnung werden ohne Datenmigration wieder nachtragsfähig, da die Freigabe aus dem aktuellen Dokument- und Rechnungsstatus berechnet wird.

### Umsetzung

- `CommercialStateService::state()` ermittelt neben der Rechnungsanzahl eine aktive Schlussrechnung und erlaubt Nachträge bis zu deren Vorliegen.
- `ArticleService::saveCaseServices()` behält den Pflichtgrund und das Audit bei, markiert neue Vertragszeilen als `NACHTRAG` und schützt fakturierte Vertragsdaten serverseitig.
- Die Positionsübersicht zeigt Nachträge als `NTR`, weist die fakturierte Menge aus und deaktiviert Vertragsfelder sowie Lösch-/Duplizieraktionen fakturierter Positionen.
- `CommercialService::selectInvoiceServices()` bleibt unverändert: Es wählt jede abrechenbare, erbrachte Restmenge aus und schließt neue Nachtragspositionen dadurch automatisch ein.

## 8. Risiko-Hinweis und fachliche Freigabe

BEST-112 lockert eine bisherige kaufmännische Gesamtsperre. Vor Produktivfreigabe ist deshalb eine ausdrückliche Abstimmung mit der Buchhaltung erforderlich. Festzulegen sind insbesondere Nachtragsbeleg/-bestätigung gegenüber dem Auftraggeber, Leistungszeitpunkt und Steuerbehandlung, Umgang mit bereits versendeten Teilrechnungen, Verantwortlichkeit für die Nachtragsfreigabe sowie die Auswertung von Nachtragspositionen. Die technische Umsetzung ersetzt diese fachliche Freigabe nicht. Bis zur Freigabe ist Version 0.56.0 nur für Test und Abnahme vorgesehen.

## Testfälle

- Auftrag ohne Rechnung: Nachtrag mit Grund möglich.
- Auftrag mit aktiver Teilrechnung: neue Position mit Grund möglich und als `NACHTRAG` gespeichert.
- Fakturierte Position: Vertragsfelder und Entfernen sind in UI und API gesperrt.
- Nicht fakturierte Bestandsposition: Änderung mit Grund möglich.
- Nachtragsposition nach Leistungserfassung: in der Restmengenauswahl der Folgerechnung enthalten.
- Stornierte Schlussrechnung: Nachtrag wieder möglich.
- Aktive Schlussrechnung: gesamte Positionsliste gesperrt.
- Bestandsfall mit Teilrechnung: keine Migration erforderlich.
