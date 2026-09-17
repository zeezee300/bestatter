# Offene Punkte (OP-Liste)

## OP-001 – Freigabe- und Vier-Augen-Workflow

- Status: bewusst zurückgestellt
- Ziel: Für definierte Dokumente, Zahlungen und sensible Fallstatuswechsel wird eine zweite berechtigte Person zur Freigabe benötigt.
- Vor Umsetzung festzulegen: freigabepflichtige Vorgänge, Rollen und Vertretung, Rückweisung, Änderungsverbot nach Freigabe, Protokollierung und Benachrichtigungen.
- Abnahmekriterium: Ersteller und Freigeber sind verschieden; jede Entscheidung ist mit Benutzer, Zeit, Version und Begründung revisionsnah dokumentiert.

## OP-002 – Automatischer E-Mail-Versand

- Status: Stufe 1 lokal umgesetzt, in 0.64.2 auf die zentrale Nextcloud-Mailkonfiguration umgestellt; Live-Abnahme mit echtem Geschäfts-Postfach und fachliche Freigabe stehen aus. Der spätere automatische Versand bleibt offen.
- Stufe 1: Ein Bestatter-Administrator versendet eine registrierte finale PDF nach Vorschau und ausdrücklicher Empfänger-/Anlagenbestätigung über Nextclouds serverseitigen Mailer aus einer zentral konfigurierten Geschäftsadresse. Die App speichert jeden Versuch vor dem externen Versand in einer Outbox mit eindeutiger Versandkennung. Wiederholungen derselben Kennung senden nicht erneut; bei einem neuen Versuch an denselben Empfänger ist eine Begründung erforderlich. Status „ACCEPTED“ bedeutet nur Annahme durch den Mailserver, nicht Zustellung; „UNCERTAIN“ verlangt eine manuelle Prüfung des Postausgangs und wird nie automatisch wiederholt.
- Konfiguration: Ab 0.64.2 wird der zentrale Nextcloud-Systemabsender verwendet; separate App-Werte `business_mail_from` und `business_mail_enabled` entfallen. Die gültige Nextcloud-Profiladresse der angemeldeten Person wird als `Reply-To` gesetzt, andernfalls bleibt die Systemadresse zuständig. Bei gültiger Mailkonfiguration ist der Versand für Bestatter-Administratoren und die per Nextcloud-UID zugeordnete Person verfügbar; der Zugriff auf PDFs bleibt bis OP-040 vom persönlichen Fallordner abhängig.
- Abgrenzung zu BEST-116: Die lokale Aktion „Mit Mailprogramm teilen“ übergibt nur eine Datei an den Systemdialog und setzt weder Empfänger noch Versandstatus. Sie ist kein Versand und kein Versandnachweis; die hier geforderten Prüf-, Freigabe- und Protokollierungsregeln bleiben vollständig offen.
- Offen: reales SMTP-/Mailbox- und Migrations-Abnahmetesting, Antwort- und Bounce-Bearbeitung, Zustellstatusabgleich, fachliche Freigabe von Rechnungs-/Vertragsversand, Aufbewahrung und Export der Nachrichten, Mehrbenutzer-/Fallberechtigung nach OP-040 sowie spätere automatische Workflows und optionaler Vier-Augen-Schritt nach OP-001.
- Abnahmekriterium für Stufe 1: Kein Versand ohne serverseitige Empfänger-, Rollen-, Fall- und PDF-Prüfung; Versuch und unklarer Status sind am Fall nachvollziehbar; Doppelklick erzeugt keine zweite Nachricht. Vor Aktivierung im Echtbetrieb muss das Geschäfts-Postfach im Testsystem samt Postausgang und Fehlerszenarien manuell geprüft werden.
- Detailticket: `docs/TICKET-BEST-119-GESCHAEFTSPOSTFACH-VERSAND.md`.

## OP-003 – Visueller DOCX-Vorlageneditor und Feldkatalog

- Status: als weiterer Entwicklungsschritt aufgenommen
- Ziel: Dokumentvorlagen direkt aus dem Customizing in Nextcloud öffnen oder in einem geeigneten Editor bearbeiten; Platzhalter werden aus einem verständlich beschrifteten Feldkatalog ausgewählt statt als technische Schlüssel eingegeben.
- Zu prüfen: belastbare Editor-Integration für die installierte EuroOffice-App, Schreib-/Download-Funktion des Office-Servers, Platzhalter in Kopf-/Fußzeilen und Tabellen sowie eine echte gerenderte Dokumentvorschau.
- Abnahmekriterium: Administratoren können eine Vorlage öffnen, zulässige Felder auswählen, speichern und vor der Erzeugung mit Testdaten als Dokument ansehen.

## OP-004 – PDF-Konvertierung mit EuroOffice

- Status: in 0.19.1 technisch umgesetzt; Server-Abnahme der automatischen EuroOffice-Konvertierung offen
- Umsetzung: PDF-Erzeugung verwendet bevorzugt die öffentliche Nextcloud-Schnittstelle `OCP\Files\Conversion\IConversionManager`. Wenn dort kein Provider registriert ist, wird der vorhandene EuroOffice-Nextcloud-Connector mit dessen signierter Download- und Converter-Schnittstelle verwendet.
- Abnahmekriterium: DOCX bleibt bei einem PDF-Fehler erhalten; eine erfolgreiche Konvertierung erzeugt eine gültige, versionierte PDF-Datei im gleichen Fallordner. Es wird kein zusätzlicher öffentlicher Download-Endpunkt bereitgestellt.

## OP-005 – Zahlungseingänge, offene Posten und Mahnwesen

- Status: ausdrücklich zurückgestellt; außerhalb des aktuellen Umfangs „bis Rechnungsstellung“
- Ziel eines späteren Folgeausbaus: Teil-/Vollzahlungen, Rechnungsstorno, Gutschriften, offene Posten, Fälligkeit, Mahnstufen und buchhalterische Übergabe nachvollziehbar abbilden.
- Abnahmekriterium: Zahlungen verändern den Rechnungsstatus reproduzierbar; OP-Saldo, Mahnstatus und jede Korrektur sind revisionsnah protokolliert.

## OP-016 – Eingangsrechnungen, Fremdkosten und echte durchlaufende Posten

- Status: Grundprozess ohne externe Abhängigkeit in 0.38.0 umgesetzt; reale Abnahme offen
- Umsetzung 0.38.0: Eingangsrechnung und Originalbeleg werden fallbezogen unter `06 Abrechnung` erfasst. Kopf- und Positionswerte, Dublettenprüfung, Summenabgleich, steuerliche Klassifikation, Zuordnung zu vorhandenen Leistungen, einmalige Zusatzleistung, Abweichungsbegründung, Status und Audit funktionieren ohne Paperless-ngx.
- Fachliche Abgrenzung: Eine weiterberechnete Fremdrechnung ist nicht automatisch ein echter durchlaufender Posten. Die steuerliche Klassifikation wird als Stammdaten-/Prüfentscheidung geführt und mit der Steuerberatung abgestimmt.
- Führendes System in Stufe 1: Nextcloud führt die Belegdatei; die Bestatter-App führt Fallzuordnung, Lieferanten- und Kundenwerte, Abweichungen, Klassifizierung, Status und Übernahme. Paperless ist keine Voraussetzung.
- Datenprinzip: Katalogwert, Auftragssnapshot, tatsächlicher Lieferantenbetrag und Kunden-Rechnungsbetrag bleiben getrennt. Historische Auftragswerte werden nicht rückwirkend überschrieben.
- Steuerliche Klassifizierung: `TRUE_PASS_THROUGH`, `THIRD_PARTY_SERVICE`, `EXPENSE_FEE`, `NOT_BILLABLE` oder `CLASSIFICATION_PENDING`.
- Abnahmekriterium: Vom Fall aus lässt sich der Nextcloud-Beleg öffnen. Vorhandene, abweichende und ungeplante Positionen können ohne Änderung historischer Auftragsdaten zugeordnet und genau einmal übernommen oder als nicht abrechenbar markiert werden. Jede Übernahme verweist auf Beleg, Benutzer und Zeitpunkt; Belegdatei und Lieferanten-Rechnungsnummer werden auf Dubletten geprüft.
- Detailkonzept: `docs/KONZEPT-EINGANGSRECHNUNGEN-DURCHLAUFENDE-POSTEN.md`.

## OP-022 – Optionale Paperless-ngx-Anbindung für Eingangsrechnungen

- Status: Connector-Grundlage und zentraler Belegeingang in 0.48.0 umgesetzt; OCR-Auftragserfassungsbogen in 0.49.0 umgesetzt; reale Integrationsabnahme offen
- Voraussetzung: Der in 0.38.0 umgesetzte eigenständige Eingangsrechnungsprozess bleibt führende Fachlogik und muss bei Ausfall oder Nichtinstallation von Paperless vollständig funktionieren.
- Ziel: Paperless übernimmt optional E-Mail-/Scanner-Eingang, OCR, Volltextsuche, Vorschau und Archivmetadaten. Über eine serverseitige API/Webhook-Anbindung werden nur Originalreferenz, Prüfsumme und vorgeschlagene Metadaten an den vorhandenen Bestatter-Eingangsbeleg übergeben.
- Sicherheitsmodell: Zugangsdaten ausschließlich serverseitig; idempotente Webhooks; keine ungeprüfte OCR-Faktura; keine zweite fachliche Statuslogik in Paperless.
- Abnahmekriterium: Derselbe Prozess kann wahlweise mit Nextcloud-Datei oder Paperless-Referenz arbeiten. Paperless-Ausfall verhindert weder Fallbearbeitung noch manuelle Belegerfassung und erzeugt keine Dubletten.
- Umsetzung 0.48.0: verschlüsselte serverseitige Konfiguration, Verbindungstest, asynchroner API-Upload, Task-Polling, persistente Retry-Warteschlange, idempotenter Webhook, globale Arbeitsliste, kontrollierte Fallzuordnung und Kopie des Originals in den Nextcloud-Fallordner.
- Umsetzung 0.49.0: OCR-/Metadatenabruf für klassifizierte Auftragserfassungsbögen, deterministische Label-Extraktion, Konfidenzanzeige, Splitansicht, ausdrückliche Übernahme und Ablage des Originals. Rechnungs-OCR, XRechnung-/ZUGFeRD-Extraktion sowie Lieferanten- und Positionsvorschläge bleiben getrennt zurückgestellt. Erkennungswerte bleiben immer bestätigungspflichtig.
- Detail- und Betriebskonzept: `docs/PAPERLESS-INTEGRATION.md`.

## OP-023 – Lieferantenbestellung und optionaler Drei-Wege-Abgleich

- Status: bewusst als späterer Ausbau zurückgestellt; für den jetzigen Eingangsrechnungsprozess nicht erforderlich
- Vorhandene Grundlage: KVA/Auftrag erzeugt fallbezogene Leistungssnapshots. Eingangsrechnungen können diesen vorhandenen Fallleistungen oder einer einmaligen Zusatzleistung zugeordnet werden.
- Ziel: Für tatsächlich aus der App beauftragte Fremdleistungen einen eigenen Beschaffungsbeleg mit Lieferant, Bestellnummer, Positionen, vereinbarten Mengen/Einkaufspreisen, Termin und Status führen. Optional wird ein Leistungs-/Wareneingang mit Teilmengen dokumentiert.
- Dokumente: Bestellformular als konfigurierbare DOCX/PDF-Vorlage im Fallordner; kein automatischer Versand ohne eigene Freigabe.
- Prüfung: Zwei-Wege-Abgleich Rechnung–Bestellung oder Drei-Wege-Abgleich Bestellung–Leistungseingang–Rechnung mit Toleranzen und begründeten Abweichungen. Rechnungen ohne vorherige Bestellung bleiben zulässig, müssen aber als ungeplante Fremdkosten begründet werden.
- Abnahmekriterium: Historische Bestellwerte bleiben unverändert; jede Rechnungsposition verweist eindeutig auf Bestellung/Leistungseingang oder eine begründete Direktzuordnung. Teilmengen und parallele Bearbeitung führen nicht zu Doppelübernahmen.

## OP-017 – Explizite Teil- und Schlussrechnungen

- Status: in 0.42.0 einschließlich freier Positions-/Teilmengenauswahl und Leistungszeitraum umgesetzt; reale End-to-End-Abnahme offen
- Vorhandene Grundlage: Erbrachte, fakturierte und noch abrechenbare Mengen werden bereits getrennt geführt; dadurch sind mehrere zeitlich aufeinanderfolgende Rechnungen technisch vorbereitet.
- Umsetzung 0.36.0: Rechnungen werden ausdrücklich als Teil- oder Schlussrechnung angelegt, je Fall fortlaufend gekennzeichnet und mit der Summe früherer Rechnungen dokumentiert. Teilrechnungen tolerieren noch nicht erbrachte Auftragspositionen als Warnung; eine nicht stornierte Schlussrechnung sperrt weitere Rechnungsentwürfe. Nummernvergabe, Mengensperre, Snapshot und Audit bleiben transaktional.
- Umsetzung 0.42.0: Rechnungsart, frei auswählbare Positionen, Teilmengen, Leistungszeitraum, bereits fakturierte Beträge und Restmengen werden je Rechnungsentwurf geführt. Die Prüfung einer Teilrechnung berücksichtigt nur globale Fehler und die ausgewählten Positionen; Positionssnapshots und Mengensperren bleiben transaktional.
- Abnahmekriterium: Mehrere Teilrechnungen können aus demselben Fall erstellt werden, ohne Mengen oder Eingangsrechnungspositionen doppelt zu fakturieren; jede Rechnung behält einen unveränderlichen Positionssnapshot.

