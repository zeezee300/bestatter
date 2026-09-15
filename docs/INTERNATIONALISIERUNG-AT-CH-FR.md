# Internationalisierung: Delta-Analyse und Entwicklungsvorgaben (AT, CH, FR)

Ausgangsbasis ist die konsolidierte deutsche Anwendung. Zielmärkte in empfohlener Umsetzungsreihenfolge: Österreich → Schweiz → Frankreich. Diese Reihenfolge ist bewusst nach Umsetzungsaufwand sortiert, nicht nach Marktgröße (Frankreich ist der größte Markt, aber der aufwendigste).

## 0. Vorbedingung für alle drei Märkte: Länderprofil statt harter Kodierung

Bevor an einem einzelnen Land gearbeitet wird, muss eine Voraussetzung geschaffen werden, die aktuell fehlt: **Deutschland ist an mehreren Stellen fest einprogrammiert, nicht konfiguriert.** Konkret gefunden:

- `ArticleService::VAT_RATES = [0, 7, 19]` (Zeile 17) validiert hart gegen deutsche Steuersätze: *„Die Mehrwertsteuer muss 0, 7 oder 19 Prozent betragen.“* (Zeile 395). Österreich (20 / 13 / 10 %), die Schweiz (8,1 / 2,6 / 3,8 %) und Frankreich (20 / 10 / 5,5 %) würden an dieser Stelle sofort scheitern.
- `EInvoiceService` kennt ausschließlich die Profile `ZUGFERD` und `XRECHNUNG`.
- Zahlungs-QR-Code, Devis-/Rechnungsvorlagen und Terminologie („Standesamt“, „Friedhofsverwaltung“) sind deutsches Fachvokabular ohne Umschaltbarkeit.

**Konkrete Vorgabe (Schritt 0, vor jedem Länderschritt):**

1. Neue Tabelle `bestatter_country_profiles` (oder Erweiterung der bestehenden Niederlassungs-Konfiguration `InstallationConfigService`) mit mindestens: `countryCode` (ISO 3166-1 alpha-2), `vatRates` (JSON-Array), `invoiceProfile` (`ZUGFERD` | `XRECHNUNG` | `FACTURX` | `NONE`), `paymentQrStandard` (`EPC069-12` | `SWISS_QR` | `NONE`), `documentTemplateSet` (Verweis auf Vorlagen-Namespace).
2. `ArticleService::VAT_RATES` wird pro Niederlassung aus dem Länderprofil gelesen statt einer globalen Konstante.
3. `EInvoiceService` bekommt einen dritten Erzeugungspfad (siehe Abschnitt 3.2), ausgewählt über `invoiceProfile`.
4. Eine Niederlassung bekommt genau ein Länderprofil zugewiesen (ihr habt Mehrniederlassungsfähigkeit bereits – das ist der richtige Anknüpfungspunkt, nicht ein globaler Schalter für die ganze Installation).

Ohne diesen Schritt wird jede folgende Länderarbeit zu Spezialfall-Code (`if ($country === 'CH')`) verstreut über mehrere Services – das widerspricht eurem bisherigen sauberen Customizing-Ansatz und sollte vermieden werden.

---

## 1. Österreich (AT) — geringster Aufwand, empfohlener erster Schritt

### Rechtliche Ausgangslage
Bestattungsrecht ist in Österreich überwiegend **Landessache** (9 unterschiedliche Landes-Leichen- und Bestattungsgesetze), nicht Bundessache. Der Grundablauf ist identisch zu Deutschland: ärztliche Totenbeschau, Meldung an die Gemeinde/das Standesamt, Übernahme der Behördenwege durch das Bestattungsunternehmen. Es gibt **keine bundesweit vorgeschriebene Kostenvoranschlags- oder Rechnungsform** wie in Frankreich – hier ist der Aufwand also strukturell klein.

