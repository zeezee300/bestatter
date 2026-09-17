# BEST-117 – Geführte Bestattungsvarianten und pflegbare Zuschlagsstaffeln

Status: Varianten- und Zuschlagsregeln lokal umgesetzt; Pflege des Variantenbaums nach Nutzerhinweis ergänzt. Migration 3700 in Korrekturversion 0.61.1 ohne selbstreferenzierenden Fremdschlüssel. Die erneute Abnahme der Variantenpflege im Testsystem bleibt offen. Priorität: Muss. Aufwand: L. Ursprung: Ergänzung zum Anforderungsprompt für den Leistungskatalog; `OP-007` bezeichnet in der bestehenden OP-Liste bereits das eigenständige Artikelmodul und wird nicht umgewidmet.

## Fachliche Entscheidung

Die Auswahl erfolgt stufenweise über feste technische Variantenkennungen. Bestandsdaten mit freiem Bestattungstext bleiben lesbar. Für Feuer-, Erd-, See- und Waldbestattungen dürfen Varianten organisatorisch zugeordnet werden. Almwiesen-, Kristall- und Wiesenbestattung bleiben ohne bestätigte Leistungs-/Kremationszuordnung; zu diesen Varianten dürfen keine aktiven Leistungsregeln angelegt werden.

Die Fallmaske bietet „Trauerfeier“ als ja/nein/offen sowie Abholzeit, Größe und Gewicht. Eine aktivierte Trauerfeier legt einmalig eine Aufgabe zur Terminabstimmung an, soweit kein entsprechender Trauerfeiertermin bereits vorliegt. Eine spätere Deaktivierung entfernt keinen bereits erzeugten Datensatz; dies ist ein bewusst zu bearbeitender Arbeitsvorgang.

Variantenregeln können intern auf benötigte Artikelgruppen, einen Standardartikel, einen konkreten Vorschlag oder eine ausgeschlossene Gruppe hinweisen. Standards werden **nicht** stillschweigend als kostenpflichtige Auftragsposition eingefügt. Die Bearbeitung kann für einzelne Pflichtregeln als „später entscheiden“ markiert werden. Hinweise erscheinen nicht auf Kundenbelegen. Insbesondere ist die derzeitige Artikelgruppe „Sarg“ für eine Urnenpflicht ungeeignet, solange darin auch Urnen liegen.

Zuschlagsstaffeln für Abholzeit, Größe und Gewicht sind durch Administratoren pflegbar und an genau eine Katalogleistung mit ihrem Preis verknüpfbar. Anfangsstaffeln sind deaktiviert. Je Merkmal kann im Fall eine aktive Staffel bewusst ausgewählt und im Falldatensatz vorgemerkt werden; ehemals ausgewählte, inzwischen inaktive Staffeln bleiben lesbar. Zeitpunkt-/Feiertags- und Schwellenlogik, die automatische Auswahl und die Preisübernahme sind nicht Gegenstand dieser Version. Für weitere Zuschlagsarten gibt es eine neutrale Dimension „Weitere“. Nach Festschreibung ist die Staffelvormerkung gegen Änderungen gesperrt; neue oder geänderte Zuschläge sind als begründete Positions- und Vertragsnachträge zu erfassen, soweit der bestehende Vertragsstatus dies zulässt.

## Akzeptanzkriterien

