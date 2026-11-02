# Änderungsstand

Je Version ein Abschnitt `## x.y.z – Titel` (Format des Nextcloud App Stores).

## 0.90.0 – Buchhaltungsexport (DATEV)

- **Neuer Bereich „Buchhaltung“:** Freigegebene Rechnungen, Stornorechnungen und Gutschriften werden als DATEV-Buchungsstapel (EXTF, Formatversion 13) für die Steuerberatung exportiert – ohne Nacherfassung. Sichtbar für Bestatter-Administratoren und die Rollengruppe „Buchhaltung“.
- **Export mit Vorschau und Prüfliste:** Zeitraum wählen, Belege, Buchungszeilen, Soll/Haben und neue Debitoren prüfen, dann exportieren. Fehler (fehlende Kontenzuordnung, unzulässige Belegnummer, Zeitraum über das Wirtschaftsjahr) verhindern den Export, Warnungen (Nummernlücken, Rundungsdifferenzen) werden angezeigt. Die Datei wird vor dem Herunterladen gegen die Formatregeln geprüft.
- **Buchungen:** eine Zeile je Erlöskonto und Steuersatz mit der Rechnungsnummer als Belegfeld; durchlaufende Posten auf eigenem Konto; Storno und Gutschrift im Haben mit Bezug auf die Ursprungsrechnung; Leistungsdatum, Fälligkeit und auf Wunsch Kostenstelle (Niederlassung) und Kostenträger (Fallnummer). Buchungstexte enthalten keine Namen.
- **Kein Beleg doppelt oder vergessen:** Jeder Beleg wird genau einmal exportiert; später freigegebene Rechnungen eines schon exportierten Monats nimmt der nächste Export als Nachzügler mit. Das Exportprotokoll führt Zeitraum, Summen, Datei und Prüfsumme; jede Datei lässt sich unverändert erneut herunterladen, einzelne Belege mit Begründung nachexportieren.
- **Einstellungen:** Berater- und Mandantennummer, Wirtschaftsjahr, Sachkontenlänge, SKR03/SKR04 mit Kontenvorschlag, Kontenzuordnung je Steuersatz, Bereich, Warengruppe oder Artikelart, Debitor je Rechnungsempfänger oder Sammeldebitor. Die Debitorenliste (CSV) liefert Namen und Anschriften zum Anlegen der Personenkonten in DATEV.

## 0.89.10 – Korrekturen aus einem vollständigen Musterauftrag

- **Fall abschließen nach Storno und Neuausstellung:** Sind alle erbrachten Leistungen bereits mit Teilrechnungen berechnet, verlangt die Abschlussprüfung keine Schlussrechnung mehr; es genügt, dass diese Teilrechnungen bezahlt sind. „Schlussrechnung vorbereiten“ erklärt das, statt nur „keine Restmengen“ zu melden.
- **Eingangsrechnungen:** Wird eine Belegposition einer noch nicht berechneten Leistung mit Schätzbetrag, „nach Bescheid“ oder 0,00 € zugeordnet, übernimmt die Leistung jetzt Preis und Steuersatz aus dem Beleg. Festpreise bleiben unverändert.
- **Rechnungen:** Im Kopf steht der Name der Niederlassung statt „Bestatterplattform“; unveränderte Standardvorlagen werden automatisch ersetzt. Nach der Freigabe trägt die PDF keinen Zusatz „Prüfung“ mehr im Dateinamen.
- **Kostenvoranschlag:** „Gültig bis“ im deutschen Datumsformat; die KVA-Nummer wird nicht mehr mit der Auftragsnummer vorbelegt. Fehlt die PDF eines festgeschriebenen KVA oder Auftrags, lässt sie sich mit „PDF erzeugen“ nachholen.
- **Systemprüfung:** neue Prüfung „PDF-Erzeugung“; bei fehlender Niederlassung ein Hinweis zur Abhilfe.
- **Aufgaben und Termine:** Fehlt einer Person der Kalender bzw. die Aufgabenliste, legt die App „Persönlich“ bzw. „Aufgaben“ selbst an, statt die Einträge nur lokal zu halten. Ein Termin lässt sich ohne Änderungsgrund als erledigt markieren; eine vorbelegte Terminvorlage setzt Einordnung und Terminart gleich mit.
- **Klartext:** Zahlungsart „Überweisung“/„SEPA-Lastschrift“, Status der KVA- und Auftragsnachweise sowie fehlende Pflichtangaben bei Dokumenten und Abmeldungen erscheinen mit Bezeichnung statt technischem Schlüssel. Die Kondolenzliste heißt „… Trauerfeier 04.11.2026 11.00 Uhr …“.

## 0.89.9 – Technische Dateien zu Rechnungen ausgeblendet

