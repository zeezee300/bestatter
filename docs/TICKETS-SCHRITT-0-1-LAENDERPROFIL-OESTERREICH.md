# Entwicklungstickets: Schritt 0 (Länderprofil) und Schritt 1 (Österreich)

Grundlage ist die konsolidierte deutsche Anwendung. Beide Tickets sind bewusst klein und unabhängig von Schweiz/Frankreich umsetzbar.

## Bewertung und Umsetzungsstand 0.51.0

Das Architekturprinzip wurde übernommen. Ticket A ist technisch umgesetzt; Ticket B ist als sichere Österreich-Basis umgesetzt. Abweichungen vom ursprünglichen Vorschlag:

- Zulässige Steuersätze werden als Vereinigung der freigegebenen Länderprofile angeboten, weil der Artikelkatalog weiterhin installationsweit gemeinsam ist. Die steuerliche Zuordnung eines Artikels zu einem einzelnen Land ist ein eigener Folgeschritt.
- Österreichische Behördenvorlagen werden nicht ohne fachliche Prüfung erfunden. Für solche Dokumente gibt es keinen stillen deutschen Rückfall; neutrale Dokumente dürfen dagegen weiterhin die gemeinsame Vorlage nutzen.
- Bestehende Niederlassungen erhalten migrationssicher `DE`, `ZUGFERD` und `EPC069-12`. Eine vollständige Übernahme sämtlicher historischer Kombinationen globaler Schalter ist vor der ersten externen Veröffentlichung nicht erforderlich und würde mehrdeutige Altzustände erzeugen.
- Das österreichische B2G-Format und die Übermittlung an e-Rechnung.gv.at bleiben ausdrücklich offen.

Die produktbezogene Beschreibung steht in `docs/LAENDERPROFILE.md`. Die folgenden ursprünglichen Kriterien bleiben als Entscheidungsgrundlage erhalten; erledigte und bewusst zurückgestellte Teile werden nicht durch nachträglich geschönte Häkchen verschleiert.

---

## TICKET A — Länderprofil je Niederlassung (Voraussetzung für alle weiteren Länder)

### Kontext (Ist-Zustand, geprüft)

Zwei unabhängige Stellen sind heute fest auf Deutschland kodiert, mit unterschiedlicher Reichweite:

1. **Artikelkatalog ist installationsweit, nicht niederlassungsbezogen.** `ArticleService::validateArticle()` (Zeile 379–395) nimmt keinen Niederlassungsbezug entgegen; die Prüfung `if (!in_array($vatRate, self::VAT_RATES, true))` mit der Konstante `VAT_RATES = [0, 7, 19]` (Zeile 17) und der Fehlermeldung *„Die Mehrwertsteuer muss 0, 7 oder 19 Prozent betragen.“* (Zeile 395) gilt für den gesamten Katalog gleichermaßen. Daraus folgt: **Die zulässigen Steuersätze sind sinnvollerweise eine installationsweite Einstellung, keine Niederlassungseinstellung** – der Katalog wird nicht dupliziert.
2. **Rechnungs-/Zahlungsformat hängt dagegen bereits heute an der Niederlassung.** `bestatter_branches` (Migration `Version1800Date20260906000000.php`, Zeile 83) führt bereits IBAN, BIC, VAT-ID, Gläubiger-ID usw. pro Niederlassung, inklusive einer bereits vorhandenen, bisher rein informativen `country`-Spalte (Freitext, Default `'Deutschland'`, siehe `ConfigurationService::branches()`/`saveBranch()`). `DocumentService::generateInvoice()` übergibt `$branch` bereits an `EInvoiceService::createXml()` (Zeilen 117 und 142) mit fest verdrahtetem Standard (`'ZUGFERD'` bzw. `'XRECHNUNG'` als Literal). **Das Rechnungs-/QR-Format ist also bereits architektonisch niederlassungsbezogen – nur noch nicht konfigurierbar.**

Daraus ergibt sich eine bewusst zweigeteilte Lösung, keine einzelne globale Umschaltung.

### Akzeptanzkriterien