### Delta zur bestehenden Anwendung
- **Kein neues Rechnungsformat nötig für den Regelfall.** Österreich hat keine B2C-E-Rechnungspflicht. Eine B2G-Pflicht (ebInterface bzw. PEPPOL über das Portal e-rechnung.gv.at) besteht seit 2014 nur gegenüber Bundesdienststellen und seit 2019 auch gegenüber Ländern/Gemeinden – relevant für euch nur in Randfällen (z. B. Rechnungsstellung an eine städtische Friedhofsverwaltung), nicht für das Kerngeschäft mit Angehörigen.
- **Terminologie und Formularvorlagen** müssen an die jeweilige Landesgesetzgebung angepasst werden. Da die Fristen und Verfahren neun Mal unterschiedlich sind, empfiehlt sich **kein** einzelnes „Österreich-Profil“, sondern ein Bundesland-Parameter innerhalb des Länderprofils, der nur die Vorlagentexte/Fristen-Defaults ändert, nicht die Kernlogik.
- **Mehrwertsteuer:** 20 % Normalsatz, 13 %/10 % ermäßigt – reine Konfigurationsänderung sobald Schritt 0 umgesetzt ist.

### Konkrete Entwicklungsschritte
1. Länderprofil `AT` mit `vatRates: [0, 10, 13, 20]`, `invoiceProfile: NONE` (Standardfall), `paymentQrStandard: EPC069-12` (Österreich nutzt SEPA, kein eigener Standard – hier ändert sich nichts an der bestehenden QR-Logik).
2. Neuer Vorlagensatz für die drei bis vier größten Bundesländer zuerst (Wien, Niederösterreich, Oberösterreich decken einen Großteil der Fälle ab), restliche Länder iterativ nachziehen.
3. Optionales, klar als Zusatzmodul gekennzeichnetes ebInterface-Exportformat für den B2G-Randfall – **niedrige Priorität**, nur falls konkrete Kundennachfrage aus dem kommunalen Umfeld besteht.

---

## 2. Schweiz (CH) — mittlerer Aufwand, wichtigster technischer Unterschied: Zahlungsverkehr

### Rechtliche Ausgangslage
Bestattungsrecht liegt **kantonal**. Drei zugelassene Bestattungsarten (Erd-, Feuer-, Gruftbestattung); anders als in Deutschland/Österreich besteht **keine Friedhofspflicht für Totenasche** – das ist fachlich relevant für eure Termin-/Checklistenvorlagen (z. B. eine Option „Ascheverstreuung außerhalb des Friedhofs“, die es in einem DE/AT-Profil nicht braucht).

### Delta zur bestehenden Anwendung – das Kernthema ist der Zahlungsverkehr, nicht das Recht
Das ist der wichtigste technische Befund dieser Analyse: **Die Schweiz verwendet seit dem 1. Oktober 2022 verpflichtend die Swiss QR-Rechnung, nicht den SEPA-EPC069-12-Standard, den `EInvoiceService` heute erzeugt.** Das ist kein anderes Layout desselben Standards, sondern ein eigenständiges Format mit eigener Kodierung. Wesentliche Unterschiede:

| Merkmal | Aktuell (EPC069-12, DE/AT) | Swiss QR-Rechnung |
|---|---|---|
| Kontobezug | IBAN | **QR-IBAN** (reservierter IID-Bereich) *oder* Standard-IBAN |
| Referenznummer | Verwendungszweck, Freitext | **QR-Referenz** (26-stellig numerisch + Prüfziffer) *oder* Creditor Reference nach ISO 11649 (`RF`-Präfix, SCOR) |
| Währung | EUR | **CHF oder EUR**, Währungscode ist Pflichtfeld direkt neben dem Betrag |
| Layout | Nur QR-Code auf der Rechnung | **Zahlteil + Empfangsschein**, genormtes A6-Format, üblicherweise am unteren Rand perforiert |
| Kodierung | EPC-QR-Datensatz | Eigener Swiss-QR-Code-Datensatz nach den „Swiss Implementation Guidelines QR-Rechnung“ (SIX Group), technisch auf ISO 20022 aufsetzend |

**Schnittstellenbeschreibung – neue Klasse `SwissQrService` (analog zur bestehenden EPC-Erzeugung in `EInvoiceService`, aber eigenständig, nicht als Variante):**

```
SwissQrService::buildPayload(array $branch, array $invoice): SwissQrPayload

SwissQrPayload {
  creditorIban: string        // QR-IBAN oder Standard-IBAN der Niederlassung
  creditorName, creditorAddress, creditorPostalCode, creditorCity, creditorCountry
  debtorName, debtorAddress, ...            // optional, aus Rechnungsempfänger
  amount: string               // exakt 2 Nachkommastellen
  currency: 'CHF' | 'EUR'
  referenceType: 'QRR' | 'SCOR' | 'NON'
  reference: string            // je nach IBAN-Typ Pflicht oder verboten – siehe Validierungsregel unten
  unstructuredMessage: string  // z. B. Rechnungsnummer/Fallnummer
}
```