## OP-006 – ZUGFeRD-PDF/A-3-Einbettung und Konformitätsprüfung

- Status: XML-Erzeugung in 0.20.0 umgesetzt; formale Validierung und Einbettung offen
- Ziel: Den erzeugten EN-16931-CII-Datensatz als `factur-x.xml` normgerecht in ein PDF/A-3-Rechnungsdokument einbetten und das Gesamtdokument gegen ZUGFeRD 2.5.2/EN16931 validieren.
- Voraussetzung: serverseitig verfügbare PDF/A-3-Bibliothek sowie ein reproduzierbarer KoSIT-/ZUGFeRD-Validator. Die aktuelle EuroOffice-Konvertierung erzeugt PDF, stellt der App aber keine nachgewiesene PDF/A-3-Einbettungsschnittstelle bereit.
- Abnahmekriterium: PDF/A-3- und XML-Validierung laufen fehlerfrei; eingebettete Datei, Profilkennung, Summen, Steuergruppen und Zahlungsdaten stimmen mit der sichtbaren Rechnung überein.

## OP-007 – Eigenständiges Modul für Leistungen und Artikel

- Status: Architektur vorbereitet; CSV-Rundlauf in 0.22.0 umgesetzt
- Übergangslösung: Der bestehende Katalog bleibt die führende Datenquelle. Ein CSV-Export kann in Excel oder LibreOffice gepflegt und anschließend wieder importiert werden. Identische Artikelnummern aktualisieren vorhandene Datensätze; `delete=true` löscht unbenutzte Artikel beziehungsweise deaktiviert historisch verwendete Artikel.
- Empfehlung: Keine direkte Verknüpfung einer dauerhaft geöffneten Excel-Datei als Datenbank. Dateisperren, konkurrierende Bearbeitung, fehlende Transaktionen und unkontrollierte Spaltenänderungen würden Aufträge und Rechnungen gefährden.
- Zielbild: Eigenständige Nextcloud-App mit stabiler Katalog-API, Berechtigungen, Änderungsprotokoll, Gültigkeitszeiträumen, Lieferantenpreisen, Preislisten sowie Importvorschau mit fachlicher Validierung. Die Bestatter-App greift dann ausschließlich über diese API auf Artikel-Snapshots zu.
- Abnahmekriterium: Änderungen werden vor Übernahme als Neu/Geändert/Deaktiviert/Fehlerhaft dargestellt; verwendete Artikel bleiben als unveränderlicher Auftragssnapshot erhalten und parallele Pflege erzeugt keine Dubletten.

## OP-008 – Migrations-Baseline für eine Hauptversion

- Status: in 0.26.0 vor der ersten echten Veröffentlichung umgesetzt
- Umsetzung: Die Entwicklungsmigrationen `0100` bis `1700` wurden durch die vollständige, idempotente Baseline `1800` ersetzt. Sie baut eine leere Installation vollständig auf und ergänzt den bestehenden Testserver ohne Löschung von Fachdaten.
- Abnahmekriterium: Upgrade des aktuellen Teststands und Neuinstallation auf leerer Datenbank müssen vor der ersten Veröffentlichung erfolgreich geprüft werden. Ab der ersten Veröffentlichung bleibt Migration 1800 unverändert bestehen.

## OP-009 – Zentraler Terminartenkatalog und Workflow-Referenzen

- Status: Terminartenkatalog und verpflichtender Ressourcenbedarf in 0.44.0 umgesetzt; fachliche Abnahme offen
- Umsetzung 0.27.0: Direkte Terminanlage verwendet einen gemeinsamen Auswahlkatalog und ergänzt aktive `SCHEDULE`-Aktionen bestehender Workflows. Die Auswahl übernimmt Terminart, Kategorie, Titel, Dauer und Priorität, ohne die anschließende Bearbeitung einzuschränken.
- Umsetzung 0.42.0: Terminarten sind eigenständige Stammdaten mit fachlicher Einordnung, Standarddauer sowie Vor-/Nachbereitungszeiten. Direkte Termine und Workflow-Vorlagen werden in einer Auswahl zusammengeführt.
- Umsetzung 0.44.0: Mindestbedarf für Mitarbeitende, Fahrzeuge, Räume, Kapellen und Ausstattung sowie konkrete Standardressourcen werden je Terminart administrativ gepflegt und bei der Speicherung serverseitig erzwungen. Teilnehmerrollen und Erinnerungsprofile bleiben Folgeausbau.
- Abnahmekriterium: Eine Änderung an einer Terminart wirkt konsistent bei direkter Anlage und workflowgesteuerter Erzeugung; bestehende Termine bleiben als unveränderlicher Snapshot nachvollziehbar.

## OP-010 – Disposition, Ressourcen und Konfliktprüfung

- Status: Grundausbau und Alternativtermine in 0.44.0 umgesetzt; reale Mehrbenutzer-/Kalenderabnahme offen
- Umsetzung 0.42.0: Mitarbeiter sowie administrativ gepflegte Fahrzeuge, Räume/Kapellen und sonstige Ressourcen werden mit Termin-Dauer und Vor-/Nachlaufzeit serverseitig auf Überschneidung geprüft. Nicht konfliktrelevante Ressourcen können bewusst ausgenommen werden; eine Übersteuerung ist nur mit Begründung zulässig.
- Umsetzung 0.44.0: Konflikte liefern bis zu drei verständliche Ersatzzeiten im 30-Minuten-Raster; diese können im Terminformular übernommen werden. Arbeitszeitmodelle, Abwesenheiten und Nextcloud-Free/Busy bleiben gemäß `TERMINPLANUNG-UND-VERFUEGBARKEIT.md` Folgeausbau.
- Abnahmekriterium: Trauerfeier, Überführung oder Beisetzung kann nicht unbemerkt mit bereits gebundenem Personal/Fahrzeug/Raum kollidieren; berechtigte Übersteuerung wird begründet protokolliert.

## OP-011 – Terminstatus, Absage, Erinnerungen und Änderungsverlauf

- Status: Status, Absagesynchronisation und nachvollziehbarer Änderungsverlauf in 0.44.0 umgesetzt; Erinnerungsprofile und reale Abnahme offen
- Ziel: „Erledigt“ und „Abgesagt“ als getrennte fachliche Zustände, Erinnerungen je Terminart, Ende/Dauer, Änderungsgrund sowie Historie von Zeit-, Ort- und Teilnehmeränderungen.
- Umsetzung 0.42.0: Erledigte Termine werden nicht als `CANCELLED` übertragen; Dauer wird synchronisiert. Anlage, Änderung und Absage erzeugen einen Snapshot mit Benutzer, Zeitpunkt und optionalem Änderungsgrund, der im Terminformular angezeigt wird.
- Umsetzung 0.44.0: Absagen werden als iCalendar `CANCELLED` synchronisiert, erledigte Termine bleiben als durchgeführt erhalten. Wesentliche Änderungen verlangen einen Grund; die Historie zeigt die betroffenen Alt-/Neu-Werte.
- Abnahmekriterium: Absage und Durchführung sind in Kalender, Fallakte und Verlauf eindeutig; Änderungen nennen Benutzer, Zeitpunkt, alten und neuen Wert.

## OP-012 – Aufgabenübersicht für hohe Fallzahlen

- Status: Darstellung in 0.27.0 bereinigt; Ausbau offen
- Umsetzung 0.27.0: Der zusammengezogene Nextcloud-Tag `Bestatter,2026-…` wird nicht mehr erzeugt. Die Fallnummer bleibt im Titel und als strukturiertes Metadatum erhalten.
- Ziel: Gruppierung nach Fall, Fälligkeit und Zuständigkeit, Schnellfilter „heute/überfällig/meine“, Sammelaktionen sowie klare Trennung von Checklistenaufgaben und freien Aufgaben. Wiederholte Standardaufgaben verschiedener Fälle dürfen nicht wie Dubletten wirken.
- Abnahmekriterium: Ein Mitarbeiter findet bei mehreren hundert offenen Aufgaben die nächsten relevanten Schritte ohne lineares Durchscrollen und kann die Fallakte direkt öffnen.

## OP-013 – Durchgängige Abnahme von Bedienbarkeit und Barrierefreiheit

- Status: Grundprüfung in 0.27.0 dokumentiert; Toasts, Ladezustände, In-App-Dialoge, erste Fokus-/ARIA-Regeln und mobile Touch-Ziele in 0.29.0 umgesetzt; systematische Testmatrix offen
- Ziel: Tastaturbedienung, sichtbarer Fokus, Zoom bis 200 %, Screenreader-Bezeichnungen, responsive Ansichten sowie definierte Leer-, Lade-, Fehler- und Konkurrenzzustände für alle Hauptprozesse.
- Abnahmekriterium: Die Testmatrix wird für Beratung, Fallanlage, Auftrag/KVA, Termine, Dokumente/Abmeldungen, Leistungserfassung, Rechnung und Fallabschluss ohne kritischen UX- oder Datenverlustfehler bestanden.

## OP-015 – Persönlich speicherbare Filter und Wochenfälligkeit

- Status: feste serverseitige Schnellansichten und Fall-Pagination in 0.29.0 umgesetzt; frei benennbare Benutzeransichten offen
- Ziel: Benutzer können kombinierte Fall-, Aufgaben- und Terminfilter unter einem eigenen Namen speichern. Die Ansicht „Diese Woche fällig“ verknüpft Fälle serverseitig mit offenen Aufgaben, Fristen und externen Terminen statt nur bereits geladene Browserdaten auszuwerten.
- Abnahmekriterium: Gespeicherte Ansichten gelten benutzerbezogen, bleiben nach Neuanmeldung erhalten und liefern auch bei mehreren tausend Fällen vollständige, paginierte Ergebnisse.

## OP-014 – Vollständiger Fallabschluss und Aufbewahrung

- Status: offen
- Ziel: Abschlussprüfung mit Pflichtschritten, offenen Aufgaben/Terminen, Abmeldungen, Dokumenten, Leistungen, Rechnungen und Zahlungen; kontrolliertes Wiederöffnen; Aufbewahrungs-, Lösch- und Archivregeln sowie Backup-/Restore-Test.
- Abnahmekriterium: Ein Fall kann nur mit transparenten Ausnahmen abgeschlossen werden; Wiederöffnung und fristgerechte Löschung sind berechtigt und revisionsnah dokumentiert.

## OP-018 – Geführte Schnellerfassung, Sprache und KI-Assistent

- Status: Ausbaustufen 1 bis 3 einschließlich lokaler Spracheingabe, Fortschrittsanzeige, Feature-Policy, regelbasiertem Fallback, sicherer Assistenten-Seitenleiste, Tagesübersicht und Prozessvollständigkeit in 0.33.0 umgesetzt. Version 0.34.0 ergänzt kontrollierte Organisationsrecherche, bestätigte Kontaktanlage, fachliche Rückfragen und den Kondolenzlisten-Ausgabeprozess. Version 0.34.1 generalisiert diesen Ansatz auf alle aktiven DOCX-Dokumentvorlagen; Rechnungen verbleiben im geschützten Finanzprozess. Version 0.36.1 ergänzt eine wiederaufnehmbare Verzögerungswarnung für Speech-to-Text und weitere Sprachvarianten/Felder der Schnellerfassung. Version 0.40.1 ergänzt die bestätigte Übernahme erkannter Daten in bestehende Sterbefälle und die wiederholungssichere Anlage mehrerer Aufgaben aus Notizen. Reale Fachabnahme auf dem Testserver offen.
- Ziel: Auftragserfassung und operative Bedienung durch geführte Erfassung, lokale Spracheingabe, strukturierte Feldvorschläge und einen kontextbezogenen Bestatter-Assistenten beschleunigen.
- Leitplanke: KI bereitet ausschließlich nachvollziehbare Vorschläge und Entwürfe vor. Schreibende Aktionen benötigen eine Vorschau und ausdrückliche Benutzerbestätigung; Versand, Finalisierung, Erledigung, Rechnungsnummernvergabe und Löschung erfolgen nicht autonom.
- Architektur: Nextcloud Task Processing als Providerabstraktion, bevorzugt lokale Speech-to-Text- und LLM-Verarbeitung, strikt typisierte Vorschlagsschemata, erlaubte Intent-Positivliste, bestehende Fachservices als einzige schreibende Instanz sowie vollständige Berechtigungs- und Auditprüfung.
- Einführung: Alle drei Stufen werden zusammen entwickelt, aber per Feature-Flags getrennt aktiviert und nacheinander abgenommen. Die manuelle Bedienung bleibt jederzeit vollständig nutzbar.
- Abnahmekriterium: Ein synthetischer Musterfall kann geführt und per Sprache als geprüfter Entwurf erfasst werden; der Assistent kann definierte Aufgaben, Termine und Dokumentaktionen vorbereiten, ohne ungeprüfte Datenänderung oder Seiteneffekt. Providerausfall führt zu verständlichem Feedback und nicht zu Datenverlust.
- Detailkonzept: `docs/KONZEPT-KI-SPRACHE-AUFNAHMEASSISTENT.md`.

