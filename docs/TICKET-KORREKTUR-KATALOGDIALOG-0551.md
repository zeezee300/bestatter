# Korrekturticket: Leistungskatalogdialog und Scrollposition

## Fehlerbild

Im Dialog „Artikelliste / Leistungskatalog durchsuchen“ sprang die Ergebnisanzeige nach jeder Selektion an den Anfang. Ursache war der notwendige Neuaufbau der Ansicht nach dem automatischen Speichern, bei dem der neu erzeugte Dialog keine eigene Scrollposition übernahm. Zusätzlich nutzte der Dialog auf Desktop-Bildschirmen nur einen begrenzten Teil der verfügbaren Fläche.

## Umsetzung

- Vor einer positionsbezogenen Autospeicherung werden Scrollpositionen des Dialogs und des Artikelergebnisbereichs im Ansichtsstatus gesichert.
- Nach dem Neuaufbau werden die Positionen in zwei Animationszyklen wiederhergestellt, nachdem der Browser das neue Layout berechnet hat.
- Filter-, Such-, Seitengrößen- und Seitenwechsel setzen die Scrollposition absichtlich auf 0, weil eine neue Ergebnismenge angezeigt wird.
- Der Desktopdialog verwendet maximal 1.680 Pixel Breite sowie die verfügbare Fensterhöhe abzüglich eines 16-Pixel-Rands je Seite.
- Der Artikelergebnisbereich und die Auswahlzusammenfassung scrollen unabhängig. Unterhalb von 900 Pixeln bleibt ein einspaltiges, touchgeeignetes Layout erhalten.

## Abnahmekriterien

1. Auswahl oder Abwahl eines Artikels verändert die sichtbare Position im Katalog nicht.
2. Änderungen von Menge oder Preis führen nach dem Autospeichern nicht zum Anfang der Liste.
3. Suche, Filter und Seitenwechsel beginnen bei der neuen Ergebnismenge oben.
4. Auf einem Desktop-Bildschirm nutzt der Dialog nahezu die gesamte Breite und Höhe, ohne über den Viewport hinauszuragen.
5. Auswahlzusammenfassung und Artikelliste bleiben unabhängig erreichbar und scrollbar.
6. Auf schmalen Bildschirmen wird ein einspaltiges Layout ohne horizontal abgeschnittene Inhalte angezeigt.