**Validierungsregeln, die zwingend serverseitig geprüft werden müssen** (Quelle: „Swiss Implementation Guidelines QR-Rechnung“, SIX Group):
- Bei **QR-IBAN** ist eine **QR-Referenz** zwingend vorgeschrieben (26 Ziffern + Modulo-10-rekursiv-Prüfziffer, darf nicht ausschließlich aus Nullen bestehen).
- Bei **Standard-IBAN** ist entweder keine Referenz oder eine **Creditor Reference (ISO 11649, `RF` + Prüfziffer + bis zu 21 alphanumerische Zeichen)** zulässig – **nicht** die QR-Referenz.
- Diese beiden Modi dürfen nicht vermischt werden; eine falsche Kombination führt in der Praxis zu einer von Banken abgelehnten Zahlung, nicht nur zu einem hässlichen Beleg.

**Konkrete Entwicklungsschritte:**
1. `SwissQrService` neu bauen (nicht in `EInvoiceService` verschachteln – anderes Rechtsgebiet, andere Testbarkeit).
2. Niederlassungsstammdaten um `ibanType` (QR-IBAN/Standard) und `qrReferenceSeed` (falls ihr Referenznummern selbst vergebt, z. B. aus Rechnungs-/Fallnummer ableiten) erweitern.
3. DOCX-/PDF-Rechnungsvorlage für CH: eigenes Layout mit Zahlteil + Empfangsschein statt des heutigen EPC-QR-Blocks – **das ist eine eigene Vorlage, kein Parameter der bestehenden**, weil das Seitenlayout normiert ist.
4. Ein Modultest, der eine erzeugte Zahlung gegen die offiziellen SIX-Testfälle validiert, bevor das Feature als „einsatzbereit“ markiert wird – falsche Referenznummern werden von Schweizer Banken stillschweigend abgelehnt, das darf nicht erst beim Kunden auffallen.
5. Mehrwertsteuersätze im Länderprofil `CH`: `[0, 2.6, 3.8, 8.1]`.

### Fachlicher Zusatzaufwand außerhalb der Zahlungslogik
- Kantonal unterschiedliche Ruhefristen (Grabruhe 20–25 Jahre je Kanton) als konfigurierbarer Wert in den Aufbewahrungs-/Friedhofsdaten, nicht im Code.
- Terminart „Ascheverstreuung“ als zusätzliche, nicht verpflichtende Option im Terminartenkatalog.

---

## 3. Frankreich (FR) — größter Markt, größter Aufwand, zwei getrennte Baustellen

Frankreich hat zwei voneinander unabhängige regulatorische Anforderungen, die getrennt geplant werden sollten: den **Kostenvoranschlag (Devis)** und die **E-Rechnungspflicht 2026/2027**. Beide sind gesetzlich verpflichtend, aber zu unterschiedlichen Zeitpunkten im Prozess relevant.

### 3.1 Der gesetzlich vorgeschriebene Devis (Kostenvoranschlag)

**Rechtsgrundlage:** Art. R.2223-29 des Code général des collectivités territoriales (CGCT) in Verbindung mit dem *arrêté du 23 août 2010*, geändert durch den *arrêté du 3 août 2011*. Seit 2011 müssen Kostenvoranschläge diesem Modell entsprechen, damit Familien Angebote verschiedener Anbieter vergleichen können.

**Verbindliche Struktur** (das ist keine Stilvorgabe, sondern ein vorgeschriebenes Tabellenformat):

Drei getrennte Spalten pro Position:
1. **Prestations courantes** (laufende, im Angebot enthaltene Pflicht-/Regelleistungen)
2. **Prestations complémentaires optionnelles** (optionale Zusatzleistungen)
3. **Frais avancés pour le compte de la famille** (Fremdkosten, die das Bestattungsunternehmen im Namen der Familie verauslagt, z. B. Friedhofsgebühren, Todesanzeigen – **ohne Gewinnaufschlag** weiterzugeben)

Diese Spalten sind über **acht gesetzlich definierte Ablaufschritte** der Bestattung gegliedert (u. a.: Vorbereitung/Organisation der Bestattung inkl. Behördengänge, Sarg und Zubehör, Transport, Aufbahrung, Trauerfeier, Bestattung/Einäscherung selbst). Gesetzlich zwingend enthalten sein müssen mindestens: das Transportfahrzeug, der Sarg mit Zubehör (Normgrößen: 22 mm Wandstärke bzw. 18 mm bei Kremation, mit vier Griffen, dichter Einlage) sowie die Bestattungs- bzw. Kremationsleistung selbst inklusive Urne.

