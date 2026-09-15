# Rechnungsvorlage: Leistungs- und Steuerblöcke

## Umgesetzte Darstellung

Die Paketvorlage `resources/templates/RECHNUNG.docx` gliedert Rechnungspositionen in einer schmalen, zentrierten Leistungstabelle anhand des fachlichen Positionstyps. Im Rechnungskopf steht die Empfängeranschrift links; die kompakten Belegdaten stehen rechts. Der SEPA-Zahlcode wird in einer platzsparenden, noch scanbaren Größe von 1,8 cm dargestellt.

- `EL`: **Eigene Leistungen**
- `FK`: **Fremdleistungen und verauslagte Beträge**
- `DP`: **Durchlaufende Posten – nicht Teil des Entgelts**

Jeder tatsächlich verwendete Block erhält eine eigene Zwischensumme. Ein Block wird nicht ausgegeben, wenn er keine Positionen beziehungsweise keinen Betrag enthält. Der rechtsbündige Summenblock weist das umsatzsteuerliche Nettoentgelt, die Umsatzsteuer, gegebenenfalls echte durchlaufende Posten und den Rechnungsbetrag getrennt aus. Die Tabellenzeile `Frühere Rechnungen` erscheint nur, wenn der Rechnung tatsächlich ein positiver Betrag aus früheren Rechnungen zugeordnet ist. Die Umsatzsteuerübersicht enthält für jeden in EL-/FK-Positionen vorkommenden Steuersatz das zugehörige Nettoentgelt und den Steuerbetrag. DP-Positionen werden dort nicht als steuerpflichtiges Entgelt berücksichtigt.

## Pflichtangaben und Plausibilisierung

Vor der Dokumenterzeugung werden ein vollständiger Rechnungsempfänger, die Absender- und Bankdaten, mindestens Steuernummer oder USt-IdNr. sowie Beginn und Ende des Leistungszeitraums verlangt. Die Vorlage enthält insbesondere Rechnungsnummer, Ausstellungsdatum, Leistungszeitraum, Empfängeranschrift, Leistungsbeschreibung, Menge, Entgelt je Steuersatz und Umsatzsteuerbetrag.

Echte durchlaufende Posten sind nur mit 0 % Umsatzsteuer zulässig. In der strukturierten E-Rechnung werden sie als nicht umsatzsteuerbarer Umsatz (`O`) und mit dem Hinweis „Durchlaufender Posten gemäß § 10 Abs. 1 Satz 6 UStG – nicht Teil des Entgelts“ ausgegeben. Die fachliche Einstufung als DP bleibt eine Einzelfallentscheidung und ist mit Buchhaltung beziehungsweise Steuerberatung abzustimmen.

Rechtsgrundlagen für die Umsetzung sind insbesondere § 14 Abs. 4 UStG (Rechnungspflichtangaben) und § 10 Abs. 1 Satz 6 UStG (durchlaufende Posten).

## Vorlagenbetrieb

Eine bereits im konfigurierten Nextcloud-Vorlagenordner vorhandene kundeneigene `RECHNUNG.docx` wird bei einem App-Update nicht automatisch überschrieben. Die neue Paketvorlage muss daher bei bestehenden Installationen bewusst als neue aktive Vorlage übernommen werden.