1. Migration 3700 ergänzt nullable Bestandsfelder; alte Fälle, Positionen, Rechnungen und Termine bleiben unverändert.
2. Variantenbaum und stabile Kennungen stehen bereit; Zwischenstufen und offene Fachzuordnungen sind erkennbar.
3. Ja/nein/offen für Trauerfeier, Abholzeit, Größe und Gewicht sowie die bewusst gewählte aktive Staffel je Merkmal werden beim Fall gespeichert und plausibilisiert.
4. Trauerfeier „ja“ erzeugt höchstens eine Abstimmungsaufgabe; vorhandene Trauerfeiertermine verhindern einen doppelten Auftrag.
5. Administratoren pflegen Variantenregeln und Zuschlagsstaffeln mit referenzierten, aktiven Katalogleistungen; nicht geklärte Varianten sind gegen Aktivierung gesperrt.
6. Interne Variantenhinweise berücksichtigen die vorhandenen Fallpositionen; Aufschieben und Standardpositionen bleiben sichtbar beziehungsweise nachvollziehbar.
7. Weder Varianten- noch Zuschlagsregeln verändern automatisch Vertrag, Positionen, Rechnungen oder Dokumentvorlagen. Zusätzliche Leistungen nach Festschreibung gehen durch den Vertragsnachtrag.
8. Release-ZIP enthält ausschließlich Nextcloud-Laufzeitdateien; keine Tickets, Testdaten oder Ergebnisse.

## Test und Risiko

Lokale Node-Laufzeit- und Strukturtests sowie Frontend-Build prüfen die UI-Regeln. PHP-Lint, echte Datenbankmigration und fachliche Browserabnahme sind auf der Nextcloud-34-Testinstanz durchzuführen. Besonders zu prüfen: bestehender Fall ohne Variantencode, neue Fallanlage, Mehrfachspeichern von Trauerfeier „ja“, existierender Trauerfeiertermin, aktive/deaktivierte Regel, Auftragsnachtrag nach Festschreibung, Zahl der Positionen und unveränderte Rechnung. Vor Produktivfreigabe sind Preis-/Steuerbehandlung und Staffellogik mit Buchhaltung und Fachbereich zu bestätigen.

## Nachtrag: Variantenbaum selbst pflegen

Die erste Umsetzung bot nur die Pflege der Leistungsregeln zu fest hinterlegten Varianten. Das genügte der geforderten fachlichen Pflege der Unterauswahlen nicht. Administratoren können nun unter **Customizing → Wertelisten → Bestattungsvarianten** Ober- und Untervarianten neu anlegen sowie Bezeichnung, Elternvariante, Zuordnung (Feuer-/Urnen-, Erd- oder fachlich offen) und Reihenfolge pflegen. Bestehende technische Schlüssel bleiben stabil; neue Schlüssel sind eindeutig und bestehen aus Großbuchstaben, Ziffern und Unterstrichen. Ein Elternwechsel auf sich selbst oder einen eigenen Nachfahren wird abgewiesen. Eine fachlich offene Variante erlaubt weiterhin keine aktiven Leistungsregeln. Löschen ist nur ohne Untervarianten, Regel- und Fallbezüge möglich. Die Fallauswahl verwendet den gespeicherten Baum unmittelbar nach dem Neuladen.

Der bereits vorhandene Befehl `bestatter:development-reset` dient der Bereinigung von Testbetriebsdaten. Er erstellt vor Ausführung eine JSON-Sicherung und benötigt `--execute` plus Bestätigungscode. **Wertelisten, Artikel, Regeln und weitere Konfiguration bleiben dabei erhalten.** Er ist daher weder Voraussetzung noch Ersatz für die Variantenpflege; hier wurde kein Reset ausgeführt.

Testsystem 0.62.1: Nutzer meldete erfolgreiches Upgrade mit `maintenance: false` und `needsDbUpgrade: false`. Nach Browser-Neuladen sind 18 Varianten samt Eltern- und Zuordnungsfeldern sichtbar; die Neuzeile erscheint, und die Wahl von `SEA_NORTH_FAMILY` belegt `CREMATION`. Ein unveränderter vorhandener Eintrag ließ sich mit Erfolgsmeldung speichern. Es wurde keine Testvariante und kein Fall angelegt oder verändert. Die serverseitige Neuanlage und die Auswahl einer neu gespeicherten Untervariante im Fall bleiben damit für eine vollständige End-to-End-Abnahme offen.