## OP-019 – Provider-neutrale LLM-Erweiterung des Bestatter-Assistenten

- Status: bewusst zurückgestellt; regelbasierter Assistent in 0.36.0 erweitert
- Ausgangslage: Der Assistent verfügt über einen transparenten Positivkatalog für Fallsuche, Tagesübersicht, Stammdaten, Aufgaben, Termine, Checklisten, Leistungen, Abmeldungen, Dokumente, Teil-/Schlussrechnung und Systemprüfung. Ohne LLM bleiben alle Fachfunktionen vollständig bedienbar.
- Ziel: Optionaler Adapter über Nextcloud Task Processing für einen administrativ freigegebenen lokalen oder externen Textanbieter (z. B. OpenAI oder Anthropic), ohne providerbezogene Logik in Fachservices oder Benutzeroberfläche einzubauen.
- Datenschutz: Externe Übermittlung ist standardmäßig deaktiviert. Vor Aktivierung sind Auftragsverarbeitung, Region, Protokollierung, Aufbewahrung, Trainingsausschluss und zulässige Datenklassen festzulegen. Personen- und Falldaten dürfen nur nach expliziter Freigabe und Datenminimierung übertragen werden.
- Sicherheitsmodell: Ein LLM darf ausschließlich strukturierte Vorschläge aus der erlaubten Intent-Liste liefern. Berechtigungen, Validierung, Vollständigkeitsprüfungen, Bestätigungs-Token, Fachtransaktionen und Audit werden weiterhin ausschließlich serverseitig durch die Bestatter-App erzwungen.
- Abnahmekriterium: Anbieterwechsel und Providerausfall verändern keine Fachfunktion; nicht erlaubte Aktionen werden verworfen; jeder schreibende Vorschlag besitzt verständliche Vorschau, ausdrückliche Benutzerbestätigung und Audit-Eintrag.

## OP-020 – Kontrollierte Übergabe aus Nextcloud Talk

- Status: Konzept aufgenommen; Umsetzung offen
- Ziel: Textnachrichten, Sprachnachrichten und freigegebene Talk-Aufzeichnungen gezielt an die Bestatter-Schnellerfassung oder einen ausgewählten Fall übergeben. Die Quelle (Unterhaltung, Nachricht, Absender, Zeitpunkt und Dateireferenz) bleibt nachvollziehbar.
- Stufe 1: In der Bestatter-App eine Talk-Nachricht beziehungsweise eine in Nextcloud abgelegte Talk-Audiodatei auswählen und als Transkriptions-/Gesprächsentwurf übernehmen.
- Stufe 2: Optionaler Talk-Bot „Bestatter“ auf Basis der signierten Talk-Bot-Schnittstelle. Der Bot fragt Fallbezug und gewünschte Aktion ab und liefert nur eine Vorschau beziehungsweise einen Übergabeentwurf.
- Datenschutz und Sicherheit: Nur Mitglieder der Gruppe Bestatter, explizit freigegebene Unterhaltungen, keine Übernahme von Gastnachrichten ohne Prüfung, keine autonome Fallanlage/-änderung, kein Versand und keine Erledigung. Schreibende Schritte verwenden weiterhin Berechtigungsprüfung, Bestätigungs-Token und Audit-Protokollierung der Fach-App.
- Abnahmekriterium: Eine Text- und eine Sprachnachricht können einem neuen Entwurf oder einem bestehenden Fall zugeordnet werden; Dubletten, fehlender Fallbezug, Providerausfall und widerrufener Dateizugriff führen zu verständlichen Rückfragen, ohne Datenverlust.

## OP-021 – Lernfähige, fachsprachliche Schnellerfassung

- Status: aufgenommen; Umsetzung nach Abschluss der Abnahme von 0.36.x vorgesehen
- Priorität: hoch, da die Erkennungsqualität unmittelbar den Zeitaufwand und die Datenqualität der Erstaufnahme bestimmt
- Ziel: Gesprächsdaten aus Diktaten, Transkripten und Texteingaben zuverlässig filtern, dem üblichen Sprachgebrauch eines Bestattungsunternehmens anpassen und möglichst vollständig den passenden Feldern der Schnellerfassung zuordnen.
- Architektur: Hybride Verarbeitung aus Normalisierung, fachlich getrennten deterministischen Extraktoren, konfigurierbarem Synonym- und Formulierungslexikon, Kontext- und Beziehungsregeln, Feldvalidierung sowie einem optionalen späteren LLM-Fallback. Manuelle Erfassung und deterministischer Parser bleiben ohne KI vollständig nutzbar.
- Nachvollziehbarkeit: Jeder Feldvorschlag enthält Wert, Konfidenz, zugrunde liegenden Textausschnitt, verwendeten Extraktor beziehungsweise Regelversion und – bei abgeleiteten Angaben – eine verständliche Begründung. Sichere, unsichere und widersprüchliche Vorschläge werden in der Prüfansicht deutlich unterschieden.
- Kontextregeln: Zusammengehörige Aussagen werden satzübergreifend ausgewertet, beispielsweise „verheiratet mit ihrem Mann Gerd“ als Familienstand und Ehepartner sowie „drei weitere gebührenpflichtige“ im Kontext zuvor genannter Sterbeurkunden. Abgeleitete Werte werden nicht als direkt erkannte Tatsachen ausgegeben.
- Kontrolliertes Lernen: Benutzerkorrekturen werden mit anonymisierbarem Textausschnitt, erkanntem und korrigiertem Wert, Parser-Version, Benutzer und Zeitpunkt als Lernvorschlag gespeichert. Neue globale Sprachregeln werden erst nach administrativer Prüfung aktiviert; ein unkontrolliertes Online-Training mit Falldaten ist ausgeschlossen.
- Administration: Pflege, Test, Import und Export von Synonymen, Formulierungsvarianten und Fachbegriffen; Übersicht häufiger Nicht-Erkennungen und Korrekturen; Freigabe oder Verwerfung vorgeschlagener Regeln; Regressionstest gegen einen versionierten Bestand synthetischer und anonymisierter Beispielsätze.
- Datenschutz: Audio- und Falldaten werden nicht zu Trainingszwecken verwendet. Aufbewahrung, Anonymisierung, Berechtigungen und Audit folgen den Vorgaben aus OP-018; externe Verarbeitung bleibt standardmäßig deaktiviert.
- Vorgesehene Stufen: 0.37 modularisiert Normalisierung und Fachextraktoren und ergänzt Konfidenzen sowie Kontextbeziehungen; 0.38 ergänzt Korrekturfeedback, Administrationspflege und automatisierte Parser-Regressionstests; 0.39 kann optional einen provider-neutralen LLM-Fallback für nicht erkannte Passagen ergänzen.
- Abnahmekriterium: Ein versionierter Testsatz typischer deutschsprachiger Aufnahmegespräche befüllt alle tatsächlich genannten Zielfelder mit definierter Mindestgenauigkeit; unsichere oder widersprüchliche Angaben werden nicht still gespeichert; Korrekturen lassen sich kontrolliert in neue Regeln überführen, ohne bestehende Erkennungsfälle zu verschlechtern.

## OP-024 – Nextcloud-Dashboard-Widget

- Status: neu aufgenommen; mittlere Priorität, geringer bis mittlerer Implementierungsaufwand
- Vorhandene Grundlage: Die Bestatter-App besitzt bereits eine geschützte Dashboard-API und eine persönliche Tagesübersicht.
- Ziel: Ein natives Widget der Nextcloud-Dashboard-App zeigt „Meine offenen Fälle“, überfällige Aufgaben, heutige externe Termine und kritische Prozesshinweise. Jeder Eintrag führt per Deep-Link unmittelbar zur passenden Fallakte oder Aktivität.
- Datenschutz und Berechtigung: Das Widget liefert ausschließlich Daten des angemeldeten Bestatter-Mitglieds; Außenstehende erhalten weder Widget noch Inhalte. Es werden keine Falldaten im Browser-Cache über die bestehende Sitzung hinaus gespeichert.
- Abnahmekriterium: Das Widget kann benutzerbezogen aktiviert und angeordnet werden, zeigt dieselben Zahlen wie die Bestatter-Tagesübersicht und öffnet die korrekte Fallakte ohne erneute Suche.

## OP-025 – Globale Nextcloud-Suche für Fälle und Fallkontakte

- Status: Fall-Suchprovider in 0.40.0 umgesetzt; Suche nach zugeordneten Fallkontakten bleibt als Ausbau offen
- Ziel: Berechtigter Volltext-Suchprovider für Fallnummer, Name der verstorbenen Person, Auftraggeber, zuständigen Mitarbeiter und eindeutig zugeordnete Fallkontakte. Treffer führen direkt in die Fallakte beziehungsweise zum zugehörigen Bereich.
- Sicherheitsmodell: Der Provider prüft bei jeder Anfrage Bestatter-Mitgliedschaft und Sichtbarkeit. Sensible Zusatzdaten, Gesundheitsangaben, Bankdaten, interne Notizen und Dokumentinhalte werden weder indexiert noch als Treffertext ausgegeben.
- Technische Leitplanke: Nutzung des aktuellen Nextcloud-Unified-Search-Vertrags; begrenzte, paginierte Ergebnisse und serverseitige Normalisierung. Kontakte aus allgemeinen Adressbüchern werden nicht dupliziert, sondern nur bei bestehender Fallzuordnung referenziert.
- Abnahmekriterium: Fallnummer und Personenname sind über die globale Nextcloud-Suche auffindbar; Außenstehende und nicht zugeordnete Benutzer erhalten keine Treffer oder Metadaten.

## OP-026 – Konsolidierte serverseitige Fallliste und Pagination

- Status: Browser-Erstaufruf und Fallliste seit 0.40.0 vollständig auf serverseitige Suche/Pagination umgestellt; Lasttest mit mehr als 1.000 Fällen offen
- Vorhandene Umsetzung: `searchCases()` unterstützt serverseitig Suchbegriff, Status, Niederlassung, Zuständigkeit, Limit und Offset; die Oberfläche besitzt Pagination und die Ansicht „Meine offenen Fälle“.
- Offene Lücke: Der initiale Startpfad `listCases()` lädt weiterhin pauschal höchstens 100 Fälle und setzt daraus zunächst eine unvollständige Gesamtzahl. Dieser Parallelpfad soll entfallen oder ausschließlich auf dieselbe paginierte Such-API delegieren.
- Ziel: Eine einzige serverseitige Datenquelle für Erstaufruf, Suche, Schnellansichten und Pagination; stabile Sortierung, Gesamtzahl und Filter unabhängig von der Fallmenge. Optional werden später gespeicherte Benutzerfilter aus OP-015 angebunden.
- Abnahmekriterium: Bei mehr als 100 beziehungsweise 1.000 Testfällen stimmen Trefferzahl, Seitenwechsel und Filter; kein Fall verschwindet wegen eines vorgelagerten Browserlimits.

## OP-027 – Export und Management-Reporting

- Status: CSV-Stufe 1 in 0.40.0 umgesetzt; XLSX und DATEV bleiben als klar getrennte Ausbaustufen offen
- Stufe 1: Berechtigungsgeschützte CSV-Exporte für Fallübersicht, Leistungsstatus, Rechnungen bis zur Rechnungsstellung, Umsatz je Niederlassung und Arbeitsauslastung je Mitarbeiter. Filter und Zeitraum der Bildschirmansicht werden übernommen.
- Erweiterte interne Kennzahlen: Seit 0.48.5 können Plan-/Ist-Verhältnis, Aufgaben- und Fristtreue, Terminabsagen sowie wertbezogene Eigenleistungs-, Auslagen- und Fremdleistungsanteile als nicht in der Oberfläche beworbener, auditierter Bericht `indicators` erzeugt werden. Die Dokumentvollständigkeit bleibt bis zur fachlichen Festlegung der Pflichtdokumente je Prozessphase ausdrücklich `NOCH_NICHT_KONFIGURIERT`.
- Stufe 2 – Management-XLSX: Optionaler XLSX-Export mit mehreren Tabellenblättern, Summen, verständlichen Spaltennamen und dokumentierten Berechnungsständen.
- Stufe 3A – Buchhaltungsvorbereitung: Neutraler, noch nicht als DATEV-Import bezeichneter Übergabeexport für festgeschriebene Ausgangsrechnungen und fachlich freigegebene Eingangsrechnungen. Er enthält Belegnummer, Belegdatum, Leistungszeitraum, Brutto/Netto/Steuer, Geschäftspartner, Fall- und Niederlassungsreferenz sowie einen stabilen Exportbezug.
- Stufe 3B – DATEV-Format: Erst nach Abstimmung mit der Steuerberatung wird ein DATEV-EXTF-Buchungsstapel umgesetzt. Vorher müssen Berater-/Mandantennummer, Wirtschaftsjahr, SKR03/SKR04 beziehungsweise individueller Kontenrahmen, Erlös-/Aufwandskonten, Steuerschlüssel, Debitoren-/Kreditorenlogik, Buchungsdatum, Beleglink, Festschreibung und Dublettenschutz konfigurierbar sein. Jeder Lauf erhält Protokoll, Prüfsumme, Zeitraum und Wiederholungsschutz. Ein technisch importierbarer Stapel ist noch keine fachlich richtige Buchung.
- Stufe 3C – OPOS/Zahlungen: Zahlungseingänge, offene Posten, Mahnwesen, Storno und Gutschriften bleiben bis zur Umsetzung und fachlichen Freigabe von OP-005 außerhalb des Umfangs. Ein Export festgeschriebener Rechnungsbuchungen kann unabhängig davon vorher realisiert werden.
- Datenschutz: Exporte werden explizit durch berechtigte Benutzer ausgelöst, protokolliert und auf die notwendigen Felder beschränkt. Bankdaten, besondere personenbezogene Daten und interne Notizen sind standardmäßig ausgeschlossen.
- Abnahmekriterium: Exportwerte stimmen mit den gefilterten Bildschirmdaten und unveränderlichen Rechnungs-/Auftragssnapshots überein; Summen lassen sich reproduzierbar gegen die Datenbank abstimmen. Ein DATEV-Export gilt erst nach Importtest und schriftlicher Feld-/Kontierungsfreigabe der Steuerberatung als abgenommen.

