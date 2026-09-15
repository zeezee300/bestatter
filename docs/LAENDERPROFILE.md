# Länderprofile und Internationalisierung

Stand: 0.51.0

## Zielbild

Die Installation führt einen gemeinsamen Artikelkatalog. Jede Niederlassung erhält jedoch ein festes Länderprofil. Dieses steuert:

- die auswählbaren Umsatzsteuersätze,
- das Rechnungsformat,
- den Zahlungs-QR-Standard,
- den Vorlagen-Namensraum und
- soweit erforderlich eine regionale Untergliederung.

Neue Länder werden zuerst technisch vorbereitet und anschließend fachlich sowie rechtlich freigegeben. Ein vorbereitetes, aber nicht freigegebenes Profil darf keiner produktiven Niederlassung zugeordnet werden.

## Deutschland

Das bestehende Verhalten bleibt erhalten: Umsatzsteuersätze 0, 7 und 19 Prozent, ZUGFeRD als Standard-Rechnungsprofil und EPC-SEPA-Zahlcode. Bestehende Niederlassungen werden bei der Migration Deutschland zugeordnet.

## Österreich – erste Ausbaustufe

Die erste österreichische Ausbaustufe umfasst:

- Länderkennzeichen `AT`,
- Umsatzsteuersätze 0, 10, 13 und 20 Prozent für den vorgesehenen Bestattungs-Leistungskatalog,
- Auswahl eines der neun Bundesländer,
- EPC-SEPA-Zahlcode,
- frei wählbares Rechnungsprofil, zunächst standardmäßig ohne deutsches E-Rechnungsformat,
- Vorlagenpfade nach dem Schema `AT/<BUNDESLAND>/<DATEI>.docx`.

Der seit Juli 2026 für ausgewählte Nahrungsmittel geltende Satz von 4,9 Prozent ist nicht Bestandteil des derzeitigen Bestattungs-Leistungskatalogs. Bevor solche Waren abgerechnet werden, muss das Datenmodell auf dezimale Steuersätze erweitert und steuerlich geprüft werden.

### Vorlagenregeln

Allgemeine Dokumente – beispielsweise Kondolenzlisten oder normale Korrespondenz – dürfen die neutrale Paketvorlage verwenden, solange keine regionale Variante hinterlegt wurde.

Behördenabhängige Dokumente dürfen nicht stillschweigend auf eine deutsche Vorlage zurückfallen. Für die Sterbefallanzeige muss daher eine fachlich freigegebene Datei im Vorlagenordner der Niederlassung liegen, zum Beispiel:

`AT/WIEN/STERBEFALLANZEIGE_STANDESAMT_BEARBEITBAR.docx`

Fehlt sie, wird die Erzeugung mit einem verständlichen Hinweis abgebrochen. Damit wird verhindert, dass eine deutsche Behördenvorlage irrtümlich in Österreich verwendet wird.

## Rechnungen und E-Rechnung

Das Rechnungsprofil wird pro Niederlassung festgelegt:

- `ZUGFERD`: deutsche hybride PDF/XML-Rechnung,
- `XRECHNUNG`: deutsche strukturierte XRechnung,
- `NONE`: normale PDF-Rechnung ohne strukturierten deutschen E-Rechnungsdatensatz.

Für Österreich ist `NONE` der sichere Ausgangswert. Rechnungen an österreichische Bundesdienststellen benötigen einen eigenen, später zu implementierenden und zu validierenden e-Rechnung.gv.at-Prozess. Die Auswahl eines deutschen Profils bedeutet nicht automatisch österreichische B2G-Konformität.

## Weitere Länder

Schweiz und Frankreich sind als nicht freigegebene technische Profile vorbereitet. Die Aktivierung erfordert mindestens:

1. dezimale Umsatzsteuersätze im Artikel-, Leistungs- und Rechnungsmodell,
2. eigenes Zahlungs-QR- beziehungsweise E-Rechnungsprofil,
3. lokalisierte Feldbezeichnungen und Datums-/Zahlenformate,
4. rechtlich geprüfte Formulare und Pflichtangaben,
5. automatisierte Länder- und Abnahmetests.

## Fachliche Freigabe

Steuersätze, Formulare und elektronische Rechnungswege sind vor produktiver Verwendung durch Steuerberatung beziehungsweise zuständige Fachstellen zu prüfen. Die Anwendung stellt technische Profile bereit, ersetzt aber keine rechtliche Prüfung.

## Geprüfte Primärquellen

- Österreichisches Unternehmensserviceportal: `https://www.usp.gv.at/themen/steuern-finanzen/umsatzsteuer-ueberblick/steuersaetze-und-steuerbefreiungen-der-umsatzsteuer.html`
- Österreichisches Unternehmensserviceportal zur Rechnungslegung und e-Rechnung an den Bund: `https://www.usp.gv.at/themen/steuern-finanzen/umsatzsteuer-ueberblick/rechnung.html`
- Stadt Wien zur Anzeige des Todes und Ausstellung einer Sterbeurkunde: `https://www.wien.gv.at/amtswege/sterbeurkunde-ausstellung-nach-todesfall`

Die Quellen wurden am 7. September 2026 geprüft. Da sich Steuer- und Verwaltungsrecht ändern können, gehört eine erneute Prüfung zur Freigabe jeder Länderversion.
