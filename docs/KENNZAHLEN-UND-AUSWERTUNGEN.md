# Kennzahlen und Auswertungen – Benutzerhandbuch

## Zweck und Geltungsbereich

Die Kennzahlen unter **Administration → Kennzahlen & Auswertungen** dienen der operativen Steuerung bis einschließlich Rechnungsstellung. Zeitraum und Niederlassung gelten für Bildschirmansicht und CSV-Export gleichermaßen. Zahlungseingänge, Mahnwesen, Storno- und Gutschriftprozesse sind ausdrücklich nicht Bestandteil dieser Auswertung.

Die Auswertung zeigt zusätzlich eine Plausibilitätsprüfung. Ein Wert mit dem Status **OK** ist rechnerisch plausibel. **Hinweis** bedeutet, dass Daten fehlen oder eine fachliche Prüfung erforderlich ist. **Fehler** bedeutet, dass mindestens ein widersprüchlicher oder rechnerisch ungültiger Datensatz gefunden wurde. Ein Hinweis macht eine Zahl nicht automatisch falsch, aber sie darf nicht ohne Prüfung als belastbare Management-Kennzahl verwendet werden.

## Kennzahlen und Berechnung

Jede Prozentkennzahl hat einen nachvollziehbaren **Zähler** und **Nenner**. Beide sind in der folgenden Berechnung ausdrücklich benannt; bei einem Nenner von null bleibt der Wert leer.

| Anzeige | Berechnung | Interpretation |
|---|---|---|
| Offene Fälle | Fälle im Filterzeitraum minus Fälle mit Status `ABGESCHLOSSEN` oder `STORNIERT` | Der Zeitraum bezieht sich auf das Anlagedatum; dies ist keine Liste aller derzeit offenen Fälle außerhalb des Zeitraums. |
| Offene Aufgaben | Aufgaben ohne Abschlussstatus `ERLEDIGT`, `DONE`, `ABGESCHLOSSEN` oder `FINAL` | Aufgaben ohne gültiges Datum werden nicht als überfällig gezählt. |
| Aufgaben-Erledigungsquote | abgeschlossene Aufgaben ÷ alle Aufgaben × 100 | Nur sinnvoll, wenn der Filterzeitraum und die Aufgabenmenge fachlich zusammenpassen. |
| Überfällige Aufgaben | offene Aufgaben mit gültigem Fälligkeitszeitpunkt vor dem Prüfzeitpunkt | Abgesagte oder erledigte Aufgaben werden nicht gezählt. |
| Überfälligkeitsquote | überfällige offene Aufgaben ÷ offene Aufgaben × 100 | Aufgaben ohne Datum fehlen im Nenner nicht; die Datenqualität weist sie separat aus. |
| Termine | alle Termine im Filterzeitraum | Erledigte und abgesagte Termine bleiben für die Prozesshistorie sichtbar. |
| Absagequote | abgesagte Termine ÷ alle Termine × 100 | Ein Termin gilt als abgesagt bei `ABGESAGT` oder `CANCELLED`. |
| Planbelastung | Summe der Termin-Dauern und vorhandenen Aufgaben-Planminuten | Dies ist eine Dispositionsgröße, keine Personalauslastung. |
| Istzeit-Abdeckung | Aktivitäten mit `actualMinutes > 0` ÷ alle Aktivitäten × 100 | Zeigt, wie vollständig Istzeiten dokumentiert sind. |
| Ist-/Plan-Verhältnis | Summe Istminuten ÷ Summe Planminuten × 100 | Nur bei vorhandenen Plan- und Istzeiten sinnvoll; extreme Abweichungen werden als Hinweis markiert. |
| Erbrachte Positionen | Leistungspositionen mit erbrachter Menge größer 0 | Anzahl, nicht Umsatz. |
| Leistungserbringungsgrad | Summe erbrachte Mengen ÷ Summe beauftragte Mengen × 100 | Mengen werden in der fachlichen Einheit ausgewertet. Ein Wert über 100 % ist eine zu prüfende Abweichung, nicht automatisch ein Fehler. |
| Fakturierungsgrad | Summe fakturierte Mengen ÷ Summe erbrachte Mengen × 100 | Zeigt den Abrechnungsfortschritt; Zahlungseingänge sind nicht enthalten. |
| Rechnungsvolumen | Summe Netto, Umsatzsteuer und Brutto aller nicht stornierten Rechnungen | Entwürfe und freigegebene/versendete Rechnungen werden getrennt ausweisbar; stornierte Rechnungen werden nicht summiert. |
| Freigegebenes Rechnungsvolumen | Bruttosumme der Status `FREIGEGEBEN`, `VERSENDET`, `TEILBEZAHLT`, `BEZAHLT` | Dies ist der belastbarere Wert für bereits ausgegebene Rechnungen. |
| Durchschnittliche Rechnung | nicht storniertes Bruttovolumen ÷ Anzahl nicht stornierter Rechnungen | Bei null Rechnungen bleibt die Kennzahl leer. |
| Mitarbeiter-Planbelastung | Planminuten je erkannter Zuständigkeit | „Ohne Zuordnung“ wird separat ausgewiesen. |

