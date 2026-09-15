# Workflows und Automatisierungen

Workflows verbinden eine fachliche Ausgangsaufgabe mit kontrollierten, serverseitig geprüften Folgeaktionen. Die Regeln werden unter **Customizing → Workflows** gepflegt; frei formulierter ausführbarer Code wird nicht gespeichert.

Zusätzlich können Workflows auf die Bestätigung bestimmter Terminvorlagen reagieren. Der vollständige Referenzprozess für Trauerfeiern und das zweiteilige Kondolenzlisten-Paket ist in [PROZESS-TRAUERFEIER-KONDOLENZLISTEN.md](PROZESS-TRAUERFEIER-KONDOLENZLISTEN.md) beschrieben.

## Unterstützte Aktionstypen

- Folgeaufgabe: erzeugt eine synchronisierte Nextcloud-Aufgabe.
- Wiedervorlage: erzeugt eine als Wiedervorlage gekennzeichnete Nextcloud-Aufgabe mit Fälligkeit.
- Termin / Frist: erzeugt einen synchronisierten Nextcloud-Kalendereintrag.
- Dokument: erzeugt ein DOCX aus einer freigegebenen Vorlagenkennung.
- Dokumentpaket: erzeugt mehrere zusammengehörige DOCX-Ausgaben aus demselben Fall- und Terminkontext.
- E-Mail-Entwurf: legt einen bearbeitbaren Dokumentdatensatz mit Empfänger, Betreff und Text an; es erfolgt kein Versand.
- Kontaktaktivität: protokolliert Kontakt, Kanal, Zeitpunkt und Notiz im Fall-Verlauf.
- Fallstatus: setzt einen kontrollierten Zielstatus. Zusätzlich kann jede Aktion einen automatischen Aufgaben- und Fallstatus danach festlegen.

## Workflow „Standesamtliche Anzeige vorbereiten“

Der Startworkflow passt zur gleichnamigen Aufgabe der Grundcheckliste. Er bietet die optimierte Sterbefallanzeige, einen E-Mail-Entwurf, Kontaktprotokoll, Wiedervorlage und den Abschluss der Ausgangsaufgabe an. Die Sterbefallanzeige wird in `03 Behörden` gespeichert. Die bearbeitbare Vorlage wird bei der ersten Erzeugung nach `Bestatter/Vorlagen` kopiert beziehungsweise auf Vorlagenversion 3 aktualisiert.

Nicht wiederholbare Aktionen werden nach Ausführung gesperrt. Jede Ausführung wird mit Benutzer, Zeitpunkt und Ergebnis protokolliert. Freigabe/Vier-Augen-Prüfung und automatischer E-Mail-Versand sind ausdrücklich nicht Bestandteil dieser Version; siehe `docs/OP-LISTE.md`.