- **Dokumente aufgeräumt:** Abschlussnachweise (JSON), Prüfberichte der E-Rechnungsprüfung und separate factur-x.xml älterer Rechnungen erscheinen unter „Dokumente“ und in der Fallakte nicht mehr zwischen den Rechnungen. Sie bleiben als Nachweis im Abrechnungsordner; „Technische Dateien anzeigen“ blendet sie bei Bedarf mit einer kurzen Erklärung ein.
- **Sichtbar bleibt, was zur Rechnung gehört:** eine XRechnung und eine factur-x.xml, die nicht in die PDF eingebettet werden konnte. Im zweiten Fall weist die Rechnung darauf hin, dass die XML zusammen mit der PDF aufzubewahren und zu versenden ist.

## 0.89.8 – Gemeinsame Fallablage im Teamordner, Niederlassungen im Einrichtungspaket

- **Fallablage im Teamordner:** Ist der Arbeitsordner „Bestatter“ bei allen Mitarbeitenden derselbe Teamordner, erkennt die App das als gemeinsame Fallablage. Die Systemprüfung meldet „Gemeinsame Fallablage im Teamordner“ statt der Warnung zur benutzerbezogenen Ablage; fehlt der Teamordner bei einzelnen Personen, nennt sie diese. Ein eigenes Ablagekonto ist nicht mehr nötig und wird nicht mehr empfohlen; unter Administration → Fallablage steht es nur noch als aufklappbare Alternative.
- **Niederlassungen im Einrichtungspaket:** Das Einrichtungspaket überträgt jetzt auch Niederlassungen – Anschrift, Länderprofil, Kontakt, Zahlungsziel, Steuer- und Registerdaten, E-Rechnungs-Adresse – ohne Bankverbindung und ohne zugeordnete Personen. Vorhandene Niederlassungen werden über ihren Schlüssel aktualisiert, ihre Bankverbindung bleibt erhalten. Preislisten und Logos einer Niederlassung aus demselben Paket werden mit eingespielt.

## 0.89.7 – Aufträge ohne Sterbefall und Vorsorgen

