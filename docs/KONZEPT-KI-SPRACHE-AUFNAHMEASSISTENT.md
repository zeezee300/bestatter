# Konzepterweiterung: Geführte Schnellerfassung, Sprache und KI-Assistent

> Umsetzungsstand 0.36.0: Der geführte Wizard, lokale Entwurfsfortsetzung, regelbasierte Feldvorschläge, Spracheingabe, sichere Intent-Vorschau/-Bestätigung und der fachliche Aktionskatalog sind umgesetzt. Der Assistent unterstützt Fallsuche, Tagesübersicht, Stammdaten, Aufgaben, Termine, Checklisten, Leistungen, Abmeldungen, Dokumentausgabe, Teil-/Schlussrechnung und Systemprüfung. Ein LLM ist nicht erforderlich; die optionale provider-neutrale Erweiterung ist als OP-019 zurückgestellt.

## 1. Ziel und Leitentscheidung

Die Auftragserfassung und die tägliche Fallbearbeitung sollen deutlich weniger Eingabeaufwand verursachen. Die Erweiterung wird als ein gemeinsames Assistenzmodul entwickelt, aber in drei getrennt aktivierbaren Ausbaustufen eingeführt und abgenommen.

Die KI ist ein **Vorschlags- und Erfassungsassistent**, kein autonom handelnder Sachbearbeiter. Fachliche Änderungen werden erst nach einer verständlichen Vorschau und ausdrücklicher Bestätigung gespeichert. Versand, Finalisierung von Dokumenten oder Rechnungen, Statusabschluss sowie das Erledigen von Aufgaben und Terminen erfolgen niemals allein aufgrund einer KI-Antwort.

## 2. Zielbild

```text
Mikrofon oder Texteingabe
    -> Transkription
    -> strukturierte Feldvorschläge
    -> serverseitige Validierung und Fachregeln
    -> Prüf- und Bestätigungsdialog
    -> vorhandene Bestatter-Services
    -> Audit-Log
```

Die bestehenden Services bleiben die einzige schreibende fachliche Instanz. Der Assistent erhält keine eigene Möglichkeit, Validierungen, Berechtigungen, Artikelstammdaten, Preisberechnungen oder Workflow-Sperren zu umgehen.

## 3. Ausbaustufe 1: Geführte Schnellerfassung

### Funktionsumfang

- neuer Einstieg „Sterbefall / Auftrag schnell erfassen“;
- schrittweise Erfassung von Sterbefall, Angehörigen, Auftraggeber, Rechnungsempfänger, Bestattungsart, Niederlassung und ersten Terminen;
- Übernahme vorhandener Kontakte mit Anzeige möglicher Treffer statt stiller automatischer Zuordnung;
- Vorbelegung von Niederlassung, zuständigem Benutzer, aktuellem Datum und betrieblichen Standardwerten;
- Vorlagen für typische Fallarten, zunächst Erd- und Feuerbestattung sowie Überführung;
- „Speichern und weiter“, Zwischenspeicherung als Entwurf und Fortsetzung ohne Datenverlust;
- progressive Anzeige: selten benötigte Felder werden erst bei Bedarf eingeblendet;
- kompakte Zusammenfassung mit offenen Pflichtangaben;
- mobile, touchfreundliche Erfassung.

### Abnahmekriterien

- Ein Musterfall kann ohne Wechsel zwischen mehreren Hauptansichten als Entwurf angelegt werden.
- Bereits erfasste Daten müssen nicht erneut für Auftrag, Termine, Dokumente oder Rechnung eingegeben werden.
- Abbruch und Fortsetzung erhalten alle bestätigten Eingaben.
- Pflichtfelder, mehrdeutige Kontaktzuordnungen und fachliche Widersprüche werden verständlich angezeigt.

## 4. Ausbaustufe 2: Spracheingabe und strukturierte Übernahme

### Funktionsumfang

- Mikrofon-Schaltflächen an geeigneten Freitext-, Aufgaben- und Terminfeldern;
- Modus „Aufnahmegespräch diktieren“ für längere zusammenhängende Erfassung;
- Transkription über die zentrale Nextcloud-Task-Processing-Schnittstelle;
- bevorzugt lokaler Speech-to-Text-Provider, beispielsweise Nextcloud Local Whisper;
- Extraktion in ein fest definiertes JSON-Schema mit Feldschlüssel, vorgeschlagenem Wert, Konfidenz und Herkunft im Transkript;
- Erkennung von Personen, Organisationen, Datums-/Zeitangaben, Orten, Beziehungen, Bestattungsart und gewünschten Aktivitäten;
- Markierung widersprüchlicher oder unsicherer Angaben;
- selektive Übernahme einzelner Vorschläge;
- nicht zuordenbare Aussagen werden als Gesprächsnotiz angeboten und nicht verworfen.

