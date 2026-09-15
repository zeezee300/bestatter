# OCR-gestützte Auftragsschnellerfassung

## Ziel und fachliche Grenze

Ein vor Ort handschriftlich oder digital ausgefüllter Auftragserfassungsbogen kann in der Schnellerfassung als PDF hochgeladen werden. Das Original wird zuerst in Nextcloud unter `Bestatter/.Erfassungseingang` gesichert. Paperless-ngx ist ein optionaler OCR-Dienst; die Bestatter-App bleibt für Interpretation, Prüfung, Fallanlage und Ablage führend.

Kein erkannter Wert wird automatisch in einen Sterbefall geschrieben. Niedrige oder nicht eindeutig beschriftete Werte bleiben leer. Der Anwender vergleicht die Vorschläge mit dem Scan, wählt sie aus, korrigiert sie und bestätigt erst danach die Fallanlage.

## Prozess

1. In **Schnellerfassung** „PDF-Erfassungsbogen auswählen“ wählen.
2. Die App prüft Dateityp, PDF-Signatur, Größe bis 25 MB und Dublette per SHA-256.
3. Das Original wird im persönlichen geschützten Eingang abgelegt.
4. Bei aktiver Paperless-Anbindung wird eine separate externe Dokumentreferenz vom Typ `CASE_CAPTURE_FORM` angelegt und asynchron verarbeitet.
5. Paperless liefert den OCR-Volltext. Der Bestatter-Parser sucht ausschließlich nach bekannten Label-Ankern wie „Vorname“, „Sterbedatum“, „Friedhof“ oder „Auftraggeber“.
6. In der Splitansicht steht der Originalscan links und das prüfbare Formular rechts. Jeder Vorschlag zeigt Feld, Wert, Quelltext und Erkennungssicherheit.
7. Erst „Geprüften Fall anlegen“ speichert bestätigte Werte. Danach wird die PDF nach `01 Stammdaten` verschoben und als Dokumentart „Auftrag“ registriert.
8. OCR-Volltext und temporäre Vorschläge werden aus dem Importvorgang gelöscht. Prüfsumme, technische Referenzen und Audit-Ereignis bleiben erhalten.

## Paperless-Konfiguration

In Paperless sollten mindestens folgende Stammdaten angelegt werden:

- Dokumenttyp: `Auftragserfassungsbogen`
- Tag: `Bestatter-Auftragserfassung`
- optional ein Workflow, der bei diesem Dokumenttyp die gewünschte OCR-Sprache und weitere rein dokumentarische Metadaten setzt

Die numerischen IDs werden in **Administration → Paperless** getrennt von den IDs für Eingangsrechnungen eingetragen. So gelangen Erfassungsbögen niemals in den Rechnungs-Postkorb. Ohne eigene Dokumenttyp-ID startet die App aus Sicherheitsgründen keine OCR-Übergabe und verwendet den manuellen Fallback. Der technische Benutzer benötigt Rechte zum Anlegen und Anzeigen von Dokumenten sowie zum Lesen des Task-Status.

## Verhalten ohne Paperless oder bei Störung

Der Upload wird nicht verworfen. Die PDF bleibt im Nextcloud-Eingang, die normale Text-, Sprach- und Formularerfassung bleibt vollständig nutzbar. Die Oberfläche zeigt „manuelle Prüfung erforderlich“ und bietet nach Wiederherstellung der Verbindung „OCR erneut versuchen“ an. Die persistente Warteschlange führt fehlgeschlagene technische Versuche kontrolliert erneut aus.

## Gestaltung des Erfassungsbogens

Für zuverlässige Ergebnisse sollte der Bogen eine stabile Formularversion und eindeutige, links stehende Labels verwenden. Empfohlen sind Druckschrift, ausreichend große Eingabeflächen, getrennte Felder für Datum/PLZ/Ort und klar markierte Auswahlkästchen. Freie Handschrift kann mit der üblichen Paperless-/Tesseract-OCR deutlich schlechter erkannt werden; sie wird nicht erraten.

## Datenschutz und Nachvollziehbarkeit

- Zugriff nur für angemeldete Mitglieder der konfigurierten Bestatter-Gruppe.
- Importe sind bis zur Fallzuordnung nur für den hochladenden Benutzer abrufbar.
- Keine Zugangsdaten oder Dokumentinhalte im Audit-Log.
- Keine automatische Übernahme ohne Bestätigung.
- Temporärer OCR-Text wird nach Abschluss minimiert.
- Fall-Export, Backup, Löschrichtlinie und Entwicklungsreset umfassen die Importreferenzen.