- [ ] Eine Administratorin kann in den Systemeinstellungen die installationsweit zulässigen Mehrwertsteuersätze pflegen (Standard weiterhin `[0, 7, 19]`), ohne Code zu ändern.
- [ ] Jede Niederlassung hat ein Länderfeld, das nicht mehr Freitext ist, sondern aus einer festen Liste (`DE`, `AT`, `CH`, `FR`, weitere folgen) gewählt wird.
- [ ] Jede Niederlassung hat ein Rechnungsprofil (`ZUGFERD`, `XRECHNUNG`, `NONE` – `FACTURX` folgt mit dem Frankreich-Schritt) und einen Zahlungs-QR-Standard (`EPC069-12`, `NONE` – `SWISS_QR` folgt mit dem Schweiz-Schritt).
- [ ] Bestehende Niederlassungen erhalten beim Migrieren automatisch `country = DE`, `invoiceProfile` = bisherigen Wert aus den globalen Rechnungseinstellungen (`InvoiceSettings.zugferdEnabled`/`xrechnungEnabled`), `paymentQrStandard = EPC069-12` falls `qrEnabled` aktiv war – **keine Änderung des sichtbaren Verhaltens für Bestandsniederlassungen.**
- [ ] `generateInvoiceDocument`/`generateInvoice` verwenden das Rechnungsprofil der Niederlassung der Rechnung, nicht mehr einen fallweise übergebenen Parameter.
- [ ] Bestehende Tests (`tests/php`, `tests/*-static.mjs`) laufen unverändert grün; ein neuer Test deckt die Migration bestehender Niederlassungsdaten ab.

### Technische Vorgaben

**Migration** (neue Version nach `1800`):
```
ALTER TABLE bestatter_country_rates   -- neue, kleine Tabelle statt Erweiterung von bestatter_branches
  (installationsweit, nicht pro Niederlassung)
  key VARCHAR, vat_rates TEXT (JSON-Array)

ALTER TABLE bestatter_branches
  ADD COLUMN country_code VARCHAR(2) NOT NULL DEFAULT 'DE'
  ADD COLUMN invoice_profile VARCHAR(20) NOT NULL DEFAULT 'ZUGFERD'
  ADD COLUMN payment_qr_standard VARCHAR(20) NOT NULL DEFAULT 'EPC069-12'
```
Die bisherige `country`-Spalte (Freitext) bleibt zur Anzeige/Anschrift erhalten (z. B. „Deutschland“ im Rechnungskopf) – `country_code` ist die neue, maschinenlesbare Ergänzung, keine Ablösung. Beide Felder werden im Formular nebeneinander gepflegt (Anzeigetext vs. Steuer-/Formatlogik).

**`ArticleService`:**
- `VAT_RATES` wird zur Laufzeit aus einer neuen `CountryConfigurationService::allowedVatRates(): array` gelesen statt einer Klassenkonstante.
- Fehlermeldung wird dynamisch: `'Die Mehrwertsteuer muss ' . implode(', ', $allowed) . ' Prozent betragen.'`

**`EInvoiceService` / `DocumentService`:**
- `generateInvoice()` liest `$branch['invoiceProfile']` statt eines hartkodierten Aufrufparameters; die bestehenden Signaturen von `createXml()`/`validateXml()` bleiben unverändert (Parameter `$standard` wird weiterhin durchgereicht, nur die Quelle des Werts ändert sich von Literal zu Konfiguration).

**`ConfigurationService::saveBranch()`:**
- Neue Validierung: `country_code` muss aus einer festen, im Code gepflegten Liste unterstützter Länder stammen (bewusst kein Freitext, um Tippfehler mit Auswirkung auf Steuerlogik auszuschließen).

### Nicht-Ziele (bewusst außerhalb dieses Tickets)
- Keine automatische Umrechnung bestehender Katalogpreise zwischen Ländern.
- Keine Mehrsprachigkeit der Oberfläche (separates Thema).
- `SWISS_QR` und `FACTURX` werden als Aufzählungswerte bereits vorgesehen, aber in diesem Ticket **nicht** implementiert (folgt in den jeweiligen Länder-Tickets) – das verhindert eine zweite Migration in Ticket B/C.

### Geschätzter Aufwand
Klein bis mittel – eine Migration, eine neue kleine Konfigurationsklasse, punktuelle Änderungen an drei bestehenden Services. Kein neues UI-Konzept, nur zusätzliche Felder in bereits vorhandenen Formularen (Niederlassungsverwaltung, Systemeinstellungen).