## OP-028 – Aufbewahrungs- und Löschrichtlinie

- Status: technische Stufe 1 in 0.46.0 umgesetzt; rechtliche Freigabe und vollständiger Dateilöschlauf bleiben offen
- Vorhandene Umsetzung: Konfigurierbare Regelfrist, individuelles Fälligkeitsdatum, Legal Hold, Trockenlauf und bestätigtes OCC-Verfahren für Anonymisierung oder Löschung. Der Hintergrundjob meldet nur fällige Fälle und löscht niemals automatisch.
- Vor Umsetzung festzulegen: gesetzliche und betriebliche Aufbewahrungsfristen je Datenart, Rechtsstreit-/Prüfsperren, Archivformat, Löschfreigabe, Vier-Augen-Prinzip, Mandanten-/Niederlassungsbezug und Nachweisführung. Die Festlegung ist mit Datenschutz, Steuerberatung und gegebenenfalls Rechtsberatung abzustimmen.
- Offen: verbindliche Fristen je Datenart, technische Vier-Augen-Freigabe und Archivformat. Fallordner der konfigurierten Bestatter-Gruppen werden im bestätigten Lauf kontrolliert entfernt; externe Freigaben, E-Mail-Systeme, Papierarchive und Sicherungen bleiben separat. Audit- und Rechnungsnachweise benötigen eigene rechtlich freigegebene Regeln.
- Abnahmekriterium: Ein Trockenlauf zeigt jeden betroffenen Datensatz und Grund; eine Ausführung ist wiederholungssicher, protokolliert und beeinträchtigt keine gesperrten oder aufbewahrungspflichtigen Daten.

## OP-029 – Backup-, Restore- und Notfallkonzept

- Status: App-Snapshot, Prüfsumme, sicherer Leerziel-Restore und Betriebsdokumentation in 0.46.0 umgesetzt; realer Infrastruktur-Restore und RPO/RTO-Freigabe bleiben offen
- Ziel: Konsistente Sicherung von Nextcloud-Datenverzeichnis, Konfiguration, Custom Apps, Datenbank, Dokumentvorlagen, Verschlüsselungsschlüsseln und den für die Test-/Produktionsumgebung erforderlichen Compose-/Secret-Konfigurationen. Restore-Ziele werden mit RPO und RTO festgelegt.
- Sicherheitsmodell: Backups sind verschlüsselt, räumlich beziehungsweise technisch getrennt, unveränderbar gegen Ransomware geschützt und nur für benannte Administratoren zugänglich. Secrets stehen nicht unverschlüsselt in der Dokumentation oder im App-Backup.
- Verfahren: dokumentierter Voll-Restore in eine isolierte Umgebung; anschließend Nextcloud-Status, Datenbankkonsistenz, Fallakten, Dokumente, Suche, Aufgaben/Termine, Rechnungen, Vorlagen, Berechtigungen und Hintergrundjobs prüfen. Einzeldatei- und Einzelfallwiederherstellung gesondert bewerten.
- Turnusvorschlag: tägliche automatisierte Sicherung, laufende Überwachung des Sicherungsergebnisses, monatlicher technischer Restore-Test und mindestens jährlicher vollständiger Notfalltest. Die endgültigen Intervalle richten sich nach RPO/RTO und Datenvolumen.
- Abnahmekriterium: Ein dokumentierter Test stellt eine ausgewählte Sicherung auf einem leeren Zielsystem wieder her; Prüfsummen, Fallzahlen, Dokumentzugriff, Berechtigungen und Stichprobenrechnungen stimmen. Dauer, Fehler und Freigabe werden protokolliert.

## OP-030 – Fachliche Benachrichtigungen, Push und E-Mail

- Status: native Nextcloud-Activity-Integration in 0.41.0 umgesetzt; fachliche Erweiterungen und Versandabnahme offen
- Umgesetzt: persönliche Gruppe **Bestatter** mit E-Mail-/Push-Schaltern für Fallzuweisung, Aufgabenänderung/-erledigung, Terminänderung/-löschung, Dokument-/Rechnungsfinalisierung und Workflowfehler. Ereignisse enthalten einen sicheren Fall-Deep-Link, vermeiden Selbstmeldungen und ignorieren reine Synchronisationsmetadaten.
- Zeitsteuerung: Es gelten bewusst die Nextcloud-Standardintervalle `schnellstmöglich`, `stündlich`, `täglich` und `wöchentlich`. Eine parallele Bestatter-Intervallverwaltung und ein eigener E-Mail-Dispatcher entfallen.
- Offen: Fristwarnungen, Freigabe-/Rückweisungsereignisse, ein fachlich ausgelöster erweiterbarer Ereigniskatalog und optionale `VALARM`-Erinnerungen. Ein freier Wertelisteneintrag allein kann keinen unbekannten fachlichen Auslöser definieren.
- Abgrenzung: Activity-Daten ersetzen weder Audit-Log noch Fall-Verlauf. Benutzer dürfen Meldungen deaktivieren; die revisionsbezogene Protokollierung bleibt vollständig erhalten.
- Abnahmekriterium: Mit zwei Benutzern Fremdänderung, Kalenderlöschung, Selbständerung, Neu-Zuweisung, deaktivierten Kanal und Deep-Link prüfen. E-Mail erst nach ausdrücklicher Versandfreigabe testen.
- Detailkonzept: `docs/KONZEPT-BENACHRICHTIGUNGEN.md`.

## OP-031 – Dienstpläne und betriebliche Disposition

- Status: zurückgestellt; die Terminarten, Ressourcenpflichten und Konfliktprüfung aus 0.44.0 bleiben nutzbar.
- Umfang: persönliche Dienst-/Arbeitszeitmodelle, Bereitschaften, Abwesenheiten, Urlaube, Fahrzeuge, Räume, Kapellen und sonstige Betriebsmittel mit Verfügbarkeit, Sperrzeiten, Wartung und Zuständigkeit.
- Ziel: Die Disposition soll freie Kapazitäten, Qualifikationen, Arbeitszeitregeln und betriebliche Ressourcen gemeinsam prüfen und verständliche Alternativen anbieten. Dienstplanänderungen werden versioniert und mit Benutzer, Grund und Gültigkeitszeitraum protokolliert.
- Integrationsvorschlag: In einer ersten Stufe werden Arbeitszeit und Abwesenheiten je Bestatter in der App gepflegt; Nextcloud-Kalender liefern optional Free/Busy-Sperren. Es werden nur Verfügbarkeitsinformationen, keine privaten Kalenderdetails, übernommen. In einer späteren Stufe kann eine HR-/Dienstplanquelle über eine klar begrenzte Schnittstelle angebunden werden.
- Datenschutz: Standardmäßig werden nur „verfügbar/belegt/abwesend“, Zeitraum, Ressourcenschlüssel und erforderliche Qualifikation verarbeitet. Private Termintitel und sensible Personalinformationen bleiben außerhalb der Bestatter-App.
- Abnahmekriterium: Dienstplan, Abwesenheit, Ressourcensperre und Terminänderung führen zu derselben serverseitigen Konfliktentscheidung; alle Konflikte nennen Ursache, Zeitraum und mindestens eine verständliche Alternative. Eine Auslastungsquote darf erst nach gepflegten Kapazitätsdaten berechnet werden.

## OP-032 – Fachliche Freigabe österreichischer Regionalvorlagen

- Status: Technische Länder- und Vorlagenauflösung in 0.51.0 umgesetzt; rechtliche Inhalte offen.
- Umfang: Freigegebene Stammdaten-, Behörden- und Auftragsvorlagen zunächst für Wien, Niederösterreich und Oberösterreich; danach die übrigen Bundesländer. Quellenstand, Prüfer, Freigabedatum und Gültigkeit werden je Vorlage dokumentiert.
- Sicherheitsregel: Behördenabhängige Dokumente fallen nie still auf eine deutsche Vorlage zurück. Ohne freigegebene Regionalvorlage erscheint ein verständlicher Blocker.
- Abnahmekriterium: Je Bundesland werden die Vorlagen mit einem realistischen Musterfall erzeugt, fachlich freigegeben sowie in DOCX und PDF visuell geprüft. Gesetzes-/Formularverweise entsprechen dem dokumentierten Quellenstand.

## OP-033 – Länderspezifischer Artikelkatalog und dezimale Steuersätze

- Status: Der gemeinsame Katalog akzeptiert seit 0.51.0 die ganzzahligen Sätze der freigegebenen Profile; eine eindeutige Länderzuordnung je Artikel und Dezimalsätze bleiben offen.
- Umfang: Länder-/Gültigkeitszuordnung am Artikel, dezimale Steuersätze in Artikel-, Fallleistungs-, Eingangsrechnungs- und Rechnungs-Snapshots sowie reproduzierbare Rundungsregeln.
- Anlass: Der österreichische Satz von 4,9 Prozent für ausgewählte Nahrungsmittel sowie spätere Schweizer und französische Sätze können mit dem bisherigen Integer-Modell nicht korrekt abgebildet werden.
- Abnahmekriterium: Preis- und Steuer-Snapshots bleiben nach KVA/Auftrag/Rechnung unverändert; gemischte Länderfälle werden verhindert; Rundung und Summen stimmen mit steuerlich freigegebenen Referenzfällen überein.

## OP-034 – Österreichische B2G-E-Rechnung

- Status: zurückgestellt, bis ein konkreter Anwendungsfall mit öffentlichem Rechnungsempfänger vorliegt.
- Umfang: Österreichische Rechnungen an Bundesdienststellen werden als eigener Entwicklungsschritt umgesetzt. Erforderlich sind ein unterstütztes strukturiertes Rechnungsformat, Empfängerkennung, fachlich und technisch validierte Übermittlung über e-Rechnung.gv.at, Übermittlungsprotokoll und Wiederholungsschutz.
- Abgrenzung: `ZUGFERD` und `XRECHNUNG` sind deutsche Profile und begründen keine österreichische B2G-Konformität.
- Abnahmekriterium: Erfolgreiche technische und fachliche Validierung sowie bestätigte Testübermittlung über e-Rechnung.gv.at an einen abgestimmten österreichischen Bundesdienststellen-Empfänger. PDF- oder XML-Dateierzeugung ohne erfolgreichen Übermittlungsnachweis genügt nicht.

## OP-035 – Verbindliche länderabhängige Vorlagenauswahl

- Status: Die technische Namespace-Auflösung ist seit 0.51.0 vorhanden; die durchgängige Absicherung aller Dokumentarten, Aufgaben und Workflows bleibt offen.
- Ziel: Ein Fall darf unabhängig vom Aufrufweg ausschließlich eine für das Land beziehungsweise Bundesland seiner Niederlassung freigegebene oder ausdrücklich länderneutrale Vorlage verwenden.
- Datenmodell: Dokumentvorlagen erhalten Geltungsbereich (`NEUTRAL`, `COUNTRY`, `REGION`), Länder-/Regionscode, Vorlagenversion, Freigabestatus sowie optional `gültig ab` und `gültig bis`.
- Workflowregel: Aufgaben und Workflows referenzieren ausschließlich den logischen Vorlagenschlüssel, niemals einen konkreten Dateinamen. Der Server löst bei der Ausführung über `Fall → Niederlassung → Land/Bundesland` die passende Variante auf.
- Auflösungsreihenfolge: freigegebene Regionalvariante, freigegebene Landesvariante, ausdrücklich länderneutrale Variante. Ein Rückfall auf eine Vorlage eines anderen Landes ist nicht zulässig.
- Oberfläche: Dokumentauswahl filtert nach dem Länderprofil des Falls und zeigt Land, Region, Version und Freigabestatus. Die Workflow-Pflege zeigt eine Verfügbarkeitsmatrix für alle aktiven Niederlassungsprofile.
- Systemprüfung: Fehlende, abgelaufene oder nicht freigegebene Varianten werden für alle aktiven Workflows und Niederlassungen mit Absprungmöglichkeit gemeldet.
- Geltung: Die Prüfung wird immer durchgeführt, auch wenn aktuell nur Niederlassungen eines Landes eingerichtet sind. Rechnungen, KVA, Aufträge und Behördenformulare gelten standardmäßig als landesspezifisch; nur fachlich neutrale Dokumente dürfen als `NEUTRAL` gekennzeichnet werden.
- Abnahmekriterium: Automatisierte Matrix-Tests für direkte Dokumenterzeugung, Aufgaben und Workflows belegen, dass eine deutsche Vorlage bei einem österreichischen Fall und umgekehrt serverseitig abgewiesen wird. Fehlende Varianten erzeugen eine verständliche Meldung statt eines stillen Fallbacks.