### Sicherheitsregeln

- Audio wird in einem nicht allgemein freigegebenen temporären App-Bereich verarbeitet.
- Standardmäßig wird die Audiodatei nach erfolgreicher Transkription gelöscht; eine abweichende Aufbewahrung muss administrativ aktiviert werden.
- Transkript und Vorschläge werden keinem anderen Fall zugänglich gemacht.
- Mehrdeutige Datumsangaben wie „nächsten Freitag“ benötigen eine sichtbare Bestätigung mit absolutem Datum.
- IBAN, Preise, Artikelnummern und Kontakte werden gegen vorhandene Daten geprüft und niemals erfunden.

### Abnahmekriterien

- Eine deutschsprachige Testaufnahme erzeugt ein nachvollziehbares Transkript.
- Erkannte Werte werden vor der Übernahme neben den bestehenden Formularwerten angezeigt.
- Ohne Benutzerbestätigung wird kein Fachdatenbestand geändert.
- Fehler oder nicht erreichbare KI-Dienste lassen die normale manuelle Erfassung vollständig nutzbar.
- Temporäre Audiodateien werden entsprechend der konfigurierten Aufbewahrung bereinigt.

## 5. Ausbaustufe 3: Kontextbezogener Bestatter-Assistent

### Bedienkonzept

Der Assistent erscheint als Seitenleiste innerhalb der Bestatter-App. Er kennt ausschließlich den für den angemeldeten Benutzer zulässigen Kontext der aktuellen Ansicht oder des geöffneten Falls.

Er unterstützt drei Arten von Anfragen:

1. **Auskunft:** „Was ist heute fällig?“ oder „Welche Angaben fehlen für die Rechnung?“
2. **Navigation:** „Öffne die Dokumente des Falls“ oder „Zeige die offenen Aufgaben“.
3. **Vorbereitete Aktion:** „Lege die Trauerfeier am Freitag um 11 Uhr an“ oder „Bereite das Kondolenzlisten-Paket vor“.

Schreibende Aktionen werden auf eine serverseitige Positivliste fester Intents begrenzt. Jeder Intent besitzt ein definiertes Eingabeschema, Berechtigungsprüfung, Validierung und eine Vorschau. Freie, vom Modell erzeugte Routen- oder Serviceaufrufe sind ausgeschlossen.

### Erste erlaubte Intents

- Aufgabenentwurf anlegen;
- Termin-/Fristentwurf anlegen;
- vorhandenen Kontakt als Teilnehmer vorschlagen;
- vorhandene Leistungen oder Artikel zur Auswahl vorschlagen;
- Dokument- oder Dokumentpaketerzeugung vorbereiten;
- fehlende Pflichtangaben eines KVA, Auftrags oder Rechnungsentwurfs ermitteln;
- vorhandene Fallansicht oder Aktivität öffnen.

### Ausdrücklich ausgeschlossene autonome Aktionen

- Dokumente oder Rechnungen finalisieren;
- E-Mails, Abmeldungen oder Rechnungen versenden;
- Aufgaben oder Termine als erledigt markieren;
- Rechnungsnummern vergeben;
- Fallstatus abschließen;
- Preise, Artikel, Kontaktdaten oder Bankdaten neu erfinden;
- Löschungen und Massenänderungen.

### Abnahmekriterien

- Nicht erlaubte Absichten werden abgewiesen oder in eine rein erklärende Antwort umgewandelt.
- Jede vorgeschlagene Änderung zeigt Zielobjekt, alte und neue Werte sowie mögliche Folgeaktionen.
- Benutzer, Zeitpunkt, Provider/Modell, bestätigte Vorschläge und Ergebnis werden im Audit-Log protokolliert; vollständige Prompts werden nur gespeichert, wenn dies datenschutzrechtlich freigegeben ist.
- Ein Fehler des Modells kann keine unvollständige fachliche Transaktion hinterlassen.

## 6. Technische Architektur

Vorgesehene App-Komponenten:

- `AssistantConfigurationService`: Aktivierung, Providerfähigkeiten, Sprache, Aufbewahrung und Grenzwerte;
- `SpeechTranscriptionService`: Adapter zur Nextcloud-Task-Processing-Schnittstelle;
- `StructuredCaptureService`: strikt schemaorientierte Extraktion aus Transkripten oder Texteingaben;
- `AssistantIntentService`: Positivliste und Auflösung erlaubter Bedienabsichten;
- `AssistantValidationService`: fachübergreifende Vorprüfung, ohne bestehende Fachservices zu ersetzen;
- `AssistantAuditService` beziehungsweise Erweiterung des vorhandenen `AuditService`;
- eigene API-Routen für Transkriptionsauftrag, Statusabfrage, Vorschläge und bestätigte Ausführung;
- Frontendmodule für Schnellerfassung, Aufnahme, Review-Dialog und Assistenten-Seitenleiste.

Die Integration verwendet möglichst Nextclouds einheitliche AI-/Task-Processing-API. Damit kann eine lokale oder später ausdrücklich freigegebene externe Providerimplementierung ausgetauscht werden, ohne die fachliche Bestatter-App neu zu schreiben. Nextcloud dokumentiert Speech-to-Text sowie lokale Provider als Teil dieser modularen Architektur: [Nextcloud Assistant](https://docs.nextcloud.com/server/stable/admin_manual/ai/app_assistant.html) und [AI-Übersicht](https://docs.nextcloud.com/server/stable/admin_manual/ai/overview.html).

## 7. Datenschutz, Berechtigungen und Betrieb

- lokale Verarbeitung ist der Standard für Fall-, Angehörigen-, Gesundheits- und Finanzdaten;
- externe Provider sind standardmäßig deaktiviert und benötigen eine bewusste administrative Freigabe;
- Berechtigungen werden für jede Abfrage und jede vorgeschlagene Aktion erneut serverseitig geprüft;
- Datenminimierung: nur die für den konkreten Intent erforderlichen Fallinformationen werden an den Provider übergeben;
- keine Falldaten für Training oder Produktverbesserung des Providers;
- konfigurierbare Löschfristen für Audio, Transkripte und technische Fehlerdaten;
- klare Anzeige, wenn Text oder Audio einen Server außerhalb der eigenen Infrastruktur verlassen würde;
- Systemprüfung für Providerverfügbarkeit, Task-Processing, Aufbewahrungsbereinigung und Modellkonfiguration.

Diese Festlegungen unterstützen Datenminimierung sowie Datenschutz durch Technikgestaltung und datenschutzfreundliche Voreinstellungen gemäß Art. 5 und Art. 25 DSGVO. Eine abschließende betriebliche und rechtliche Freigabe bleibt erforderlich.

## 8. Entwicklung, Installation und Test

Die drei Stufen werden in einem gemeinsamen Entwicklungsstrang umgesetzt, jedoch nacheinander aktiviert:

1. gemeinsame Datenmodelle, Konfiguration, Berechtigungen und Audit-Grundlage;
2. Schnellerfassungsoberfläche und Entwurfslogik;
3. Speech-to-Text-Adapter und Review-Dialog;
4. strukturierte Extraktion und Validierung;
5. Assistenten-Seitenleiste mit zunächst kleiner Intent-Positivliste;
6. Systemprüfung, Dokumentation, statische Tests und Runtime-Tests;
7. Installation des Releasepakets auf dem Testserver;
8. Ende-zu-Ende-Abnahme mit synthetischen Daten, Desktop und Mobilansicht.

### Servervoraussetzungen

- Nextcloud AppAPI und ein kompatibler Task-Processing-/Assistant-Provider;
- ein Speech-to-Text-Provider für Deutsch;
- für lokale Modelle ausreichende CPU-/RAM-Ressourcen, für flüssige Verarbeitung vorzugsweise eine unterstützte GPU;
- kein echter Versand während Entwicklung und Abnahme;
- Testkonto mit Bestatter-Mitgliedschaft und ein Administrationskonto.

Fehlt der lokale KI-Provider, kann die App einschließlich manueller Schnellerfassung, Provideradapter, Fehlerbehandlung und Mock-Tests vollständig entwickelt werden. Der reale Sprach- und Modelltest bleibt dann bis zur Installation des Providers offen.

## 9. Release- und Abnahmestrategie

Die Ausbaustufen werden nicht als untrennbarer „Big Bang“ ausgeliefert. Jede Stufe erhält ein Feature-Flag und kann unabhängig deaktiviert werden:

- `guidedCaptureEnabled`
- `speechInputEnabled`
- `assistantEnabled`
- `externalAiProvidersAllowed` (Standard: `false`)

So können alle drei Stufen zusammen entwickelt und installiert werden, während die Abnahme kontrolliert nacheinander erfolgt. Ein Fehler in Sprache oder KI darf die bestehende Bestatter-App und die manuelle Bedienung nicht blockieren.