- **Drei Arten von Akten:** Neben „Sterbefälle“ stehen in der Navigation „Aufträge“ (ohne Sterbefall, zum Beispiel Verkauf, Überführung, Urnenbeisetzung oder Trauerfeier) und „Vorsorgen“. Jede Art hat eine eigene Liste mit Suche und Filtern und einen eigenen Nummernkreis: Aufträge „A-2026-0001“, Vorsorgen „V-2026-0001“; Sterbefälle bleiben unverändert.
- **Anlegen:** „Neuen Auftrag anlegen“ fragt Auftragsart und Kunde ab (bei Firmen genügt der Firmenname), „Neue Vorsorge anlegen“ Name und Geburtsdatum. Die Akte bietet Leistungen, Auftrag, Rechnungen, Dokumente, Aufgaben und Termine wie im Sterbefall; Nebenaufträge und Abmeldungen gibt es nur im Sterbefall.
- **Ablage:** Aufträge und Vorsorgen liegen in eigenen Ordnern neben „Fälle“ („Aufträge“, „Vorsorgen“, Namen einstellbar unter den Installationsparametern). Sicherung, Ordnerplan, Löschung und Umzug der Ablage berücksichtigen alle drei Ordner.
- **Abläufe:** Unter Customizing → Abläufe legt „Gilt für“ fest, ob eine Ablaufvorlage für Sterbefälle, Aufträge und/oder Vorsorgen gilt. Vorhandene Vorlagen gelten weiter für Sterbefälle.
- **Belegeingang:** erkennt auch Nummern mit „A-“ und „V-“; in „V-2026-0001“ wird nicht mehr der Sterbefall „2026-0001“ vorgeschlagen.
- **Auftragsarten:** Customizing → Auftragsarten pflegt die Arten von Aufträgen (Bezeichnung, ob Angaben zur verstorbenen Person dazugehören, Ordner beim Anlegen, vorgeschlagene Warengruppen). Verwendete Arten lassen sich nur deaktivieren. Der Ordnerplan legt mit „Anlegen bei“ fest, welche Ordner bei Sterbefällen, Aufträgen und Vorsorgen entstehen.
- **Firmenkunden:** Auftrag und Rechnung nehmen Firma, Ansprechperson und USt-IdNr. auf; die E-Rechnung (ZUGFeRD/XRechnung) enthält die USt-IdNr. des Käufers. Bei Aufträgen heißt das Dokument „Auftrag“ (eigene Vorlage) statt Bestattungsauftrag.
- **Als Sterbefall weiterführen:** Wird aus einer Überführung oder Urnenbeisetzung ein vollständiger Sterbefall, legt die App nach einer Vorschau einen verknüpften Sterbefall mit Stammdaten, Auftraggeber und Leistungen an; der Auftrag bleibt erhalten.
- **Vorsorge führen:** Reiter „Vorsorge“ (Status, Preisart „Preise zum Zeitpunkt der Ausführung“ oder „Preisgarantie“, Wiedervorlage) und „Finanzierung“ (Treuhand, Sterbegeldversicherung, Verpfändung, Sparanlage u. a. mit Anbieter, Vertragsnummer und Betrag). Vorsorgevertrag bzw. Angebot mit Wünschen, Preisaufstellung und Unterschrift am Tablet; die Arbeitsübersicht zeigt fällige Wiedervorlagen. Unter Customizing → Vorsorge stehen Wiedervorlage, Vorlauf und ein Link zum Treuhand- oder Versicherungsportal.
- **Vorsorge einlösen:** Bei einem neuen Sterbefall weist die Akte auf passende Vorsorgen hin (Name und Geburtsdatum). „Vorsorge übernehmen“ überträgt nach einer Vorschau Wünsche, Leistungen (bei Preisgarantie zum Vertragspreis, sonst zu aktuellen Preisen, Abweichungen sichtbar), Dokumente, Kontakte und Marker und legt Sterbefallmeldungen an Treuhand bzw. Versicherung als Abmeldung an. Die Vorsorge ist danach eingelöst und schreibgeschützt.
- **Datenschutz:** Laufende Vorsorgen erscheinen nie im Löschvorschlag. Beratungen ohne Vertragsabschluss werden nach einer einstellbaren Frist (Standard 2 Jahre) vorgeschlagen; gekündigte, widerrufene und eingelöste Vorsorgen nach den üblichen Fristen.
- **Auswertung und Einrichtungspaket:** Die Auswertung zählt Sterbefälle, Aufträge, laufende, abgeschlossene, eingelöste und beendete Vorsorgen. Das Einrichtungspaket überträgt Auftragsarten und enthält Abläufe für Aufträge, Vorsorgen und den Sterbefall aus einer Vorsorge sowie die Leistung „Vorsorgepauschale“. Die Vorlagen „Auftrag“ und „Bestattungsvorsorgevertrag“ sind Muster und vor dem Einsatz rechtlich zu prüfen.
- **Abläufe für Aufträge und Vorsorgen:** Sie kommen wie alle Ablaufvorlagen mit dem Einrichtungspaket (ab Version 0.89.7). Customizing → Abläufe weist darauf hin, solange keine Vorlage für Aufträge oder Vorsorgen vorhanden ist. Beim Einspielen eines neueren Pakets gelten inhaltlich gleiche Abläufe als unverändert – auch solche aus früheren Versionen der App – und werden nicht mehr als Kopie vorgeschlagen; weicht ein Ablauf ab, nennt die Vorschau, was sich unterscheidet.
- **SQLite:** Fallanlage, Rechnungsnummern und Eingangsrechnungen funktionieren jetzt auch mit einer SQLite-Datenbank (bisher Fehler „Die nächste Fallnummer konnte nicht reserviert werden“). Die Systemprüfung nennt die Datenbank und weist bei SQLite darauf hin, dass Nextcloud es nur für Test- und Kleinstinstanzen empfiehlt.
- **Leistungskatalog:** Beim Filter nach Bereich oder Artikelart klappen die Warengruppen jetzt auf (bisher blieb nach „5 · Floristik“ eine scheinbar leere Liste stehen). Neu ist der Schalter „Alle aufklappen“/„Alle zuklappen“; selbst auf- oder zugeklappte Gruppen behalten ihren Zustand.
- **Neuer Fall:** Wer nach dem Vornamen ins nächste Feld wechselt, sieht statt der Fehlermeldung „Vor- und Nachname sind erforderlich.“ den Hinweis „Der Fall wird angelegt, sobald Vor- und Nachname eingetragen sind.“ Als Fehler erscheint er erst, wenn das Formular ohne Namen verlassen wird.
- **Fall löschen:** Nach dem Löschen werden Liste, Arbeitsübersicht, Aufgaben und Termine sofort aktualisiert; Einträge des gelöschten Falls bleiben nicht mehr stehen, und ein neuer Fall lässt sich direkt anlegen oder öffnen. Ein inzwischen an anderer Stelle gelöschter Fall wird beim Öffnen gemeldet statt ohne Reaktion zu bleiben. Verknüpfte Akten (eingelöste Vorsorge, weitergeführter Auftrag) bleiben nutzbar; die Abfrage vor dem Löschen nennt die Verknüpfung.

## 0.89.6 – Bedienhilfe: Hilfe zu jeder Seite