**Abgleich mit eurem bestehenden Datenmodell:** Das ist eine gute Nachricht – ihr habt mit `ArticleService::COST_TYPES = ['INTERNAL', 'EXPENSE', 'THIRD_PARTY']` bereits eine Kostenartlogik, die konzeptionell in dieselbe Richtung geht. Sie deckt sich aber **nicht 1:1** mit der französischen Einteilung: Eure Unterscheidung ist *wer erbringt/liefert*, die französische Vorgabe ist *Pflicht vs. optional vs. durchlaufender Posten für die Familie*. Es reicht daher nicht, eure bestehenden Werte umzubenennen – ihr braucht ein zusätzliches, unabhängiges Flag `isMandatoryByLaw: bool` bzw. `frenchDevisColumn: COURANTE | OPTIONNELLE | AVANCE` pro Artikel/Position, das eure heutige Kostenart ergänzt, nicht ersetzt.

**Konkrete Entwicklungsschritte:**
1. Neues Feld an Artikelkatalog-Positionen (und an Fallleistungen): `frenchDevisColumn`, nur relevant/sichtbar wenn Länderprofil `FR`.
2. Neue Dokumentvorlage „Devis réglementaire FR“ mit der Drei-Spalten-Tabelle je der acht Ablaufschritte – strukturell eine neue `DocumentService`-Vorlage, kein Parameter der bestehenden KVA-Vorlage, weil das Layout gesetzlich fixiert ist, nicht gestaltbar.
3. Validierungsregel analog zu eurer bestehenden Abrechnungssicherheitsprüfung: Ein Devis, der keine der gesetzlichen Pflichtpositionen (Sarg, Transport, Bestattungs-/Kremationsleistung) enthält, darf nicht als „vollständig“ freigegeben werden – das ist dieselbe Art Prüfung, die ihr bei Rechnungen schon eingebaut habt, nur mit anderen Pflichtfeldern.
4. Frankreich-Vorlagen für Terminart-/Checklistenkatalog auf Basis der acht gesetzlichen Ablaufschritte statt der heutigen sechs Fallordner-Kategorien – hier lohnt sich ein eigener, aber strukturähnlicher Vorlagensatz.

### 3.2 E-Rechnungspflicht 2026/2027 (unabhängig vom Devis, zeitlich versetzt)

**Rechtsgrundlage und Zeitplan** (Reform der Facturation électronique, DGFIP):
- **Ab 1. September 2026:** Alle in Frankreich umsatzsteuerpflichtigen Unternehmen müssen elektronische Rechnungen **empfangen** können; große Unternehmen und ETI müssen ab diesem Datum bereits elektronisch **ausstellen**.
- **Ab 1. September 2027:** Auch KMU, Kleinst- und Kleinunternehmen (die meisten Bestattungsunternehmen fallen in diese Kategorie) müssen elektronisch ausstellen und elektronisch melden (e-reporting).
- Zulässige Formate: **Factur-X** (hybrides PDF/A-3 mit eingebettetem XML nach CII-Syntax), UBL 2.1, oder reines CII-XML – alle drei bilden dasselbe **EN 16931**-Datenmodell ab.
- Übertragung ausschließlich über eine zertifizierte **plateforme agréée (PDP)** oder das staatliche Portal; ein einfacher PDF-Versand per E-Mail erfüllt die Pflicht **nicht** mehr. Für Rechnungen an öffentliche Stellen bleibt zusätzlich **Chorus Pro** relevant.

**Warum das für euch ein kleinerer Schritt ist, als es zunächst klingt:** `EInvoiceService::createXml()` erzeugt bereits ein CII-/EN-16931-konformes XML (für ZUGFeRD/XRechnung). **Factur-X basiert auf genau demselben Datenmodell und derselben CII-Syntax** – der deutsch-französische Ursprung von ZUGFeRD/Factur-X ist kein Zufall, beide Standards wurden ursprünglich gemeinsam entwickelt. Der Mehraufwand liegt also überwiegend nicht in der Datenerzeugung, sondern:

1. Ein drittes `invoiceProfile: FACTURX` in `EInvoiceService`, das dasselbe CII-Grundgerüst mit den Factur-X-spezifischen Kontextparametern statt der ZUGFeRD-/XRechnung-Kennungen erzeugt (analog zum bestehenden `isXRechnung`-Zweig).
2. Korrekte Einbettung als **PDF/A-3** mit der XML-Datei als eingebettetem Attachment (Namenskonvention und Metadaten sind bei Factur-X spezifisch vorgeschrieben) – prüfen, ob eure heutige PDF-Erzeugung (`createPdf()` in `DocumentService`) PDF/A-3 mit Attachments unterstützt oder ob hier ein zusätzlicher Konvertierungsschritt nötig wird.
3. **Neue externe Schnittstelle:** Übertragung an eine PDP. Da die Wahl der PDP kundenspezifisch ist (keine einzelne Pflichtplattform für B2B), empfiehlt sich ein **abstraktes Transport-Interface**, ähnlich wie ihr es bei Paperless schon vorgemacht habt (optional, austauschbar, App funktioniert auch ohne):

```
interface PdpTransportInterface {
    submit(string $facturXPdfPath, array $metadata): PdpSubmissionResult;
    status(string $submissionId): PdpStatus;
}
```

Konkrete PDP-Anbindungen (es gibt keinen einheitlichen API-Standard über alle PDP-Anbieter) wären dann austauschbare Implementierungen dieses Interfaces – genau das Muster, das ihr mit `PaperlessService` als optionalem Konnektor bereits erfolgreich etabliert habt.

**Konkrete Entwicklungsschritte:**
1. `invoiceProfile: FACTURX` in `EInvoiceService` ergänzen (kleinster Teilschritt, direkt wiederverwendbar).
2. PDF/A-3-Fähigkeit von `DocumentService::createPdf()` prüfen/ergänzen.
3. `PdpTransportInterface` als Kontrakt definieren, zunächst **ohne** konkrete Implementierung ausliefern (die App muss ohne PDP funktionieren, wie heute ohne Paperless) – erste konkrete Anbindung erst mit echtem Kundenbedarf und nach Wahl einer konkreten PDP.
4. Zeitliche Priorität: Da die Ausstellungspflicht für kleine Bestattungsunternehmen erst zum 1. September 2027 greift, ist dies **kein Blocker** für einen Frankreich-Marktstart 2026 – der Devis (Abschnitt 3.1) ist der Punkt, der von Tag eins an gebraucht wird, die E-Rechnungspflicht kann als Folgeschritt mit rund einem Jahr Vorlauf geplant werden.

---

## 4. Priorisierte Reihenfolge

| Schritt | Inhalt | Abhängigkeit | Aufwand relativ |
|---|---|---|---|
| 0 | Länderprofil-Architektur (VAT-Konfiguration, Rechnungsprofil-Auswahl) | – | mittel, aber einmalig |
| 1 | Österreich: Vorlagen + Landes-Parameter | Schritt 0 | klein |
| 2 | Schweiz: `SwissQrService` + CH-Rechnungsvorlage | Schritt 0 | mittel |
| 3 | Frankreich: Devis réglementaire (3-Spalten-Vorlage + Pflichtprüfung) | Schritt 0 | mittel–groß |
| 4 | Frankreich: Factur-X-Profil in `EInvoiceService` | Schritt 3 (kann aber parallel laufen) | klein–mittel |
| 5 | Frankreich: `PdpTransportInterface` + erste konkrete PDP-Anbindung | Schritt 4, echter Kundenbedarf | groß, aber zeitlich bis 09/2027 planbar |

## 5. Offene Punkte, die vor Entwicklungsbeginn zu klären sind

- **Rechtsberatung vor Ort einholen** für jedes Zielland, bevor eine Vorlage produktiv geht – diese Analyse ersetzt keine anwaltliche Prüfung, insbesondere für den bindenden französischen Devis, bei dem Verstöße bereits behördlich sanktioniert wurden.
- Klären, ob für die Schweiz eine **eBill**-Anbindung (die digitale Ablösung der Papier-QR-Rechnung im E-Banking) mittelfristig gewünscht ist – das wäre ein weiterer, unabhängiger Ausbauschritt nach der QR-Rechnung selbst.
- Für Frankreich: Beobachten, ob sich der Zeitplan der E-Rechnungsreform erneut verschiebt (das ist bereits einmal passiert) – Schritt 4/5 entsprechend flexibel timen.
