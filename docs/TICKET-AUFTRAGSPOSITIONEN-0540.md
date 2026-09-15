# Realisierungsticket Auftragspositionserfassung 0.54.0

## Ziel

Die in der Browser-Abnahme von Fall 2026-0006 erkannten Speicher- und Summenfehler werden behoben. Darauf aufbauend erhält die schlanke Positionsübersicht eine durchgängige Matchcode-Suche, zeilengebundene Details, unmittelbare Plausibilisierung und ausgewählte Komfortfunktionen, ohne die Standarderfassung zu überladen.

## Phase 0 Stabilisierung

- Bestehende Positionen werden beim Speichern eindeutig über ihre Positions-ID zugeordnet und nicht anschließend als vermeintlich entfernt storniert.
- Ausschließlich tatsächlich entfernte Positionen erhalten den Status `STORNIERT`.
- Stornierte Positionen fließen nicht in beauftragte, erbrachte, fakturierte oder offene Summen ein.
- Autospeicherungen werden in Eingabereihenfolge verarbeitet; nur die jüngste Antwort darf den sichtbaren Entwurf aktualisieren.
- Katalogposition, Freitextposition, Mengenänderung, Entfernen und erneutes Laden werden als Persistenzfolge getestet.

## Phase 1 Schnellerfassung

- Ab dem ersten eingegebenen Zeichen werden Artikelnummer, Kurztext, Langtext, Kategorie und Artikelgruppe durchsucht.
- Sortierung: exakte Artikelnummer, Nummer beginnt mit Eingabe, Wortanfang im Text, sonstiger Teiltreffer.
- Die ersten acht Treffer werden direkt unter dem Feld angezeigt; weitere Treffer werden gezählt. Fundstellen und Trefferart werden sichtbar gekennzeichnet.
- Pfeil-aufwärts/-abwärts, Enter, Escape und Tab funktionieren vollständig, ohne den Eingabefokus unnötig zu wechseln.
- Die Tabelle zeigt Einzelpreis netto, Gesamt netto, MwSt.-Satz und Gesamt brutto je Position.
- Details werden unmittelbar unter der zugehörigen Zeile angezeigt. Eine getrennt gespeicherte Positionsbemerkung wird über die Migration `Version3300Date20260911000000` ergänzt.
- Fehlende Pflichtwerte einer Freitextposition werden direkt an der Zeile angezeigt. Eine Katalogposition mit Preis 0 erhält einen nicht blockierenden Prüfhinweis.

## Phase 2 Übersicht und Komfort

- Ein clientseitiger Filter durchsucht ausschließlich die sichtbaren Auftragspositionen nach Materialnummer und Bezeichnung; Auftragssummen bleiben ungefiltert.
- Brutto-Zwischensummen nach den Positionstypen EL, FK und DP werden live angezeigt.
- Eine einzelne Position kann dupliziert werden. Die Kopie wird direkt darunter eingeordnet und alle Positionen werden konsistent in 10er-Schritten nummeriert.

## Abnahme

- Katalog- und Freitextposition bleiben nach gemeinsamem Speichern und Neuladen sichtbar.
- Änderungen an Menge, Einheit, Preis, MwSt.-Satz, Positionstyp und Bemerkung bleiben nach Neuladen erhalten.
- Entfernen einer Position verändert keine andere Position; Summen enthalten keine stornierten Zeilen.
- Suche, Tastaturbedienung, Filter, Detailanzeige und Zwischensummen funktionieren im Entwurf. Die Funktion „Duplizieren“ ist auf Nutzerwunsch vorläufig vollständig ausgeblendet.
- Festgeschriebene oder fakturierte Positionen bleiben gemäß bestehender Schutzlogik gesperrt.
- Der Auftrag des Testfalls bleibt nach der Abnahme im Status `Entwurf`.