- **Hilfe zu dieser Seite:** „Bedienhilfe“ in der Navigation oder die Taste F1 öffnet die Hilfetexte zur gerade geöffneten Seite, mit Link auf den passenden Abschnitt im Handbuch. Es kommen keine zusätzlichen Symbole an Reiter oder Felder.
- **Immer verfügbar:** Ohne Chat-Anbieter zeigt die Bedienhilfe die passenden Stellen aus dem Handbuch; mit Chat-Anbieter formuliert sie zusätzlich eine kurze Antwort. Die Suche kennt gleichbedeutende Begriffe (Kästchen = Ankreuzfeld, Etikett = Marker …), durchsucht das ganze Handbuch und bevorzugt Treffer zur aktuellen Seite.
- **„Was bedeutet das?“** an Hinweisen wie „Verweise ohne Ziel im Leistungskatalog“, „Leerer Leistungskatalog“, „Einrichtungshinweis“ oder „Rollen für Abläufe unbesetzt“, **„Mehr im Handbuch“** auf den Seiten mit Regelpflege (Abläufe, Ankreuzfelder, Fremdkosten, Variantenregeln, Zuschlagsstaffeln, Dokumentvorlagen, Einrichtungspaket).
- **Rückmeldung:** „Hat das geholfen?“ – Fragen mit „Nein“ erscheinen ohne Namen unter Administration → Assistenz als *Offene Hilfefragen*.
- **Grenzen benannt:** Was sich derzeit nicht einstellen lässt, steht im Handbuch und in der Bedienhilfe.

## 0.89.5 – Fremdkosten: Schätzbeträge und Gebühren nach Bescheid; Einrichtungspaket

- **Preisart je Position:** Jede Leistung ist Festpreis, Schätzbetrag (Erfahrungswert) oder „nach Bescheid“. Vorbelegt aus dem Leistungskatalog (eigene Leistungen Festpreis, Fremdleistungen Schätzbetrag, Gebühren ohne Richtwert nach Bescheid), im Fall je Position änderbar. Gebühren „nach Bescheid“ dürfen beim Festschreiben ohne Betrag bleiben.
- **Auftrag wie Kostenvoranschlag:** Schätzbeträge stehen mit „ca.“, offene Gebühren als „nach Bescheid“ statt 0,00 € oder gar nicht. Blöcke mit solchen Positionen heißen „voraussichtlich“, der Gesamtbetrag „Voraussichtlicher Gesamtbetrag“ (zzgl. Gebühren nach Bescheid). Unter der Aufstellung steht der fest vereinbarte Anteil, in Abschnitt 2 der Hinweis auf Abrechnung in tatsächlicher Höhe und die Abweichungsschwelle. Auch die Unterschriftsansicht am Tablet zeigt die Kennzeichen.
- **Typische Fremdkosten:** Neuer Customizing-Reiter „Fremdkosten“ mit Abweichungsschwelle (Standard 10 %) und der Liste der üblichen Fremdkosten (Sterbeurkunden, zweite Leichenschau, Einäscherung, Grab, Friedhofsgebühren, Trauerhalle …) je Bestattungsart oder -variante, Bedingung und Menge. Im Fall übernimmt „Typische Fremdkosten übernehmen“ alle fehlenden auf einmal; vor dem Festschreiben erscheint ein Hinweis, wenn eine fehlt oder die Menge Sterbeurkunden nicht passt.
- **Neue Installation ohne Beispielinhalte:** Beispielkatalog, Musterauftragsformular, Ablaufvorlagen und Marker-Vorschläge gehören nicht mehr zum App-Paket. Eine neue Installation startet leer und erhält diese Inhalte mit einem Einrichtungspaket; Ersteinrichtung, Leistungskatalog und Systemprüfung weisen darauf hin. Bestehende Installationen behalten alle Inhalte.
- **Einrichtungspaket erweitert:** Es überträgt zusätzlich Ankreuzfelder des Auftrags, typische Fremdkosten, Variantenregeln und Zuschlagsstaffeln. Fehlende Bereiche des Katalogs werden beim Einspielen angelegt, neue Abläufe übernehmen den Aktiv-Schalter aus dem Paket.
- **Verweisprüfung:** Ankreuzfelder, typische Fremdkosten, Variantenregeln und Zuschlagsstaffeln verweisen auf Warengruppen und Artikel. Fehlt eine Warengruppe oder ein Artikel im Katalog, nennen Systemprüfung, der jeweilige Customizing-Reiter und das Ergebnis eines Paketimports die betroffene Einstellung.
- **Ankreuzfelder nach Warengruppe:** Die Vorbelegung richtet sich nach der Warengruppe der Position, nicht mehr nach den ersten Ziffern der Artikelnummer – so passen die Felder auch zu einem eigenen Katalog mit anderem Nummernschema.

