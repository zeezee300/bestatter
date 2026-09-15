# Anforderungen: Länderprofil Schweiz (CH)

**Abhängigkeit:** Ticket A (Länderprofil-Architektur) muss abgeschlossen sein. Dieses Dokument beschreibt die Anforderungen für die anschließende Umsetzung; es ist bewusst granularer als das Österreich-Ticket, weil die Schweiz eine eigenständige, prüfpflichtige Zahlungslogik braucht, kein reines Vorlagenthema ist.

## 1. Warum dieser Schritt größer ist als Österreich

Anders als Österreich hat die Schweiz seit dem 1. Oktober 2022 ein verpflichtendes, technisch eigenständiges Zahlungsformat: die **Swiss QR-Rechnung**. Das ist kein alternatives Layout des bestehenden EPC069-12-Codes, sondern eine eigene Datenkodierung nach den „Swiss Implementation Guidelines QR-Rechnung“ (SIX Group), mit eigenen Validierungsregeln. Eine falsch kodierte Zahlung wird von Schweizer Banken zurückgewiesen oder nicht automatisch verarbeitet – das ist ein Korrektheitsthema, kein Geschmacksthema, und muss entsprechend geprüft werden.

## 2. Ist-Zustand im Code (Ausgangspunkt der Änderung)

Der bestehende Ablauf für den EPC-Zahlcode, geprüft an drei Stellen:

1. `EInvoiceService::epcPayload(array $invoice, array $branch): string` (Zeile 104) erzeugt den EPC069-12-Text.
2. `DocumentService::generateInvoice()` (Zeilen 54, 78–80) verlangt bei aktiviertem QR-Code zusätzlich ein bereits fertig gerendertes PNG-Bild (`$paymentQrPng`) – das Bild wird **nicht serverseitig erzeugt**, sondern vom Client übergeben.
3. Im Frontend (`src/main.js`, Funktion `.generate-invoice-document`) wird der Payload über `GET .../invoices/{id}/payment-data` geholt und **im Browser** mit der JS-Bibliothek `qrcode` in ein PNG umgewandelt (`_n.toDataURL(...)`), das anschließend per `POST .../invoices/{id}/document` mit `paymentQrPng` an den Server zurückgeschickt wird. Der Server validiert nur, dass es sich um ein gültiges, nicht zu großes PNG handelt (`CommercialApiController::generateInvoiceDocument`, Zeilen 74–82) – er kennt den kodierten Inhalt nicht.

**Wichtige Konsequenz für die Schweiz:** Die QR-Rechnung ist mehr als ein Bild-Austausch. Sie ist ein eigener, genormter **Seitenabschnitt** (Zahlteil + Empfangsschein, A6-Maße, feste Schriftgrößen und Feldpositionen je Style Guide), der am unteren Rand der Rechnung erscheint – nicht nur ein an einer Platzhalterstelle eingefügtes QR-Bild wie heute. Die heutige Architektur (ein austauschbares Bild an einer Vorlagenstelle) reicht für die Schweiz **nicht** aus.

## 3. Funktionale Anforderungen

### 3.1 Neue Niederlassungsdaten (Schweiz-spezifisch, nur sichtbar wenn `country_code = CH`)
- `iban_type`: `QR_IBAN` oder `STANDARD_IBAN` (bestimmt, welcher Referenztyp erlaubt ist, siehe 3.3)
- Bestehendes `iban`-Feld wird für CH-Niederlassungen zusätzlich gegen den Schweizer/liechtensteinischen IID-Nummernkreis geprüft, wenn `iban_type = QR_IBAN`
- `qr_reference_seed` (optional): Vorgabe, wie die 26-stellige QR-Referenz aus Fallnummer/Rechnungsnummer abgeleitet wird, falls nicht durchgehend automatisch vergeben

### 3.2 Neuer Dienst `SwissQrService` (eigenständig, nicht Teil von `EInvoiceService`)