## OP-036 – Geführte Ersteinrichtung und Betriebsbereitschaft

- Status: In Version 0.52.0 umgesetzt. Der wiederaufnehmbare Assistent prüft Gruppen, vorhandene Benutzer, Pfade, Länderprofil, Niederlassung und Vorlagenbereitstellung; die Fachnavigation bleibt bis zur Betriebsbereitschaft gesperrt.
- Ziel: Nach einer Neuinstallation führt ein Ersteinrichtungsassistent durch Rollen, Gruppen, Niederlassung, Länderprofil, Ablagepfade und Dokumentvorlagen. Die Fachoberfläche wird erst nach einer verständlichen Betriebsbereitschaftsprüfung freigegeben.
- Benutzerverwaltung: Die App legt keine Benutzer automatisch an. Sie wählt ausschließlich vorhandene Nextcloud-Benutzer aus und ordnet sie nach ausdrücklicher Bestätigung den konfigurierten Gruppen zu.
- Prüfungen: Mitglieder- und Administrationsgruppen müssen existieren; leere Gruppen werden separat erkannt. Mindestens ein Bestatter-Administrator, ein Fachbenutzer oder eine ausdrücklich bestätigte Einpersonen-Konfiguration sowie eine aktive, vollständig gepflegte Niederlassung werden verlangt.
- Oberfläche: Systemadministratoren sehen bei unvollständiger Einrichtung ein dauerhaftes Hinweisbanner mit direktem Einstieg in den Assistenten. Außenstehende Benutzer erhalten weiterhin 403; technische API-Sicherheit wird nicht durch das Onboarding ersetzt.
- Dokumentation: Installationshandbuch, Erstinbetriebnahme, Rollenmodell, Wiederaufnahme einer abgebrochenen Einrichtung und Prüfung über OCC werden gemeinsam beschrieben.
- Abnahmekriterium: Neuinstallation ohne Gruppen, leere vorhandene Gruppen, bestehende kundenspezifische Gruppennamen und vollständige Einrichtung werden automatisiert und im Browser getestet. Nach Abschluss liefert die Systemprüfung einen reproduzierbaren Bereitschaftsstatus.

## OP-037 – Sichere Deinstallation und vollständige Datenbereinigung

- Status: In Version 0.52.0 umgesetzt. Deaktivierung und Code-Entfernung bleiben absichtlich datenerhaltend; die endgültige Bereinigung ist ein separater, gesicherter und wiederholungssicherer OCC-Prozess.
- Standardverhalten: Deaktivieren oder Entfernen der App darf sensible Fall-, Rechnungs- und Dokumentdaten niemals stillschweigend löschen. Die dokumentierte Standardoption bleibt Datenerhalt für Wiederinstallation oder Migration.
- Verfahren: Ein administrativer Trockenlauf listet Tabellenzeilen, App-Konfiguration, Hintergrundjobs, Aktivitäten, von der App angelegte Kalenderobjekte, Fallordner, Vorlagen und Integrationsdaten einzeln auf. Vor einer Löschung sind Backup, Prüfsumme und Legal-Hold-Prüfung verpflichtend.
- Ausführung: Eine vollständige Bereinigung erfolgt nur über einen gesonderten, wiederholungssicheren OCC-Befehl mit `--execute` und festem Bestätigungscode. Datenbank, Dateien und Groupware-Objekte werden in kontrollierter Reihenfolge entfernt und das Ergebnis protokolliert.
- Abgrenzung: Von Benutzern außerhalb der App angelegte Dateien, externe Paperless-Dokumente, E-Mails, Sicherungen und gesetzlich aufzubewahrende Nachweise werden nicht ungeprüft gelöscht. Gruppen und Nextcloud-Benutzer bleiben standardmäßig bestehen.
- Dokumentation: Es werden die Varianten Deaktivieren, App-Code entfernen mit Datenerhalt, Wiederinstallation und endgültiger Daten-Purge beschrieben. Die Wirkung von `occ app:remove --keep-data bestatter` und des app-eigenen Purge-Befehls wird klar getrennt.
- Abnahmekriterium: Testinstallation mit Fällen, Rechnungen, Aufgaben, Terminen, Dokumenten und Integrationsdaten wird zunächst mit Datenerhalt entfernt und erfolgreich wiederangebunden; anschließend beseitigt der bestätigte Purge ausschließlich die zuvor angezeigten Bestatter-Ziele.

## OP-038 – Nextcloud-Mehrversions-Kompatibilität und Testmatrix

- Status: 0.65.0 bereitet die Metadaten und den statischen Bruchstellencheck für Nextcloud 35 vor; derzeit ist weiterhin nur Nextcloud 34 verbindlich abgenommen. PHP-/Integrations- und Browsermatrix für 34/35 sowie die Freigabe des Containerwechsels stehen aus.
- Ziel: Die Bestatter-App unterstützt jeweils die aktuelle freigegebene Nextcloud-Hauptversion und mindestens deren direkte Vorgängerversion. Eine dritte Hauptversion wird aufgenommen, sofern Nextcloud-API, PHP-Laufzeit und abhängige Apps dies ohne unsichere Kompatibilitätsumgehungen erlauben.
- Freigabemodell: Unterstützte Versionen werden ausdrücklich in `appinfo/info.xml`, Installationsdokumentation und Release Notes genannt. „Installierbar“ gilt nicht als „unterstützt“; jede Version benötigt einen erfolgreich ausgeführten Prüfstand.
- Testmatrix: Für jede unterstützte Nextcloud-Hauptversion werden Neuinstallation, Upgrade der Bestatter-App, Datenbankmigration, Anmeldung und Rollenprüfung, Fallanlage/-bearbeitung, Aufgaben-/Terminsynchronisation, Dokumenterzeugung, KVA/Auftrag/Rechnung, Backup-Vorschau und ein vollständiger automatisierter Testlauf geprüft. Zusätzlich werden die jeweils zulässigen PHP- und Datenbankversionen dokumentiert.
- Automatisierung: Eine CI-Matrix baut für jede Kombination eine isolierte Nextcloud-Umgebung auf, installiert die App, führt PHP-Lint, statische Analyse, PHPUnit- und Frontendtests sowie die App-Abnahmeprüfung aus und veröffentlicht maschinenlesbare Ergebnisse. Externe Integrationen wie EuroOffice, Paperless und Speech-to-Text erhalten getrennte optionale Integrationstests.
- Wartungsregel: Eine neue Nextcloud-Hauptversion wird erst nach bestandenem Matrixlauf freigegeben. Das Ausscheiden einer alten Version wird mindestens eine App-Version vorher angekündigt und mit dem Ende des jeweiligen Nextcloud-Supportzeitraums abgestimmt.
- Sicherheitsregel: Es werden keine pauschalen Versionssperren entfernt, um eine Installation zu erzwingen. Inkompatible oder entfernte Nextcloud-APIs werden über klar begrenzte Adapter behandelt; sicherheitsrelevante Middleware-, Berechtigungs- und CSRF-Tests laufen in jeder Matrixkombination.
- Abnahmekriterium: Mindestens zwei aufeinanderfolgende Nextcloud-Hauptversionen bestehen dieselbe automatisierte und dokumentierte Abnahme ohne bedingte Codeänderung. Neuinstallation und Upgrade funktionieren auf beiden Versionen; Abweichungen, ausgeschlossene Integrationen und unterstützte Laufzeiten sind nachvollziehbar veröffentlicht.

## OP-039 – Native Nextcloud-Administration und bootstrap-sichere Rollenkonfiguration

- Status: Als eigenständiger Ausbau aufgenommen; die akute Ersteinrichtungssperre ist seit 0.52.1 entschärft, weil `TeamService` Nextcloud-Systemadministratoren unabhängig von den konfigurierten Bestatter-Gruppen als Mitglied und Administrator anerkennt. Ein frisch installiertes System benötigt deshalb aktuell keinen direkten Datenbank- oder OCC-Eingriff. Die Konfiguration ist jedoch weiterhin ausschließlich in die Fachoberfläche eingebettet.
- Ziel: Unter Administration → Bestatter wird ein eigener Nextcloud-Settings-Bereich über `ISection` und `ISettings` bereitgestellt. Die erstmalige Pflege von Mitgliedergruppe, Administrationsgruppen und grundlegenden Ablageparametern ist dort allein mit Nextcloud-Systemadministrationsrechten möglich und hängt weder von einer bereits vorhandenen Bestatter-Gruppe noch von der Bestatter-Middleware ab.
- Bootstrap-Sicherheit: Lesen und erstmaliges Speichern der Rollenparameter verwenden einen getrennten Settings-Endpunkt beziehungsweise Nextclouds Settings-Formularvertrag. Dieser Pfad prüft native Systemadministrations- oder ausdrücklich delegierte Settings-Rechte, nicht `requireBestatterMember()`. Alle fachlichen APIs bleiben unverändert durch `BestatterAccessMiddleware` geschützt.
- Delegation: Nach abgeschlossener Ersteinrichtung kann der Settings-Bereich über Nextclouds Administrationsdelegation einer geeigneten technischen Administratorgruppe freigegeben werden. Eine Delegation darf keine Fall-, Rechnungs- oder Dokumentinhalte sichtbar machen und keine Selbstzuordnung zu privilegierten Bestatter-Gruppen ermöglichen.
- Bedienung: Gruppen werden aus vorhandenen Nextcloud-Gruppen ausgewählt und mit Existenz, Mitgliederzahl und mindestens einer handlungsfähigen administrativen Person geprüft. Gruppenanlage und Benutzerzuordnung benötigen eine ausdrückliche Bestätigung und werden auditiert.
- Datenquelle: `InstallationConfigService` bleibt die einzige persistente Konfigurationsquelle. Fachoberfläche, Ersteinrichtungsassistent, OCC-Provisionierung und Nextcloud-Settings dürfen keine voneinander abweichenden Parallelwerte führen.
- Abnahmekriterium: Neuinstallation ohne Bestatter-Gruppen kann von einem Nextcloud-Systemadministrator vollständig konfiguriert werden. Ein Außenstehender erhält weiterhin 403; ein delegierter technischer Administrator sieht nur die freigegebenen Systemparameter; nach Entzug der Delegation endet der Zugriff sofort.

## OP-040 – Zentrale gemeinsame Fallablage statt benutzerabhängiger Ordner

- Status: Kritische Mehrbenutzer-Architekturlücke aufgenommen; auch nach der fallbezogenen Mailfreigabe in 0.64.1 noch offen. `FolderService::userFolder()` sowie mehrere Dokument-, Workflow-, Assistenz-, Backup- und Löschpfade verwenden derzeit `getUserFolder()` des jeweils angemeldeten beziehungsweise übergebenen Benutzers. Dadurch können je Bearbeiter getrennte Fallordner entstehen. Ein gemeinsam verwendetes Login kaschiert dieses Verhalten, ist aber kein zulässiges Zielmodell.
- Ziel: Jede Niederlassung beziehungsweise Mandanteneinheit besitzt eine explizit konfigurierte zentrale Fallablage, die unabhängig vom gerade angemeldeten Benutzer aufgelöst wird. Mitarbeiter arbeiten mit persönlichen Nextcloud-Konten und rollenbasierten Berechtigungen; ein geteiltes Benutzerkonto ist weder erforderlich noch vorgesehen.
- Bevorzugte Variante: Integration mit der Nextcloud-App „Group folders“, sofern deren für die eingesetzte Nextcloud-Version unterstützte Schnittstelle eine stabile Auflösung und Rechteprüfung ermöglicht. Alternativ wird ein dediziertes technisches Eigentümerkonto mit kontrollierter Ordnerfreigabe unterstützt. Benutzername, Passwort oder interaktive Anmeldung dieses Kontos werden von der Bestatter-App nicht gespeichert oder verwendet.
- Architektur: Eine zentrale `CaseStorageService`-/Storage-Backend-Abstraktion ersetzt direkte `getUserFolder()`-Aufrufe in Folder-, Document-, Workflow-, Assistant-, Backup-, Restore-, Retention-, Purge- und Artikelbildlogik. Konfiguriert werden Backend-Typ, Ablagekennung, relativer Stammpfad und optional die Zuordnung je Niederlassung.
- Berechtigungen: Die App prüft serverseitig Bestatter-Rolle und Fallberechtigung zusätzlich zu den Nextcloud-Dateirechten. Pfade und Datei-IDs dürfen keine Berechtigungsumgehung ermöglichen. Die minimale Rechtevergabe für Lesen, Bearbeiten, Löschen, Vorlagenpflege und Backup wird dokumentiert.
- Migration: Vor einer Umstellung werden alle aktuell verwendeten Benutzer-Wurzeln inventarisiert, Dubletten und abweichende Dateien gemeldet sowie ein vollständiges Fachpaket verlangt. Ein Trockenlauf zeigt Quelle, Ziel, Dateizahl, Konflikte und Prüfsummen. Der bestätigte Lauf kopiert beziehungsweise verschiebt kontrolliert, aktualisiert Referenzen und lässt die Quellen bis zur separaten Freigabe unangetastet.
- Mehrmandantenregel: Bei mehreren Niederlassungen kann eine gemeinsame oder je Niederlassung getrennte Ablage gewählt werden. Landesspezifische Vorlagen- und Berechtigungsregeln bleiben über die Fall-/Niederlassungszuordnung wirksam.
- Abnahmekriterium: Zwei Benutzer mit getrennten Konten öffnen denselben Fall und sehen dieselben Dateien; Upload, Dokumenterzeugung, Workflow, Backup, Restore und Aufbewahrung verwenden unabhängig vom ausführenden Benutzer dieselbe zentrale Ablage. Ein nicht berechtigter Benutzer erhält weder über die App noch über einen bekannten Pfad Zugriff.