## 0.89.4 – Übersichtlicher Fall-Verlauf

- **Technische Einträge nur bei Bedarf:** Der Abgleich mit Nextcloud Aufgaben und Kalender (Kennungen, Versionsstempel, Rücksprung-Links) und interne Ablaufangaben erscheinen nicht mehr im Fall-Verlauf. Der Schalter „Technische Einträge anzeigen“ blendet sie für die Fehlersuche ein; die Zahl dahinter nennt, wie viele es sind. Das Protokoll selbst bleibt vollständig.
- **Klartext:** Felder aus Auftrag, Rechnungsempfänger und Unterschrift tragen ihre Bezeichnung (z. B. „Unterschrieben von“, „Rechnungsempfänger: Straße“), Statuswerte und Wertelisten erscheinen lesbar („Offen → Erledigt“), Prüfsummen gekürzt. Bei geänderten Aufgaben, Terminen und Dokumenten steht, welcher Eintrag gemeint ist.
- **Gleichzeitig angelegte Aufgaben zusammengefasst:** Legt der Ablauf mehrere Aufgaben auf einmal an, steht im Verlauf ein Eintrag wie „13 Aufgaben angelegt“, der sich aufklappen lässt.
- **Leistungen benannt:** „Leistungsauswahl geändert“ zeigt, welche Leistungen hinzugefügt, entfernt oder storniert und wo Menge oder Preis geändert wurden; die Leistungserfassung nennt erbrachte Menge und Abrechnung.
- **Vorgänge mit Angaben:** Unterschrift am Tablet (wer, wo), Rechnungen (Nummer, Art, Statuswechsel), E-Mail-Versand (Empfänger), Ablaufaktionen und Begründungen erscheinen mit einer kurzen Angabe statt eines leeren Eintrags.

## 0.89.3 – Ankreuzfelder im Auftrag selbst festlegen

- **Customizing → Ankreuzfelder:** Die Kästchen im Bestattungsauftrag lassen sich selbst pflegen: Bezeichnung, Abschnitt (Leistungsumfang, Vollmacht, Einwilligungen und Erklärungen, Weitere Vereinbarungen), wer ankreuzt, Vorbelegung und Reihenfolge. Jedes Feld hat einen festen Platzhalter `{{order.choice.SCHLÜSSEL}}` für eine eigene Auftragsvorlage. Die sieben bisherigen Felder sind mitgeliefert und lassen sich ändern oder deaktivieren.
- **Felder für den Kunden:** Einwilligungen wie „Traueranzeige veröffentlichen“ sind nicht vorbelegt. Der Auftraggeber kreuzt sie bei der Unterschrift am Tablet selbst an; seine Auswahl steht im Dokument und im Nachweis.
- **Kästchen und Positionen passen zusammen:** Widerspricht ein von Hand gesetztes Kästchen den beauftragten Positionen (z. B. „Organisation der Trauerfeier“ angekreuzt, aber keine Leistung dafür), zeigt der Auftrag einen Hinweis mit „Passende Leistung suchen“. Vor dem Festschreiben und vor der Unterschrift am Tablet ist der Hinweis zu bestätigen.
- **Nach dem Festschreiben geschützt:** Die Kästchen lassen sich wie die Positionen nicht mehr ändern. Unterschrift und Erklärungen des Kunden lassen sich nur bei der Unterschrift am Tablet setzen.
- **E-Mail-Vorlagen (Customizing → E-Mail-Vorlagen):** Betreff und Text für den Versand von Bestattungsauftrag, Kostenvoranschlag und Rechnung (auch Storno und Gutschrift) mit Anrede („Sehr geehrte Frau …“), Nummer, Datum, Betrag, Zahlungshinweis und Signatur der Niederlassung mit Pflichtangaben; Platzhalter per Klick, Vorschau mit Beispieldaten, Signaturzusatz für alle Niederlassungen. Die Versandvorschau im Fall ist damit vorbelegt (Empfänger, Betreff, Text) und lässt sich anpassen; beim Auftrag mit Widerrufsrecht hängt sie die Widerrufsbelehrung mit Muster-Widerrufsformular zusätzlich an. Auch die Ausfertigung nach der Unterschrift am Tablet nutzt die Vorlage.
- **Prüfung der Auftragsvorlage:** Unter Customizing → Dokumentvorlagen zeigt der Bestattungsauftrag, welche aktiven Ankreuzfelder in der Datei fehlen, ob Platzhalter ohne Feld oder fest getippte Kästchen (☐) enthalten sind und ob das Feld für die Unterschrift am Tablet vorhanden ist. Im Auftrag und bei der Unterschrift erscheinen nur Felder, die in der verwendeten Vorlage stehen.

