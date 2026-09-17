# BEST-118 – Dokumentationsbasierte Bedienhilfe

Status: lokal umgesetzt für 0.62.0; PHP-Lint und Browser-/Providerabnahme auf Nextcloud 34 ausstehend. Priorität: Soll. Aufwand: M.

## Bewertung der Anforderung

Der bestehende Bestatter-Assistent führt regelbasierte Erfassung, Navigation und bestätigte Aktionen aus. Die neue Bedienhilfe ist ein eigener Dienst, Controller und Frontend-Modul ohne Zugriff auf fachliche Schreib-Services. Die appweite `BestatterAccessMiddleware` schützt auch diese Routen; zusätzliche Aufgaben-Eigentümerprüfung erfolgt beim Polling.

Die Anforderung, beliebige `docs/*.md` an einen Chat-Anbieter zu übermitteln, wäre riskant: Dieses Verzeichnis enthält interne Tickets, OP-Listen, technische Betriebshinweise und teilweise überholte Aussagen; zudem ist es aus der reinen App-ZIP ausgeschlossen. Deshalb wird ausschließlich die redaktionell freigegebene Laufzeitressource `resources/help/BEDIENUNG.md` durchsucht. Neue Themen erfordern eine geprüfte Erweiterung dieser Datei. Quellenangaben stammen aus der serverseitigen Suche, nicht aus Behauptungen des Sprachmodells.

## Akzeptanz und Grenzen

1. Ohne `TextToTextChat::ID` in Nextclouds verfügbaren Aufgabentypen bleibt die Hilfe vollständig verborgen. Der vorhandene Fallassistent bleibt sichtbar und funktionsfähig.
2. Fragen werden vor dem Scheduling auf 500 Zeichen begrenzt; ohne hinreichenden Treffer wird keine Modellaufgabe ausgelöst und eine ehrliche Lücke zurückgegeben.
3. Die Chat-Aufgabe enthält nur die Nutzerfrage und maximal zwei gekürzte Ausschnitte aus der freigegebenen Bedienhilfe. Keine Fall-, Rechnungs-, Kontakt- oder sonstigen Fachdatensätze werden aus der Anwendung nachgeladen.
4. Statusabrufe prüfen dieselbe App, den angemeldeten Benutzer, Hilfekennung und Chat-Aufgabentyp, bevor sie Ergebnisse zurückgeben. Erfolg, Fehler, Abbruch und verzögerte Antworten sind behandelt.
5. Der sichtbare Verlauf besteht nur während der aktuellen Browser-Sitzung; es gibt keine eigene Chat-Tabelle und keine schreibenden Fachaktionen. Nextcloud speichert die asynchronen Task-Daten nach seinen eigenen Regeln.
6. Die UI zeigt die von der Suche ermittelten Dateinamen und Überschriften, weist auf die automatische Erzeugung hin und bittet um Vermeidung personenbezogener Falldaten. Ein Modell kann dennoch Fehler machen; Quellenangaben und Disclaimer sind keine inhaltliche Verifikation.
7. Die ZIP enthält ausschließlich benötigte App-Laufzeitdateien, darunter die eine Hilfe-Ressource; keine internen `docs/`, Tests oder OP-Listen.

## Testfälle auf dem Testsystem

- Ohne Chat-Anbieter: keine Schaltfläche „Bedienhilfe“, keine Fehler; bisheriger Assistent unverändert.
- Mit Chat-Anbieter: „Wie lege ich einen neuen Fall an?“ führt zu einer Antwort mit Quelle `BEDIENUNG.md / Neuen Sterbefall anlegen`.
- „Wie funktioniert die Kaffeemaschine?“ erzeugt keine Modellaufgabe und meldet eine Dokumentationslücke.
- Ein fremdes Nextcloud-Konto ohne Bestatter-Rolle erhält HTTP 403; ein anderes Bestatter-Mitglied darf den fremden Task nicht auslesen.
- Langsame, fehlgeschlagene und abgebrochene Tasks zeigen ihren Zustand ohne Hänger; es entstehen keine Fall- oder Rechnungsänderungen.

Offen: Auswahl und Datenschutzbewertung des Chat-Anbieters durch die Administration, redaktionelle Pflege weiterer Hilfeabschnitte und echte End-to-End-Abnahme nach Installation.