```
SwissQrService::buildPayload(array $branch, array $invoice): SwissQrPayload
SwissQrService::validate(SwissQrPayload $payload): array   // Fehlerliste, leer = gültig

SwissQrPayload {
  creditorIban: string
  creditorName, creditorStreet, creditorPostalCode, creditorCity, creditorCountry: string
  debtorName, debtorStreet, debtorPostalCode, debtorCity, debtorCountry: string  // optional
  amount: string            // exakt zwei Nachkommastellen, Punkt als Trenner
  currency: 'CHF' | 'EUR'
  referenceType: 'QRR' | 'SCOR' | 'NON'
  reference: string
  unstructuredMessage: string   // z. B. Fallnummer + Rechnungsnummer
}
```

Begründung für eine eigene Klasse statt eines dritten Zweigs in `EInvoiceService`: andere Rechtsgrundlage (Zahlungsverkehrsrecht statt Umsatzsteuerrecht), andere Testbarkeit (Referenzprüfziffern), keine inhaltliche Nähe zum EN-16931-Rechnungsdatenmodell.

### 3.3 Zwingende Validierungsregeln (serverseitig, vor Dokumentfreigabe)

| Bedingung | Regel |
|---|---|
| `iban_type = QR_IBAN` | `referenceType` **muss** `QRR` sein; `reference` **muss** 26 Ziffern plus eine Modulo-10-rekursiv berechnete Prüfziffer sein; darf nicht ausschließlich aus Nullen bestehen |
| `iban_type = STANDARD_IBAN` | `referenceType` **darf nicht** `QRR` sein; zulässig sind `SCOR` (Referenz beginnt mit `RF`, gefolgt von zwei Prüfziffern nach ISO 7064 MOD 97-10 und 1–21 alphanumerischen Zeichen nach ISO 11649) oder `NON` (keine Referenz) |
| `currency` | ausschließlich `CHF` oder `EUR`; Code steht unmittelbar links neben dem Betrag, auch auf dem Empfangsschein |
| `amount` | zwei Nachkommastellen, keine Tausendertrennzeichen im codierten Wert |

Diese Tabelle ist bewusst so konkret gehalten, weil eine falsche Zuordnung (z. B. QR-Referenz auf einer Standard-IBAN) nicht zu einem sichtbaren Fehler in der App führt, sondern erst beim Zahlungsempfänger bzw. bei der Bank des Zahlungspflichtigen auffällt – das ist der riskanteste Fehlerfall in diesem gesamten Vorhaben und muss durch serverseitige Prüfung **vor** Dokumentfreigabe verhindert werden, nicht erst durch Kundenrückmeldung entdeckt werden.

### 3.4 Neue Rechnungsvorlage statt Parametrisierung der bestehenden

- Eigene Vorlage `RECHNUNG_CH` mit dem genormten Zahlteil-/Empfangsschein-Layout (A6, feste Schriftgrößen laut Style Guide QR-Rechnung), nicht ein Parameter der bestehenden `RECHNUNG`-Vorlage.
- Der QR-Code selbst benötigt in der Bildmitte das Schweizer Kreuz als Erkennungsmerkmal (fester Bestandteil der Kodierung/des Renderings, kein optionales Logo).
- Layout-Vorgabe: Empfangsschein links, Zahlteil rechts, exakt im A6-Format am unteren Seitenrand – das ist eine feste Vorgabe des Zahlungsverkehrsstandards, keine Wahlmöglichkeit für Corporate Design.

### 3.5 Frontend-Auswirkung

Die bestehende Logik „Server liefert Payload-Text → Client rendert PNG mit generischer QR-Bibliothek → Client schickt PNG zurück“ kann für die Fälle mit reinem QR-Code (EPC) unverändert bleiben. Für die Schweiz sind zwei Ansätze möglich, zu entscheiden vor Implementierungsbeginn:

- **Option A (empfohlen):** Serverseitiges Rendering des kompletten Zahlteils inkl. Swiss-Cross-Overlay als fertiges Bild/PDF-Fragment, das direkt in `SwissQrService` bzw. `DocumentService` erzeugt wird. Vorteil: Die Korrektheit des amtlichen Layouts liegt vollständig serverseitig und ist testbar, keine Abhängigkeit von einer im Browser laufenden Bibliothek, die das Schweizer Kreuz und die genormten Feldpositionen möglicherweise nicht unterstützt.
- **Option B:** Client-seitiges Rendering wie heute, mit einer Swiss-QR-fähigen JS-Bibliothek statt der aktuell eingebundenen generischen `qrcode`-Bibliothek. Nachteil: Layoutkorrektheit hängt von einer Drittbibliothek im Browser ab, schwerer zu testen als ein serverseitiger Weg.

**Empfehlung:** Option A, weil sie dem bestehenden Muster „App bleibt für ihre eigene Korrektheit verantwortlich, keine externe Bibliothek entscheidet über Rechtskonformität“ entspricht, das ihr an anderer Stelle (z. B. E-Rechnungsvalidierung serverseitig statt im Browser) bereits konsequent verfolgt.

## 4. Rechtliche/fachliche Zusatzpunkte außerhalb der Zahlungslogik

- Keine bundesweite Friedhofspflicht für Totenasche – als zusätzliche, nicht verpflichtende Option in Terminart-/Checklistenkatalog für `country_code = CH` vorsehen (z. B. „Ascheverstreuung außerhalb des Friedhofs“).
- Grabruhefristen sind kantonal unterschiedlich (üblicherweise 20–25 Jahre) – als konfigurierbarer Wert je Niederlassung, nicht als Konstante.
- Mehrwertsteuersätze für ein `CH`-Länderprofil (Ticket A, Konfigurationswert): `0, 2.6, 3.8, 8.1` Prozent.

## 5. Akzeptanzkriterien für die Abnahme

- [ ] Eine Niederlassung mit `country_code = CH` erzeugt Rechnungen ausschließlich mit dem neuen `RECHNUNG_CH`-Layout, niemals mit dem EPC-Layout.
- [ ] `SwissQrService::validate()` lehnt jede Kombination ab, die gegen die Tabelle in Abschnitt 3.3 verstößt, mit einer für Sachbearbeitende verständlichen Fehlermeldung (Muster: *„Bei einer QR-IBAN ist eine QR-Referenz erforderlich.“*), bevor ein Dokument erzeugt wird.
- [ ] Mindestens ein Testfall pro Zeile der Tabelle in Abschnitt 3.3 ist als automatisierter Test vorhanden (positiv und negativ).
- [ ] Erzeugte Testrechnungen wurden gegen die offiziellen Validierungsbeispiele aus den „Swiss Implementation Guidelines QR-Rechnung“ geprüft (nicht nur gegen selbst geschriebene Tests) – **dieser Abgleich mit den offiziellen Referenzbeispielen ist Bedingung für die Freigabe**, nicht optional, weil eine rein intern konsistente, aber vom Standard abweichende Implementierung durch eigene Tests nicht auffallen würde.
- [ ] Eine Rechnung mit Betrag in EUR statt CHF wird korrekt mit dem Währungscode links neben dem Betrag ausgegeben.
- [ ] Bestehende DE/AT-Rechnungserzeugung (EPC069-12) ist durch die Einführung von `SwissQrService` nicht verändert oder verlangsamt (Regressionstest).

## 6. Offene Entscheidung vor Implementierungsbeginn

- Festlegen, ob Option A oder B (Abschnitt 3.5) umgesetzt wird – das beeinflusst, ob eine PHP-QR-/PDF-Rendering-Bibliothek mit Swiss-QR-Unterstützung beschafft werden muss oder eine JS-Alternative genügt.
- Klären, ob künftig auch **eBill** (die digitale Ablösung der Papier-QR-Rechnung im Schweizer E-Banking) gewünscht ist – das ist ein unabhängiger, hier bewusst ausgeklammerter Folgeschritt.