## 0.89.1 – Unterschriftsansicht bleibt offen

- Die Ansicht „Am Tablet unterschreiben lassen“ schließt sich nicht mehr von selbst, wenn im Hintergrund Aufgaben und Termine abgeglichen werden (jede Minute und beim Wechsel in das Browserfenster).

## 0.89.0 – Bestattungsauftrag vor Ort am Tablet unterschreiben

- **Am Tablet unterschreiben lassen:** Im Reiter „Auftrag / KVA“ öffnet „Am Tablet unterschreiben lassen“ eine Ansicht für den Auftraggeber über die ganze Seite: das Dokument „Bestattungsauftrag und Vollmacht“, darunter eine Übersicht der Leistungen mit Gesamtsumme und der angekreuzten Kästchen.
- **Der Kunde kreuzt selbst an:** Die Erklärungen zum Beginn der Leistungen (nur bei Vertragsschluss außerhalb der Geschäftsräume oder im Fernabsatz) sind nicht vorbelegt. Dazu bestätigt der Kunde das Lesen, prüft Name und Ort und unterschreibt mit Finger, Stift oder Maus.
- **Verbindlich unterschreiben:** erzeugt eine nicht bearbeitbare PDF mit Unterschrift, Ort, Datum und Uhrzeit, den angekreuzten Erklärungen und der Annahme durch das Bestattungsunternehmen. Sie liegt in der Fallakte als „Unterschrieben“. Ein noch nicht festgeschriebener Auftrag wird dabei festgeschrieben. Der Nachweis (Prüfwerte der Unterschrift und der PDF, Erklärungen, annehmende Person) steht im Dokument und im Protokoll.
- **Ausfertigung per E-Mail:** Ist der Nextcloud-Mailversand eingerichtet, geht eine Ausfertigung an die Adresse des Kunden, bei Widerrufsrecht zusammen mit der Widerrufsbelehrung.
- **PDF-Vorschauen in der App:** Vorschauen von Auftrag, Rechnung und Dokumentvorlagen erscheinen wieder direkt im Fenster; die Sicherheitsrichtlinie der Seite hatte die Anzeige je nach Browser blockiert.
- Die Vorlage „Bestattungsauftrag und Vollmacht“ hat dafür neue Felder in der Unterschriftszeile. Eine unveränderte Standardfassung wird automatisch ersetzt, eine eigene Fassung lässt sich unter Customizing → Dokumentvorlagen umstellen. Der Papierweg (ausdrucken, unterschreiben, Scan hochladen) bleibt unverändert.

## 0.88.3 – Kästchen im Bestattungsauftrag selbst festlegen

- **Leistungsumfang und Vollmacht ankreuzen:** Im Reiter „Auftrag / KVA“ stehen die Kästchen des Dokuments „Bestattungsauftrag und Vollmacht“ zum An- und Abwählen. Die App kreuzt weiterhin aus Leistungen und Bestattungsart vor und nennt den Grund; eine Auswahl von Hand hat Vorrang und lässt sich auf „automatisch“ zurücksetzen. Vorschau und Dokument zeigen genau diese Auswahl.

## 0.88.2 – Dokumentvorlagen übersichtlich, Bestattungsauftrag mit Vollmacht gezielt aktivieren

- **Dokumentvorlagen neu geordnet:** eine Zeile je Vorlage mit voller Bezeichnung, Datei und Ablage, gruppiert nach Bereich; Suche, Bereichsfilter und „Nur mit Handlungsbedarf“. Bearbeiten klappt das Formular unter der Zeile auf; Feldkatalog und Logo-Platzhalter stehen unter „Werkzeuge“.
- **Zustand der Datei sichtbar:** Standardfassung, ältere Standardfassung (wird automatisch ersetzt), eigene Fassung, noch nicht im Vorlagenordner oder Datei fehlt. Beim Bestattungsauftrag zeigt „Mit/Ohne Vollmachtsteil“, ob Auftrag und Vollmacht in einem Dokument stehen.
- **„Neue Standardfassung übernehmen“:** stellt eine angepasste ältere Vorlage auf die mitgelieferte Fassung um, zum Beispiel auf „Bestattungsauftrag und Vollmacht“. Die bisherige Datei bleibt als datierte Kopie erhalten, der Kostenvoranschlag behält das bisherige Layout.
- Fest verwendete Vorlagen lassen sich nicht mehr versehentlich löschen; Bestattungsauftrag, Rechnung und Storno/Gutschrift bleiben immer aktiv.

## 0.88.1 – Übersichtlichere Fallakte, auch auf schmalen Bildschirmen