## OP-041 – Technische Integrations- und Betriebsparameter in den Nextcloud-Einstellungen

- Status: Als Ergänzung zum bestehenden Betriebs-Cockpit aufgenommen. Systemadministratoren können die Fachoberfläche in 0.52.2 zwar öffnen, erhalten technische Infrastrukturparameter jedoch noch nicht im üblichen Nextcloud-Administrationskontext.
- Ziel: Der native Bereich Administration → Bestatter erhält kompakte, plattformnahe Abschnitte für „Zugriff und Ablage“, „Dienste und Hintergrundjobs“ sowie „Sicherheit, Backup und Systemstatus“. Fachliches Customizing verbleibt in der Bestatter-App.
- Paperless: URL, API-Token, TLS-/Verbindungsstatus und Verbindungstest werden als technische Infrastrukturkonfiguration angeboten. Token werden ausschließlich ersetzt, niemals im Klartext zurückgegeben. Dokumenttypen, Korrespondenten, Belegkategorien, Fallzuordnung und Workflow-Mapping bleiben fachliches Customizing in der App.
- Betriebsdiagnose: Rein lesend angezeigt werden App-/Nextcloud-Version, unterstützte Versionsmatrix, Cron-Modus, letzter Wartungs- und Paperless-Lauf, fehlgeschlagene Hintergrundjobs, Aufgaben-/Kalender-/Kontakte-/Office-/Activity-/Speech-to-Text-Verfügbarkeit, Audit-Integrität sowie Zeit und Ergebnis der letzten Sicherungsprüfung. Jeder Hinweis erhält ein verständliches Ziel und nach Möglichkeit einen sicheren Absprung.
- Backup: Sicherungsunterordner und Aufbewahrungsfristen können technisch gepflegt werden; Erzeugung, Download, Löschung und Restore bleiben wegen ihrer fachlichen Tragweite und Schutzabfragen im bestehenden Backup-/Restore-Bereich beziehungsweise in OCC.
- Änderungsgrenze: Das Panel zeigt Nextcloud-weite Einstellungen wie Cron, Mailserver, Trusted Domains oder Office-Konfiguration nur an und verlinkt zur zuständigen Systemseite. Es dupliziert und überschreibt keine fremden App- oder `config.php`-Werte.
- Sicherheit: Schreibende Einstellungen sind nur für Nextcloud-Systemadministratoren oder ausdrücklich delegierte technische Administratoren sichtbar. Secrets werden verschlüsselt gespeichert, Änderungen vollständig auditiert und Diagnoseausgaben enthalten keine Fall-, Kontakt-, Bank-, Token- oder Dokumentinhalte.
- Abnahmekriterium: Ein Systemadministrator erkennt ohne Shell-Zugriff, ob Kernintegration, Jobs und Sicherungen betriebsbereit sind, kann technische Bestatter-Parameter sicher pflegen und gelangt über Absprunglinks zur Ursache. Ein Fachbenutzer ohne technische Delegation sieht diesen Settings-Bereich nicht.

## OP-042 – Vorlagen-Galerie für Trauerdruck

- Status: neu aufgenommen; fachlich sinnvoll, Umsetzung als eigenständiger Ausbau nach Klärung des Vorlagen-/Rechtekatalogs
- Bewertung: mittlere bis eher hohe Komplexität. Das Ticket ist klar auf Trauerdruck begrenzt und kann auf dem bestehenden Dokumentvorlagensystem aufbauen; der wesentliche Aufwand liegt in der gemeinsamen Rendering-Logik für Vorschau und Enddokument, der interaktiven Galerie, personenbezogenen Favoriten, Customizing-Validierung, Migration und der realen Abnahme mit Falldaten.
- Ziel: Die bisherige einfache Auswahl für Trauerdruck wird um eine filterbare Kachel-Galerie mit Vorschaubildern, echten Falldaten, Favoriten und automatisch einsortierten aktiven Vorlagen ergänzt. Bestehende Nicht-Trauerdruck-Vorlagen und bereits erzeugte Bestandsdokumente bleiben unverändert nutzbar.
- Umfang: Erweiterung von `resources/document-templates.json` um einen optionalen `gallery`-Knoten; Galerie- und Vorschau-Endpunkte; Favoritentabelle mit Benutzerbezug; `DocumentService::previewTemplate()` aus dem gemeinsamen Befüllungspfad; Filter nach Motiv, Stil, Foto und weltlich/religiös; Customizing-Pflichtfeld `gallery.source_rights`.
- Fachliche Leitplanken: `active: false` blendet Vorlagen nur aus der Galerie aus und darf historische Dokumente nicht unlesbar machen. Favoriten werden je Benutzer geführt. Jede Farb- oder Layoutkombination bleibt ein eigener vollständiger Katalogeintrag; dynamische Bausteinkombinationen sind nicht Bestandteil dieses OP.
- Offene Klärung vor Umsetzung: Der im Ticket erwähnte Feldkatalog für `gallery` fehlt. Vor Implementierung sind daher mindestens die zulässigen Schlüssel, Datentypen, Filterwerte, Vorschaureferenz, Sortierregeln, Rechte-/Quellenstatus und die Versionierung des Schemas verbindlich festzulegen. Zusätzlich sind Vorschauformat, maximale Galeriegröße, Caching sowie Berechtigungs- und Datenschutzregeln für Falldaten zu entscheiden.
- Abhängigkeiten: vorhandene `resources/document-templates.json`-Struktur, `DocumentService::generateTemplate()`, Fallberechtigung, Datenbankmigrationen, Dokument-/Customizing-Oberfläche und ein belastbarer Renderingpfad ohne Dateischreibnebenwirkung. Die eigentlichen 12–15 kreativen Grundlayouts sowie deren Rechteklärung sind ausdrücklich ein separates Vorhaben.
- Empfohlene Umsetzung: zuerst Schema/Feldkatalog und Rechteprüfung, danach Preview-API mit gemeinsamem Renderingpfad, anschließend Galerie/Filter/Favoriten und zuletzt Skalierungs-, Bestandsdokument- und Mehrbenutzertests. Die bestehende Auswahllogik bleibt als Fallback für Vorlagen ohne `gallery` erhalten.
- Abnahmekriterium: Aktive Galerieeinträge werden korrekt nach kombinierten Filtern und Favoritenstatus angezeigt; die Vorschau eines Falls verwendet exakt dessen Name und Lebensdaten und aktualisiert sich beim Fallwechsel; ein Benutzer sieht nicht die Favoriten eines anderen; deaktivierte Vorlagen verschwinden nur aus der Galerie; alte Dokumente bleiben öffnbar; ein Katalogeintrag ohne `gallery.source_rights` kann nicht gespeichert werden; Galerie und Vorschau bleiben bei zunächst 12–15 und später 40–80 Einträgen performant.
- Aufwandsschätzung: mittel bis hoch (Entwicklung, abhängig von vorhandenem Frontend-/Renderingfundament); externe Layoutbeschaffung und Rechteprüfung nicht enthalten.

## OP-043 – Schlanke SAP-orientierte Auftrag-/KVA-Positionserfassung

- Status: in Version 0.54.0 einschließlich Ausbaustufen 1 bis 2 umgesetzt; Katalogdialog und Scrollstabilität in 0.55.1 nachgebessert; erneute reale Browser-/Fachabnahme offen
- Ziel: Kopfdaten und Positionsdaten übersichtlich trennen und die Positionsliste als schnelle manuelle Erfassungsfläche gestalten. Der Leistungskatalog wird ausschließlich als Datenquelle für die Inline-Auswahlliste am Material-/Leistungsnummernfeld verwendet.
- Umsetzung: Positionen werden in 10er-Schritten geführt; Katalogpositionen und Freitextpositionen sind möglich; die Suche startet ab dem ersten beliebigen Zeichen und durchsucht Nummer und Katalogtexte mit Relevanzsortierung; die Auswahl übernimmt den vollständigen Katalogsnapshot in die Erfassungszeile; Menge und Einheit werden vorbelegt und bleiben änderbar; EL, FK und DP werden als getrennte Positionstypen neben Leistungsstatus, Abrechenbarkeit und Herkunft gespeichert.
- Bedienung: Die Standardtabelle zeigt nur Kernfelder. Weitere Positionsdaten werden über einen positionsbezogenen Detailzugang vorbereitet. Die Trefferliste mit Materialnummer und Kurztext erscheint direkt unter dem gerade bearbeiteten Eingabefeld; ein separater unterer Katalogbereich ist nicht Bestandteil der Erfassung. Analog zur SAP-Positionsübersicht folgt auf die belegten Zeilen immer eine leere Folgeposition ohne „Neue Position“-Button. Die Tabulator-Reihenfolge beginnt bei der Materialnummer und läuft über die fachlichen Kernfelder; Detail- und Löschaktionen werden dabei übersprungen.
- Daten- und Abrechnungsregel: Katalog- und Freitextpositionen werden als unveränderliche Snapshots weiterverarbeitet. Historische Positionen werden durch spätere Katalogänderungen nicht überschrieben. DP ist keine automatische steuerliche Entscheidung, sondern erfordert die spätere fachliche Nachweis-/Klassifikationsprüfung.
- Abnahmekriterium: KVA und Auftrag können mit Katalog- und Freitextpositionen in fortlaufenden 10er-Schritten erfasst, geändert und im angezeigten Status `Entwurf` entfernt werden; nach jeder belegten Position steht automatisch eine neue Leerzeile bereit; die Positionswerte und Summen bleiben in Dokument-, Leistungs- und Rechnungsprozessen konsistent; Festschreibung und bestehender Nachtrags-/Abrechnungsschutz außerhalb des Entwurfs bleiben wirksam.
- Detailticket: `docs/TICKET-AUFTRAG-KVA-POSITIONSERFASSUNG.md`.

## OP-044 – Korrektur von Auftragsstatus, Positionssperre und Katalogabgleich

- Status: in Version 0.54.0 erweitert; die reale Abnahme von 0.53.3 hat zusätzliche Speicher- und Summenfehler aufgedeckt, die in 0.54.0 korrigiert und erneut automatisiert geprüft werden
- Priorität: hoch, weil der widersprüchliche Zustand `Entwurf` bei gleichzeitig festgeschriebenem Auftrag und vorhandener Rechnung sowie abweichende UI-/API-Sperren fachlich unzulässige Änderungen ermöglichen oder eine falsche Bearbeitbarkeit vermitteln können.
- Ziel: Ein zentral ermittelter kaufmännischer Zustand steuert Statusanzeige, Vertragsschutz, sämtliche Positionsfelder und die serverseitige Schreibberechtigung. Festschreibung oder eine nicht stornierte Rechnung schließen `Entwurf` aus; inkonsistente Bestandsdaten werden auditierbar erkannt und repariert.
- Positionssuche: Jede nicht leere Eingabe startet die Suche. Exakte Nummern und Nummernpräfixe stehen vor Wortanfangs- und sonstigen Texttreffern aus Kurztext, Langtext, Kategorie und Artikelgruppe. Der Bindestrich besitzt keine Sonderbedeutung. Zusätzlich bleibt die vollständige Suche aus Artikelliste/Leistungskatalog mit Volltextfiltern und Paketlogik in einem optionalen Katalogdialog erhalten; sie wird nicht dauerhaft unterhalb der Positionen angezeigt.
- Bedienung: Die Bezeichnung erhält mindestens 280 Pixel nutzbare Breite. Kategorie/Gruppe und Kostenart werden platzabhängig als kompakte, nicht editierbare Kataloginformation gezeigt. Positionstyp und Einheit zeigen Schlüssel plus Bezeichnung.
- Wertelisten: Die eigene Werteliste `POSITION_TYPE` wird verbindlich mit den stabilen Schlüsseln `EL – eigene Leistung`, `FK – Fremdkosten/Fremdleistung` und `DP – echter durchlaufender Posten` angelegt; die bisherige fachliche Festlegung bleibt bestehen. Abweichende Werte `DK`/`SP` sind unzulässig. `QUANTITY_UNIT` ist die gemeinsame Quelle für Artikelkatalog und Auftrag, zum Beispiel `STK – Stück`.
- Testdaten: Fall `2026-0005` wird wegen vorhandener Festschreibung/Rechnung nicht erneut als Entwurfsabnahme verwendet. Der automatisierte neue Testfall `2026-0006` enthält keine Dokumente, Rechnungen oder vorbelegten Positionen und bleibt im Status `Entwurf`. Die Anlage eines dauerhaft sichtbaren Falls erfolgt erst in einer verbundenen Nextcloud-Testinstanz.
- Abnahmekriterium: Status, Hinweis, Eingabebereitschaft und API-Schutz sind in Entwurf, nach Festschreibung und nach Rechnungsanlage widerspruchsfrei; die Suche startet mit jedem Zeichen und sortiert exakte Nummern sowie Nummernpräfixe zuerst; Katalogfelder und gemeinsame Einheiten werden konsistent übernommen; nicht freigegebene Positionstypen werden nicht stillschweigend gespeichert.
- Detailticket: `docs/TICKET-KORREKTUR-AUFTRAGSPOSITIONEN-STATUS-AUTOCOMPLETE.md`.

