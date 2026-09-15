# Vertragspreise, KVA und Nachträge

## Grundprinzip

Der Leistungskatalog liefert ausschließlich den Ausgangspreis beim erstmaligen Hinzufügen einer Leistung zu einem Fall. Danach besitzt die Fallleistung einen eigenen historischen Positionsstand aus Bezeichnung, Menge, Einheit, Netto-Einzelpreis und Umsatzsteuersatz. Spätere Änderungen des Leistungskatalogs verändern diesen Stand nicht.

## Kostenvoranschlag

Beim Festschreiben eines Kostenvoranschlags speichert die Anwendung einen unveränderlichen Snapshot aller Positionen, Preise, Steuersätze, Summen, Empfängerdaten und des Gültigkeitsdatums. Der KVA bleibt damit während und nach Ablauf seiner Gültigkeit als Nachweis unverändert. Bei der einmaligen Übernahme in einen Auftrag wird exakt dieser Snapshot verwendet.

## Auftrag

Ein direkt bestätigter oder aus einem KVA übernommener Auftrag erhält ebenfalls einen unveränderlichen Snapshot. Eine spätere Preisänderung im Leistungskatalog hat keine Wirkung auf diesen Auftrag und keine automatische Wirkung auf die Abrechnung des Falls.

## Bewusste Vertragsänderung

Solange noch keine Rechnung für den Fall angelegt wurde, kann ein Benutzer über **Vertragsnachtrag erfassen** die Leistungsauswahl bewusst ändern. Vor der Freigabe ist ein nachvollziehbarer Änderungsgrund erforderlich. Die Anwendung protokolliert Ursprungsdokument, Grund, Benutzer, Zeitpunkt sowie den Stand vor und nach der Änderung im Audit-Log. Der ursprüngliche Dokument-Snapshot wird nicht überschrieben.

Nach Anlage einer Teil- oder Schlussrechnung sind Preis-, Mengen-, Hinzufügungs- und Löschänderungen gesperrt. Bereits fakturierte Positionen waren unabhängig davon schon bisher unveränderlich.

## Empfohlener Arbeitsablauf

1. Leistungen auswählen; dabei werden die aktuellen Katalogpreise in den Fall kopiert.
2. Mengen und vereinbarte Einzelpreise prüfen.
3. KVA mit Gültigkeitsdatum oder direkten Auftrag festschreiben.
4. Spätere Katalogpflege betrifft nur neu hinzugefügte Leistungen anderer beziehungsweise noch nicht festgeschriebener Fälle.
5. Eine mit dem Auftraggeber vereinbarte Abweichung über **Vertragsnachtrag erfassen** begründen und eintragen.
6. Vor der ersten Rechnung Vertragsstand und Nachträge kontrollieren.
7. Rechnung anlegen; danach sind die Vertragspositionen gegen weitere Änderungen gesperrt.

Der Nachtrag ist eine interne, auditierte Vertragsänderung. Wenn eine zusätzliche unterschriebene Nachtragsurkunde benötigt wird, muss diese als separates Dokument erzeugt und vom Auftraggeber bestätigt werden.