- **Schmale Bildschirme:** Die Seite scrollt nicht mehr seitlich – auch nicht im Reiter „Auftrag / KVA“, der bisher bei jeder Fensterbreite zu breit war. Suche und Filter der Fallliste stehen untereinander, Reiterleisten brechen um. Unter 768 px wählt man den Bereich der Fallakte über eine Auswahl statt über zwölf Reiter.
- **Nichts verdeckt:** Der Assistent ist auf schmalen Bildschirmen ein kleiner runder Knopf, am Seitenende bleibt Platz. Der Hinweis „Änderungen werden automatisch gespeichert.“ liegt nicht mehr über dem Formular.
- **Speicherstatus im Fallkopf:** „Speichert …“, „Gespeichert um …“ und Fehler für Stammdaten, Auftrag und Leistungen stehen neben dem Namen und bleiben beim Scrollen in einer schmalen Kopfzeile sichtbar. Ein Fehler bleibt stehen, bis erfolgreich gespeichert wurde. Vor einem Bereichswechsel wird eine ausstehende Speicherung sofort ausgeführt.
- **Aufgaben und Termine zuerst:** Die Liste mit „Neu anlegen“ steht oben, der Ablauf nach Phasen bzw. die Terminschritte darunter und lassen sich einklappen.
- **Fortschritt verständlicher:** „7 jetzt offen · 16 später · 0 erledigt“ statt „0 von 23 Schritten erledigt“.
- **Nach dem Anlegen eines Falls** zeigt die Übersicht, welche Aufgaben mit welcher Fälligkeit entstanden sind, mit Weg zu den Aufgaben.
- **Unbesetzte Rollen** (keine Nextcloud-Gruppe mit Mitgliedern) meldet die Startseite einmal für Administratoren, mit Weg zur Rollenzuordnung; im einzelnen Schritt steht nur noch eine kurze Marke.
- **Weitere Aktionen:** „Falldaten exportieren“, „Legal Hold“ und „Fall löschen“ stehen in einem beschrifteten Menü; die Sicherheitsabfrage beim Löschen bleibt. Berührungsflächen auf Touch-Geräten mindestens 44 px.

## 0.88.0 – Bestattungsauftrag und Vollmacht in einem Dokument

- **Bestattungsauftrag und Vollmacht:** Auftrag, Leistungs- und Preisaufstellung, eine auf den Bestattungsfall beschränkte Vollmacht, Erklärungen zum Widerrufsrecht und Unterschriften (Auftraggeber/in und Bestattungsunternehmen) in einem Dokument – ein Unterschriftsvorgang, ein Scan. Kästchen für Leistungsumfang und Vollmacht werden aus dem Fall vorbelegt.
- **Vertragsschluss:** neues Pflichtfeld im Auftrag (Geschäftsräume, außerhalb, Fernabsatz). Außerhalb der Geschäftsräume und im Fernabsatz wird die Widerrufsbelehrung mit gesetzlichem Mustertext und Muster-Widerrufsformular beigefügt.
- **Kostenvoranschlag** mit eigener Vorlage (bisheriges Layout). Angepasste Auftragsvorlagen bleiben erhalten und erhalten weiter die gesonderte Vollmacht.
- Neue Felder: Zahlungsziel/Abschläge, besondere Abreden/Kostenobergrenze, Vollmacht für weitere Stellen.

## 0.87.0 – Prüfung der PHP-Abhängigkeiten, Tests vor jedem Paket

- **Systemprüfung „PHP-Abhängigkeiten“** (auch in `occ bestatter:update-check`): meldet einen Fehler, wenn `vendor/`, die QR-Code-Bibliotheken oder die PHP-Erweiterung GD fehlen – typischerweise, wenn die App aus dem Quellcode statt aus dem Installationspaket eingespielt wurde.
- **Paketbau:** `tools/release/build-package.sh` führt vor dem Bauen Tests und PHPStan aus, sofern der Ordner `tests/` vorhanden ist; bei Fehlern entsteht kein Paket.

## 0.86.0 – Fallbezogene Kontakte ohne Doppelerfassung

- **Auftraggeber/in im Reiter „Kontakte“:** erscheint automatisch aus dem Auftrag (keine Kopie); „Im Auftrag bearbeiten“ führt zur Pflege.
- **Ins Adressbuch übernehmen** – nur auf Wunsch, mit Dublettenprüfung; der Fall merkt sich die Verknüpfung, „Adressbuch-Eintrag aktualisieren“ übernimmt spätere Änderungen.
- **Personen nur für diesen Fall** (z. B. Kinder, Geschwister): werden mit dem Fall gelöscht bzw. anonymisiert.
- **Bessere Dublettenprüfung** beim Anlegen von Kontakten: Name unabhängig von Reihenfolge, Umlauten und Titel, Telefonnummer, E-Mail, Anschrift; Auswahl „Vorhandenen Kontakt verwenden“ oder „Trotzdem neu anlegen“.
- Löschabfrage und Aufbewahrungsvorschau nennen Personen, deren Adressbucheintrag stehen bleibt.