## OP-045 – Komfort- und Preisfunktionen für Auftragspositionen Phase 3

- Status: nach Version 0.54.0 als spätere Ausbaustufe vorgemerkt; vor Umsetzung ist eine erneute reale Fachabnahme der stabilisierten Kernprozesse erforderlich.
- Favoriten und Verlauf: Bis zu acht benutzerbezogene Favoriten beziehungsweise zuletzt verwendete Artikel werden in der Katalogsuche angeboten, ohne die bestehende Filter- und Paketlogik zu verändern.
- Erweiterte Positionsdetails: Die in 0.54.0 eingeführte zeilengebundene Detailanzeige kann um die Bereiche Allgemein, Preis/Konditionen, Text/Bemerkung und Status/Herkunft sowie eine Navigation zur vorherigen und nächsten Position ergänzt werden. Die Standardtabelle bleibt schlank.
- Sammelbearbeitung: Mehrfachauswahl, Sammellöschen und gezieltes Einfügen oberhalb oder unterhalb einer Position werden erst nach einer Bedienabnahme aufgenommen. Fakturierte Positionen und festgeschriebene Verträge bleiben geschützt; Positionsnummern und Auditbezüge müssen stabil bleiben.
- Rabatte und Staffelpreise: Positionsrabatte in Prozent oder Betrag, Listenpreis-/Nettopreisnachweis und optionale Mengenstaffeln benötigen vor Umsetzung eine fachliche Freigabe durch Buchhaltung und Steuerberatung. Vertragsschutz, Nachträge, Dokumente und Rechnungspositionen müssen dieselbe Konditionsgrundlage verwenden.
- Abnahmekriterium: Phase 3 darf die schnelle Tastaturerfassung, die genau eine leere Folgeposition und die in 0.54.0 stabilisierte Speicher-/Summenlogik nicht beeinträchtigen. Benutzerbezogene Daten bleiben getrennt; Konditionen sind vollständig nachvollziehbar und revisionsnah dokumentiert.

## OP-046 – Zentrale Plausibilitätsprüfung vor KVA-/Auftragsfestschreibung

- Status: in Version 0.55.0 umgesetzt; reale Fachabnahme in der verbundenen Testinstanz nach Installation des Releasepakets offen.
- Ziel: Ein KVA oder Auftrag darf den bearbeitbaren Entwurf nur verlassen, wenn die für Vertrag, spätere Leistungserbringung und Abrechnung erforderlichen Daten konsistent vorliegen.
- Blockierende Regeln: Fallnummer und Name der verstorbenen Person; vollständiger Auftraggeber mit Name, Vorname, Straße, PLZ/Ort und Land; bei abweichendem Rechnungsempfänger dieselben Adresspflichten; Dokumentnummer und -datum; beim Auftrag die Art der Beauftragung; mindestens eine Position; Positionsnummern in 10er-Schritten; Bezeichnung, positive Menge, Einheit, Positionstyp EL/FK/DP und zulässiger MwSt.-Satz; bei Freitext ein positiver Preis; bei SEPA-Lastschrift vollständige Mandats- und Zahlerdaten.
- Bestätigungspflichtige Hinweise: Katalogposition ohne Preis, 0 % MwSt., doppelte Katalogartikel, FK/DP ohne erläuternde Positionsnotiz, Gesamtsumme 0,00 €, fehlender Kontaktweg und beim Auftrag fehlende digitale Bestätigung.
- Bedienung: Die Statuswerte `KVA versendet` und `beauftragt` starten denselben Prüfdialog wie die Festschreibungsbuttons. Fehler verhindern die Aktion und führen zum ersten betroffenen Feld; Hinweise werden gesammelt angezeigt und benötigen eine ausdrückliche Bestätigung.
- Sicherheit und Nachweis: Die Prüfung wird serverseitig unmittelbar vor der Transaktion wiederholt. Bestätigte Warncodes, Benutzer und Zeitpunkt werden mit dem unveränderlichen KVA-/Auftragssnapshot gespeichert; ein direkter API-Aufruf kann die Prüfung nicht umgehen.
- Abnahmekriterium: Unvollständige Kopf-, Positions- oder SEPA-Daten verhindern die Festschreibung; reine Hinweise lassen sie erst nach Bestätigung zu; ein fehlerfreier Entwurf wird genau einmal festgeschrieben und danach über den zentralen Vertragsstatus gesperrt.
- Detailticket: `docs/TICKET-PLAUSIBILITAET-FESTSCHREIBUNG-0550.md`.

## OP-047 – BEST-112: Positionsbezogene Nachträge nach Teilrechnung

- Status: in Version 0.56.0 technisch umgesetzt; fachliche Freigabe durch die Buchhaltung und reale Abnahme stehen aus.
- Priorität/Aufwand: Muss / M.
- Ziel: Eine Teilrechnung sperrt nicht länger den gesamten Auftrag. Nicht fakturierte Positionen können mit Pflichtbegründung ergänzt oder geändert werden; fakturierte Positionen und aktive Schlussrechnungen bleiben geschützt.
- Umsetzung: `CommercialStateService` unterscheidet Teil- und Schlussrechnung; neue Vertragszeilen erhalten `origin = NACHTRAG`; die Oberfläche kennzeichnet sie als `NTR` und zeigt den Fakturierungsstand je Position; vorhandene Restmengenlogik übernimmt abrechenbare Nachträge in Folgerechnungen.
- Risiko: Die Lockerung der kaufmännischen Gesamtsperre benötigt vor Produktivfreigabe eine ausdrückliche Buchhaltungsentscheidung zu Nachtragsnachweis, Steuerzeitpunkt, Berechtigungen und Auswertung.
- Abnahmekriterium: Die acht Kriterien aus BEST-112 sind erfüllt; insbesondere bleibt die Positionsliste nach Teilrechnung nachtragsfähig und wird erst durch eine aktive Schlussrechnung vollständig gesperrt.
- Detailticket: `docs/TICKET-BEST-112-NACHTRAEGE-NACH-TEILRECHNUNG.md`.

## OP-048 – Pflegbare Überschriften der Rechnungsblöcke

- Status: optionaler Customizing-Ausbau; die fachlich abgestimmten Standardtexte sind in der Rechnungsvorlage bereits umgesetzt.
- Ziel: Die sichtbaren Überschriften für `EL`, `FK` und `DP` können administrativ je Niederlassung gepflegt werden, ohne die stabilen Positionstyp-Schlüssel oder deren steuerliche Bedeutung zu verändern.
- Standardwerte: `Eigene Leistungen`, `Fremdleistungen und verauslagte Beträge`, `Durchlaufende Posten – nicht Teil des Entgelts`.
- Leitplanke: Eine kundenspezifische Bezeichnung darf die steuerliche Einordnung nicht verschleiern; insbesondere muss bei `DP` erkennbar bleiben, dass der Betrag nicht Teil des umsatzsteuerlichen Entgelts ist.
- Abnahmekriterium: Leere Texte fallen auf den Standard zurück; Änderungen wirken nur auf neu erzeugte Dokumente und werden mit Benutzer und Zeitpunkt protokolliert.

## OP-049 – BEST-113: Dokumentvorschau und revisionssichere Auftragspapiere

- Status: erste Ausbaustufe in Version 0.57.0 umgesetzt; nebenwirkungsfreie PDF-Vorschau und atomare Erzeugung/Aktualisierung des aktuellen Entwurfspakets sind technisch realisiert. Fortlaufende finale Revisionen bleiben als zweite Ausbaustufe offen.
- Priorität/Aufwand: Muss / L.
- Befund: „Drucken“ ruft aktuell nur `window.print()` für die komplette Auftrag-/KVA-Erfassungsmaske auf. „Auftrag & Vollmacht erzeugen“ erzeugt ohne vorgelagerte Dokumentvorschau einen aktuellen Entwurf; eine Wiederholung löscht und ersetzt die gleichartige Entwurfsausgabe, während die technische Versionsnummer fest auf `1` steht. Finale, unterschriebene oder versendete Dokumente sind bereits serverseitig gegen Überschreiben, Bearbeiten und Löschen geschützt.
- Ziel: Die Oberfläche trennt schreibfreie PDF-Vorschau, genau einen aktualisierbaren Arbeitsentwurf und unveränderliche, fortlaufend nummerierte Revisionen. Gedruckt wird nur aus einer Dokumentvorschau oder PDF-Ausgabe, nicht aus der Erfassungsmaske.
- Dokumentpaket: Im KVA-Modus wird ein KVA, im Auftragsmodus werden Auftrag und Vollmacht als atomare Paketrevision verarbeitet. Bei einem Teilfehler bleibt kein halbfertiges Paket zurück; Doppelklicks erzeugen keine Dubletten.
- Historie: Eine finale, unterschriebene, versendete oder ersetzte Ausgabe wird nie überschrieben oder gelöscht. Korrekturen erzeugen mit Pflichtbegründung eine neue Revision und lassen Vorgänger sowie Nachfolger nachvollziehbar sichtbar.
- Abnahmekriterium: Die zwölf Kriterien aus BEST-113 sind erfüllt; insbesondere hat die Vorschau keine dauerhafte Nebenwirkung, der aktuelle Entwurf wird transparent aktualisiert und jede festgeschriebene Korrektur erhält eine neue, transaktionssicher vergebene Revision.
- Detailticket: `docs/TICKET-BEST-113-DOKUMENTVORSCHAU-UND-REVISIONEN.md`.

## OP-050 – BEST-114: Leistungszeitraum in Rechnungsentwürfen bearbeiten

- Status: als Korrektur aufgenommen; Umsetzung und Test offen.
- Priorität/Aufwand: Muss / S.
- Befund: Der Leistungszeitraum kann derzeit nur bei der erstmaligen Anlage einer Teil- oder Schlussrechnung eingegeben werden. Fehlt eines der beiden Daten, verhindert die Rechnungsprüfung die Dokumenterzeugung; ein bereits vorhandener Entwurf bietet jedoch keine Bearbeitungsmöglichkeit.
- Ziel: Der vollständige Leistungszeitraum kann bei jeder Rechnung im Status `ENTWURF` nachgetragen oder korrigiert werden. Eine Rechnung im Status `PRUEFUNG` wird zuerst kontrolliert in den Entwurf zurückgeführt und kann danach bearbeitet werden.
- Vorbelegung: Bei einer neuen Rechnung wird **„Leistungszeitraum von“** mit dem Auftragsdatum beziehungsweise Auftragseingang und **„Leistungszeitraum bis“** mit dem aktuellen lokalen Datum vorbelegt. Fehlt ein verwertbares Auftragsdatum, bleibt der Beginn leer und wird verständlich als Pflichtangabe markiert; es wird kein fachlich ungesichertes Datum geraten.
- Validierung: Beide Daten sind vor dem Übergang zur Prüfung und vor der Dokumenterzeugung verpflichtend; Beginn darf nicht nach Ende liegen. Die Regeln gelten serverseitig und können durch direkte API-Aufrufe nicht umgangen werden.
- Nachvollziehbarkeit: Änderungen an einem bestehenden Rechnungsentwurf werden mit altem und neuem Zeitraum, Benutzer und Zeitpunkt auditiert; Rechnungsnummer, Positionssnapshot und Summen bleiben unverändert.
- Abnahmekriterium: Eine wegen fehlendem oder fehlerhaftem Leistungszeitraum beanstandete Rechnung lässt sich ohne Storno in den Entwurf zurückführen, korrigieren und anschließend erfolgreich erneut prüfen. Bei Neuanlage sind Auftragsdatum und aktuelles Datum korrekt vorbelegt.
- Detailticket: `docs/TICKET-BEST-114-LEISTUNGSZEITRAUM-RECHNUNGSENTWURF.md`.

## OP-051 – BEST-115: Nebenaufträge in Navigation, Fallstatus und Suche integrieren

