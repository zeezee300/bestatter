# Änderungsstand

Je Version ein Abschnitt `## x.y.z – Titel` (Format des Nextcloud App Stores).

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