## 0.85.0 – Schnellerfassung: Sterbeurkunden und unterschriebener Auftrag

- **Sterbeurkunden:** Nennt das Gespräch nur eine Anzahl, wird sie als gebührenpflichtig vorbelegt; die Aufteilung steht als Prüfhinweis in den Schritten „Angaben“ und „Prüfen“.
- **Unterschriebener Auftrag:** Ein Hinweis wie „der unterschriebene Auftrag liegt vor“ erzeugt beim Anlegen die Aufgabe „Unterschriebenen Auftrag hochladen und prüfen“ (abwählbar). Als unterschrieben gilt der Auftrag weiterhin erst nach Upload und Sichtprüfung des Scans oder nach digitaler Unterschrift.
- Schritt „Prüfen“ nennt unbeantwortete Rückfragen unter „Noch offen“.

## 0.84.1 – Audit-Kette und Prüfansicht der Schnellerfassung

- **Audit-Protokoll:** Gleichzeitige Einträge (zum Beispiel ein Klick und ein Hintergrundjob im selben Augenblick) werden jetzt nacheinander verkettet. Die Prüfung unterscheidet gleichzeitig geschriebene Einträge aus früheren Versionen (Inhalt unverändert, nur ein Hinweis) von echten Schäden und nennt die Nummern der betroffenen Einträge.
- **Schnellerfassung, Schritt „Prüfen“:** Bezeichnungen und Werte überlagern sich nicht mehr; die Zusammenfassung steht in denselben Blöcken wie im Schritt „Angaben“ und nennt noch offene wichtige Angaben.

## 0.84.0 – Schnellerfassung versteht freiere Formulierungen

- **Erkennung im Gesprächstext:** Anschrift in beliebiger Reihenfolge („wohnte zuletzt in 28357 Bremen, Brandenweg 5a“, „Anschrift: Am Markt 3, 12345 Ort“), Sterbeort auch nach einem Datum und „zu Hause verstorben“, weitere Bestattungsarten (Seebestattung, Baumbestattung/FriedWald/RuheForst, Waldbestattung, Urnenübergabe an Angehörige), kleine Tippfehler in Schlüsselwörtern. Eine Anzahl Sterbeurkunden ohne Angabe gebührenfrei/-pflichtig führt zu einer gezielten Rückfrage.
- **Schritt „Angaben“ in Blöcken** wie beim Anlegen eines Falls, aber auf einer Seite; aus Gespräch oder Bogen übernommene Felder sind hervorgehoben.
- README: Abschnitt „Entstehung“.

## 0.83.3 – Erste öffentliche Fassung

Erste Veröffentlichung des Quellcodes unter der GNU Affero General Public License, Version 3 (AGPL-3.0-only). Funktionsumfang dieser Fassung:

- **Digitale Fallakte** mit Stammdaten, Kontakten, Aufgaben, Terminen, Dokumenten, Leistungen und Rechnungen; Fallübersicht, globale Nextcloud-Suche, Marker mit Notizen.
- **Schnellerfassung** eines Auftrags – auf Wunsch mit Texterkennung eines Erfassungsbogens und Spracherkennung über Nextcloud-Anbieter; jede Übernahme wird bestätigt.
- **Abläufe in 7 Phasen** mit Auslösern, Rollen (Nextcloud-Gruppen), Fristen und automatisch erzeugten Nextcloud-Aufgaben; Prüfhinweise nennen fehlende oder umbenannte Rollengruppen.
- **Termine und Ressourcen** mit Konfliktprüfung und Kalenderabgleich.
- **Dokumente** aus Vorlagen (DOCX/PDF), Dokumentarten, Abmeldungen mit Fallnummer als „Unser Zeichen“.
- **Abrechnung** mit Leistungskatalog, Kostenvoranschlag, Auftrag, Teil- und Schlussrechnungen, ZUGFeRD/XRechnung und EPC-Zahlcode, Nebenaufträge.
- **Paperless-ngx (optional)** – Belegeingang mit Zuordnungsvorschlägen, Archivkopien und Erfassungsbögen steuerbar.
- **Betrieb** – Systemprüfung, unveränderliches Audit-Log, Sicherung und Wiederherstellung (auch automatisch), Aufbewahrung, Einrichtungspakete, Update-Skript `bin/bestatter-update.sh` mit `occ bestatter:update-check`.
- **Oberfläche** – einklappbare Navigation links, Handbuch (HTML und PDF) und Bedienhilfe in der App.