---

## TICKET B — Länderprofil Österreich

**Abhängigkeit:** Ticket A muss abgeschlossen sein.

### Kontext
Österreich hat keine landesweit einheitliche Kostenvoranschlags- oder E-Rechnungspflicht im B2C-Geschäft (anders als Frankreich bzw. die geplante Schweiz-QR-Pflicht). Der Aufwand ist daher überwiegend Konfiguration und Vorlagenarbeit, keine neue Zahlungs- oder Validierungslogik.

### Akzeptanzkriterien
- [ ] Länderprofil `AT` ist über Ticket A als Option wählbar; zulässige Steuersätze für eine `AT`-Installation sind `0, 10, 13, 20` Prozent (Normalsatz 20 %, ermäßigt 13 % bzw. 10 %).
- [ ] Für eine Niederlassung mit `country_code = AT` zeigen Stammdatenblatt, Sterbefallanzeige-Äquivalent und Kostenvoranschlag österreichische Begriffe (z. B. „Standesamt“ bleibt korrekt, aber Fristangaben/Formularverweise werden je Bundesland konfigurierbar, siehe unten) statt deutscher Rechtsverweise.
- [ ] Ein neues, konfigurierbares Feld **Bundesland** an der Niederlassung (`AT` erlaubt zusätzlich: Wien, Niederösterreich, Oberösterreich, Steiermark, Tirol, Kärnten, Salzburg, Vorarlberg, Burgenland), das ausschließlich die Vorlagentexte und Standard-Fristen steuert, **nicht** die Kernlogik von Fall, Workflow oder Rechnung verändert.
- [ ] Mindestens die zwei bis drei Bundesländer mit dem größten erwarteten Kundenanteil (Vorschlag: Wien, Niederösterreich, Oberösterreich) haben vollständig gepflegte Vorlagen; weitere Bundesländer sind als „Vorlage folgt“ erkennbar markiert, nicht stillschweigend mit falschen (deutschen) Texten befüllt.
- [ ] `EInvoiceService`/`paymentQrStandard` bleiben für `AT` unverändert auf `EPC069-12` – Österreich nutzt SEPA, keine Sonderlogik nötig.
- [ ] Rechnungen an öffentliche Auftraggeber (z. B. eine städtische Friedhofsverwaltung) sind **nicht** Teil dieses Tickets (ebInterface/PEPPOL-B2G bleibt optionales Folgeticket bei konkretem Kundenbedarf).

### Technische Vorgaben
- Neues Feld `federal_state` an `bestatter_branches`, nur auswertbar/sichtbar wenn `country_code = AT` (analog zur bestehenden Sichtbarkeitssteuerung von Feldern im Customizing, das Muster existiert bereits für andere länderspezifische Formularabschnitte).
- Neuer Vorlagen-Namespace unter `resources/templates/AT/` mit eigenem `document-templates.json`-Auszug für die AT-Fälle; `DocumentService` wählt den Namespace anhand `branch.country_code` (Rückfallebene: bestehende deutsche Vorlagen, damit ein Fehlen einer AT-Vorlage nicht zum Blocker wird, sondern zu einer klar erkennbaren Warnung führt).
- Kein neues Freigabe- oder Prüf-Workflow nötig; bestehende `generateTemplate()`-Logik wird nur um die Namespace-Auflösung erweitert.

### Testfälle für die Abnahme
- Neue Niederlassung mit `country_code = AT`, Bundesland Wien anlegen; Kostenvoranschlag erzeugen; Steuersatzauswahl zeigt 10/13/20 %, nicht 7/19 %.
- Fallanlage unter dieser Niederlassung; Sterbefallanzeige-Vorlage zeigt Wiener Textbausteine statt deutscher.
- Niederlassung mit `country_code = AT`, Bundesland ohne gepflegte Vorlage (z. B. Vorarlberg): System zeigt eine eindeutige Meldung „Vorlage für dieses Bundesland liegt noch nicht vor“ statt einer falschen deutschen Vorlage oder eines Fehlers ohne Erklärung.

### Geschätzter Aufwand
Klein – überwiegend Vorlagenarbeit und ein Sichtbarkeits-/Auswahlfeld, keine neue Geschäftslogik.