## Kritische Plausibilitätsprüfungen

Die Anwendung prüft bei jedem Laden der Auswertung:

- Zuständigkeit, Planzeit und Istzeit werden als Abdeckung ausgewiesen. Fehlende Kapazitäts-, Arbeitszeit- und Abwesenheitsdaten lösen bewusst einen Hinweis aus.
- Negative Mengen, negative Zeitwerte, nicht parsebare Aktivitätsdaten und Rechnungsbeträge werden markiert.
- Fakturierte Mengen dürfen die erbrachten Mengen nicht überschreiten. Erbrachte Mengen über der Beauftragung sind fachlich möglich, werden aber als Abweichung angezeigt.
- Für jede nicht stornierte Rechnung muss die Rechnungsarithmetik `Netto + Umsatzsteuer = Brutto` bis auf einen Cent Rundung stimmen.
- Plan-Ist-Abweichungen über 300 % werden als auffällig gemeldet. Dieser Grenzwert ist eine Prüfschwelle, keine fachliche Bewertung der Leistung.
- Division durch null wird vermieden; bei fehlender Datengrundlage erscheint kein künstlicher Prozentwert.

## Was aktuell bewusst nicht behauptet wird

Eine echte Mitarbeiterauslastung benötigt eine Kapazitätsbasis: Dienstplan, Arbeitszeitmodell, Abwesenheiten und gegebenenfalls Qualifikationen. Diese Informationen sind im aktuellen Stand noch nicht integriert. Die App berechnet deshalb keine Auslastungsquote und bezeichnet die angezeigte Zeit nur als **Planbelastung** beziehungsweise **Istzeit-Abdeckung**. Der Ausbau ist als OP-031 „Dienstpläne und betriebliche Disposition“ zurückgestellt.

Ebenso sind Durchlaufzeiten von Fällen nur dann belastbar, wenn ein fachlich definierter Abschlusszeitpunkt vorliegt. Ein `updated_at`-Wert ist dafür kein Ersatz und wird nicht als Abschlussdatum missbraucht.

## Anwenderprüfung

Vor einer Entscheidung sollte der Anwender immer den Filter, die Datenabdeckung und die Plausibilitätsmeldungen kontrollieren. Bei einem Hinweis ist zuerst der zugrunde liegende CSV-Export zu öffnen. Für Monatsvergleiche sollten jeweils dieselben Statusdefinitionen, Niederlassungsfilter und Ausschlüsse verwendet werden. Die Kennzahlen sind Führungs- und Prüfinstrumente; sie ersetzen keine fachliche Rechnungsprüfung, Dienstplanung oder datenschutzrechtliche Bewertung.
