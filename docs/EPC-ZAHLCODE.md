# EPC-SEPA-Zahlcode

## Entscheidung

Der Zahlungs-QR wird aus den bereits serverseitig festgeschriebenen Rechnungsdaten erzeugt und als PNG direkt in die DOCX-Vorlage eingebettet. Der Browser ruft nur noch die Dokumenterzeugung auf; er erzeugt und überträgt kein eigenes QR-Bild mehr.

Seit Version 0.57.1 werden Zahlungsart, wirksamer QR-Standard und die benötigten Bank-/Mandatsdaten bereits bei der Rechnungsanlage als Zahlungssnapshot gespeichert. Damit bleibt die QR-Ausgabe einer Rechnung reproduzierbar, auch wenn anschließend Zahlungsart oder Niederlassungsstammdaten geändert werden. Bestehende Altrechnungen ohne Snapshot werden weiterhin aus dem aktuellen Datenstand erzeugt.

Die strukturierte Zahlungsreferenz wird deterministisch aus der Rechnungsnummer gebildet. Beispiel:

- Rechnungsnummer: `RE-2026-000001`
- ISO-11649-Referenz: `RF69RE2026000001`

Der lesbare Verwendungszweck `Rechnung RE-2026-000001` bleibt im Rechnungsformular erhalten. Er wird nicht zusätzlich in das unstrukturierte EPC-Feld geschrieben, weil EPC069-12 strukturierte und unstrukturierte Referenz nur alternativ zulässt.

## Validierung und Grenzen

- IBAN: Zeichenformat und MOD-97-Prüfsumme
- Betrag: 0,01 EUR bis 999.999.999,99 EUR
- Kontoinhaber: erforderlich, höchstens 70 Zeichen
- QR-Fehlerkorrektur: Stufe M
- QR-Bibliothek: `endroid/qr-code` 6.0.9
- PHP-Erweiterung: GD

Kann ein verpflichtender Zahlcode nicht valide erzeugt oder in der Vorlage nachgewiesen werden, wird die Rechnung nicht stillschweigend ohne QR-Code abgeschlossen. Bei SEPA-Lastschrift bleibt der Zahlungs-QR wie bisher unterdrückt.

Die QR-Ausgabe wird nur aktiviert, wenn sowohl der zentrale Rechnungsschalter als auch das Niederlassungsprofil den EPC-SEPA-Zahlcode zulassen. Die Rückmeldung nach der Dokumenterzeugung nennt eindeutig einen der drei Zustände: eingebettet, wegen SEPA-Lastschrift unterdrückt oder administrativ deaktiviert.

## Betrieb und Test

Das Release-Paket muss den von Composer erzeugten Ordner `vendor/` enthalten. Nach der Installation sind mindestens folgende Fälle zu prüfen:

1. Überweisungsrechnung mit gültiger deutscher IBAN: QR in DOCX und PDF sichtbar und mit Banking-App lesbar.
2. Geänderte IBAN-Prüfziffer: verständliche Fehlermeldung, kein Rechnungsabschluss.
3. Betrag 0,00 EUR: verständliche Fehlermeldung, kein QR-Code.
4. SEPA-Lastschrift: Einzugshinweis sichtbar, kein Überweisungs-QR.
5. Wiederholte Erzeugung im Status „Prüfung“: gleicher Payload und gleiche RF-Referenz.