- Status: in Version 0.59.0 umgesetzt und auf Nextcloud 34.0.3 installiert. Die reale Abnahme mit Fall `2026-0008`, zwei offenen Nebenaufträgen unterschiedlicher Status, Navigation, Hierarchie, Filter und Abschlusssperre ist bestanden. Der dabei gefundene Suchrandfall für vollständige mehrteilige Auftraggebernamen und die fehlerhafte Mehrzahl wurden in Patchversion 0.59.1 korrigiert. Die Backend-Suche ist im Testsystem bestätigt; die Frontend-Nachprüfung folgt nach Installation von 0.59.1.
- Priorität/Aufwand: Muss / L.
- Befund: Das Anlageformular wird im Leerzustand automatisch geöffnet; Positionen und Finanzen wechseln aus dem Nebenauftragsreiter in die allgemeinen Hauptreiter; der Auftraggeber fehlt im Positionskopf. Fallliste, Suche und Fallabschluss berücksichtigen Nebenaufträge derzeit nicht.
- Ziel: Nebenaufträge werden im eigenen Fallreiter als kompakte, auswählbare Liste mit internen Ansichten für Kopfdaten, Positionen und Abrechnung geführt. Fallübersicht und Suche zeigen sie hierarchisch unter dem Sterbefall und erlauben gezielte Filter.
- Statusregel: Ein Fall darf nicht abgeschlossen werden, solange ein nicht stornierter Nebenauftrag im Status `ENTWURF` oder `BEAUFTRAGT` steht. Erledigte Nebenaufträge schließen den Fall nicht automatisch. Abgeschlossene oder stornierte Fälle dürfen keine neuen Nebenaufträge erhalten.
- Suche/Übersicht: Nebenauftragsnummer, Auftraggeber und Status werden suchbar. Pagination und Gesamtzahl zählen weiterhin Fälle; ein Fall erscheint trotz mehrerer Treffer nur einmal. Die Liste zeigt Anzahl und offene Nebenaufträge und kann untergeordnete Zeilen aufklappen.
- Risiko: Ob eine freigegebene Schlussrechnung oder erst vollständiger Zahlungseingang für den Nebenauftragsabschluss genügt, ist vor Produktivfreigabe mit der Buchhaltung zu bestätigen. Bestandsfälle mit widersprüchlichem Status werden nur diagnostiziert, nicht automatisch verändert.
- Abnahmekriterium: Die 14 Kriterien aus BEST-115 sind erfüllt; insbesondere bleibt der Nebenauftragskontext in Kopf, Positionen und Finanzen sichtbar, Suche und Filter liefern eindeutige Fallmengen und die Fallabschlusssperre kann weder über UI, API noch Workflow umgangen werden.
- Detailticket: `docs/TICKET-BEST-115-NEBENAUFTRAEGE-NAVIGATION-STATUS-SUCHE.md`.

## OP-052 – BEST-116: Dokumente drucken und Mailversand vorbereiten

- Status: in Version 0.60.2 installiert und browserseitig abgenommen. In der zentralen Dokumentübersicht wurden 24 PDF-Druckaktionen und 26 Mailaktionen korrekt angeboten; der Druck-Fallback öffnete die richtige PDF ohne CSRF-Fehler. Auch die Mailaktion erzeugte keine CSRF-Meldung oder Bestatter-App-Fehler. Die Auswahl eines lokal installierten Mailprogramms und die sichtbare Anhangsprüfung bleiben wegen des nicht auslesbaren Systemdialogs als arbeitsplatzabhängiger manueller Test in Edge oder Chrome offen; es wurde weder gedruckt noch versendet.
- Priorität/Aufwand: Soll / M.
- Befund: Dokumente lassen sich in den Übersichten bisher öffnen oder als Vorschau anzeigen, aber nicht über einheitliche Zeilenaktionen drucken oder für den Versand vorbereiten. Die Dateiliste in der Fallakte verwendet zudem eine separate Darstellung.
- Ziel: Hauptübersicht und Fallakte verwenden dieselbe Dokumentzeile. Vorhandene PDFs können direkt gedruckt werden. Jede vorhandene Datei kann über den Systemdialog an ein lokales Mailprogramm mitgegeben werden; Empfänger, Betreff und Text bleiben leer.
- Transparenz: Bei fehlender Web-Share-Unterstützung werden Datei und leere `mailto:`-Nachricht geöffnet und das manuelle Anhängen erklärt. Es findet kein serverseitiger Versand und keine Versandprotokollierung statt; OP-002 bleibt fachlich und technisch unberührt.
- Sicherheit: Drucken wird nur bei tatsächlichen PDFs angeboten. Unveränderliche Dokumente bleiben geschützt, dürfen aber weiterhin ausgegeben werden. Dokumenterzeugung, Speicherung, Versionierung und Vorlagen bleiben unverändert.
- Abnahmekriterium: Die zehn Kriterien aus BEST-116 sind erfüllt; insbesondere sind Aktionen in beiden Übersichten einheitlich, alle Mailfelder bleiben leer, ein unterstütztes lokales Mailprogramm erhält den Anhang und DOCX-Dateien werden nicht fälschlich als direkt druckbar dargestellt.
- Detailticket: `docs/TICKET-BEST-116-DOKUMENTE-DRUCKEN-MAIL-VORBEREITEN.md`.

## OP-053 – BEST-117: Bestattungsvarianten und Zuschlagsstaffeln

- Status: in 0.61.0 lokal implementiert; Migration 3700 scheiterte zunächst an einem selbstreferenzierenden Fremdschlüssel. Die Korrektur 0.61.1 wurde im Testsystem erfolgreich installiert (`needsDbUpgrade: false`); die fachliche End-to-End-Abnahme bleibt offen. Regeln werden ohne fachliche Freigabe nicht automatisch aktiviert.
- Priorität/Aufwand: Muss / L.
- Ziel: Stabile, hierarchische Variantenwahl, bedingte interne Leistungshinweise sowie pflegbare Zuschlagsstaffeln mit festen Katalogleistungen. Erfassung von Abholzeit, Körpergröße und Gewicht bereits jetzt, aber noch keine automatische Zuschlagsermittlung.
- Nachtrag Variantenpflege: Die zuvor fehlende Pflege von Ober- und Untervarianten ist lokal ergänzt (Elternzuordnung, fachliche Einordnung, Neuanlage, Umhängen, Schutz vor Zyklen und unzulässigem Löschen). Browserabnahme im Testsystem bleibt offen. Der Entwicklungsreset erhält Wertelisten und ist dafür nicht erforderlich.
- Offen: fachliche Zuordnung von Almwiesen-, Kristall- und Wiesenbestattung; Staffelgrenzen und Zeit-/Feiertagslogik; Artikelgruppe für Urnen vor einer verbindlichen Regel bereinigen; Freigabe der Standardleistungen und Preise durch Fachbereich/Buchhaltung.
- Nach Beauftragung: Zusätzliche oder veränderte preiswirksame Leistungen dürfen nur als dokumentierte Auftragsänderung beziehungsweise Nachtrag über den bestehenden Positions-/Vertragsprozess erfasst werden.
- Detailticket: `docs/TICKET-BEST-117-BESTATTUNGSVARIANTEN-ZUSCHLAEGE.md`.

## OP-054 – BEST-118: Dokumentationsbasierte Bedienhilfe

- Status: in 0.62.0 lokal implementiert; Testsystem- und Anbieterabnahme offen. Bei fehlendem Chat-Anbieter bleibt die Bedienhilfe absichtlich unsichtbar.
- Priorität/Aufwand: Soll / M.
- Ziel: Read-only-Frage/Antwort-Panel für Bedienungsfragen, ausschließlich über Nextcloud Task Processing und freigegebene, mitgelieferte Bedienhilfe. Der vorhandene Fallassistent bleibt eigenständig.
- Grenzen: Keine internen Tickets/OP-Listen als Modellkontext, keine Fall-, Rechnungs- oder Workflow-Aktion, keine eigene Modellanbindung. Modellantworten werden als automatisiert gekennzeichnet und mit ermittelten Dokumentationsabschnitten belegt; eine fachliche Prüfung ersetzt dies nicht.
- Detailticket: `docs/TICKET-BEST-118-DOKUMENTIERTE-BEDIENHILFE.md`.

## OP-055 – Bestattungsart und Bestattungsvarianten fachlich konsolidieren

- Status: in 0.63.0 lokal umgesetzt; Testsystem-Abnahme offen. Die historische Liste `FUNERAL_TYPE` wird nicht mehr neu initialisiert und bei bestehenden Installationen in der Pflege ausgeblendet; vorhandene Werte bleiben ohne Datenverlust erhalten.
- Ein gemeinsamer Baum `BURIAL_VARIANT`: Wurzeleinträge sind Bestattungsarten, Kind-Einträge Untervarianten. Die Fallspalte `funeral_type` erhält die Wurzelbezeichnung, `burial_variant_code` den gewählten Eintrag. Fallformular und Aufnahmeassistent verwenden dieselbe Liste.
- Baum- und Waldbestattung sind zwei getrennte Wurzeln. Die bisherigen Baumplätze werden von Wald nach Baum umgehängt; die anonyme Waldbestattung bleibt unter Wald. `CREMATION`/`BURIAL` bleibt ausschließlich technische Katalogfilterung.
- Almwiesen-, Kristall- und Wiesenbestattung bleiben fachlich offen und leiten weiterhin keine Leistungen automatisch ab. Historische Fälle ohne Variantencode werden nicht automatisch anhand ähnlich klingender Texte umklassifiziert.
- Abnahme: Migration 3800, Wertelistenpflege, Fall- und Assistentenauswahl sowie Berichte/Dokumente an einem neu angelegten Testfall prüfen.
- Priorität/Aufwand: Soll / M.

## OP-056 – Handschriftlich unterschriebene Bestattungsverträge erfassen

- Status: lokal in 0.63.1 umgesetzt und statisch getestet; fachliche Browser-Abnahme beider Wege im Testsystem ausstehend.
- Priorität/Aufwand: Muss / M. Abhängigkeit: finale, unveränderliche Dokumentrevisionen nach BEST-113 (OP-049).
- Zwei zulässige Eingänge: (A) Ein separat erstellter und handschriftlich unterschriebener Bestattungsvertrag wird vollständig eingescannt und dem Fall zugeordnet. (B) Die Anwendung erzeugt den Bestattungsauftrag als finale PDF-Revision; diese wird ausgedruckt, handschriftlich unterschrieben und anschließend vollständig eingescannt.
- Der Scan wird zunächst nur als „eingegangen / Unterschriftenprüfung offen“ gespeichert. Erst nach Sichtprüfung von Vollständigkeit, Fallbezug, Vertragsinhalt und erforderlichen Unterschriften bestätigt eine berechtigte Person „handschriftlich unterschrieben“. Dabei werden Quelle A/B, signierende Parteien, ein ggf. ersichtliches Unterschriftsdatum, prüfende Person und Prüfzeitpunkt dokumentiert; der Aufbewahrungsort des Papieroriginals kann angegeben werden. Eine unsichere oder fehlende Angabe bleibt offen und wird nicht erfunden.
- Bei B wird der Scan mit der ausgegebenen PDF-Revision verknüpft. Der Scan ersetzt oder überschreibt die nicht unterschriebene Revision nicht. Bei A bleibt der externe Vertrag als eigenständiges Originaldokument erkennbar. Jede Korrektur ist eine neue Revision beziehungsweise ein neues Dokument mit nachvollziehbarem Bezug.
- Die aktuelle direkte Auswahl „Unterschrieben“ bei der Dokumenterzeugung ist zu entfernen oder für diesen Papierprozess zu sperren: Ohne zugeordneten, geprüften Scan darf dieser Status nicht entstehen. Handschriftliche und spätere elektronische Signaturarten müssen unterscheidbar sein.
- Abnahme: Beide Wege mit je einem Testfall; fehlende Seite oder Unterschrift verhindert die Bestätigung; keine automatische Anerkennung durch OCR; direkte Dateiänderung/-löschung und Auswirkung auf die noch offene Archivierung gesondert prüfen. Die rechtlich erforderliche Form und Zahl der Unterschriften je Dokumentart sind fachlich freizugeben. Die drei Prüfschritte sind eine menschliche Erklärung, keine automatische Bildprüfung. Die App protokolliert SHA-256-Werte beim Upload und prüft sie vor der Bestätigung; eine GoBD-konforme, gegen direkte Nextcloud-Änderungen geschützte Archivierung ist damit nicht erreicht.

## OP-057 – Elektronische Signatur für Aufträge und Vorsorgeverträge

- Status: offen; bewusst nicht Teil des Papierprozesses OP-056.
- Priorität/Aufwand: Soll / L; Signaturstufe und Anbieter je Dokumentart vor Umsetzung fachlich und rechtlich festlegen.
- Ziel: Signaturanfragen für unveränderliche PDF-Revisionen über eine Nextcloud-kompatible Signaturanwendung, mit internen/externen Unterzeichnern, Statusabgleich, verifizierter signierter Ausgabe und Nachweisablage. „Elektronisch signiert“ darf nicht mit „handschriftlich unterschrieben“ oder einem bloß manuell gesetzten Status verwechselt werden.
