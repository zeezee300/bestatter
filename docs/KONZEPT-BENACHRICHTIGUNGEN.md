# Konzept: Benachrichtigungen der Bestatter-App

## Zielbild ab 0.41.1

Die Bestatter-App verwendet für fachliche Hinweise die vorhandene Nextcloud-Activity-Infrastruktur. Es gibt keine zweite persönliche Einstellungsseite und keine eigenen SMTP-Zugangsdaten. Unter **Einstellungen → Persönlich → Benachrichtigungen** erscheint für Benutzer mit einer konfigurierten Bestatter-Rolle die Gruppe **Bestatter** mit getrennten Schaltern für E-Mail und Push. Konten außerhalb der Mitglieder- und Administrationsgruppen erhalten keine Bestatter-Aktivitäten.

Die E-Mail-Häufigkeit wird über die vorhandene persönliche Nextcloud-Auswahl gesteuert:

- schnellstmöglich
- stündlich
- täglich
- wöchentlich

Diese Häufigkeit gilt benutzerbezogen für Activity-E-Mails. Push wird von Nextcloud zeitnah ausgeliefert und nicht über diese E-Mail-Häufigkeit gebündelt. Unter **Administration → Aktivität** werden E-Mail-Versand und Standardwerte für neue Konten verwaltet. Bestehende Benutzer behalten ihre persönlichen Einstellungen.

## Ereignisse

Version 0.41.0 registriert folgende auswählbare Typen:

| Persönliche Einstellung | Auslöser |
|---|---|
| Neuer Sterbefall wurde mir zugewiesen | Anlage oder Änderung der verantwortlichen Person |
| Mir zugewiesene Aufgabe wurde geändert oder erledigt | fachlich relevante Fremdänderung oder neue Zuweisung |
| Mir zugewiesener Termin wurde geändert oder gelöscht | fachlich relevante Fremdänderung, Kalenderlöschung oder neue Zuweisung |
| Dokument oder Rechnung wurde finalisiert | erstmaliger finaler Dokumentstatus bzw. Rechnungsfreigabe/-versand |
| Prozess benötigt Aufmerksamkeit | fehlgeschlagener Workflow |

Eigene Änderungen erzeugen grundsätzlich keine Selbstbenachrichtigung. Reine technische CalDAV-Metadatenänderungen lösen keine Meldung aus. Aufgabe und Termin bleiben zusätzlich normale Nextcloud-`VTODO`-/`VEVENT`-Objekte.

## Technische Umsetzung

- Fünf `ActivitySettings` erscheinen gemeinsam in der Gruppe **Bestatter**.
- Der Activity-Provider erzeugt verständliche deutsche Texte und einen absoluten Deep-Link zum Fall.
- Das bestehende Audit-Log ist der zentrale fachliche Ereigniseinstieg. Erst nach dem erfolgreichen Audit-Eintrag wird die optionale Activity veröffentlicht.
- Fehler in der Benachrichtigungsinfrastruktur werden protokolliert, dürfen aber eine bereits abgeschlossene Fachoperation nicht zurückrollen oder dem Benutzer fälschlich als fehlgeschlagen anzeigen.
- Die Activity enthält Fallnummer und Name sowie die Vorgangsbezeichnung, aber keine Gesundheits-, Bank-, Adress- oder Rechnungsbeträge.
- Das unveränderbare Audit-Log bleibt unabhängig von persönlichen Benachrichtigungsschaltern erhalten.

## Betrieb und Datenschutz

Für E-Mail muss der Nextcloud-Mailserver eingerichtet und **Administration → Aktivität → Benachrichtigungs-E-Mails** aktiviert sein. Nextcloud sollte im Hintergrundmodus `cron` laufen. Push setzt die aktivierte Notifications-Infrastruktur und ein entsprechend angemeldetes Mobil-/Desktop-Gerät voraus.

Auf Sperrbildschirmen können Push-Inhalte sichtbar sein. Deshalb bleiben die Texte datensparsam; Detaildaten werden erst nach Anmeldung über den Deep-Link geladen. Die Activity-App ist kein revisionssicheres Protokoll und darf nicht anstelle des Bestatter-Audit-Logs verwendet werden.

Während der Testphase wird keine Test- oder Serien-E-Mail automatisch ausgelöst. Die fachlichen Events können zunächst mit deaktivierter E-Mail-Spalte über Activity-Stream und Push geprüft werden.

## Noch offene Erweiterungen

- fachlich abgestimmte Warnung für gefährdete oder überfällige Fristen
- erforderliche Freigaben und Rückweisungen
- administrativ erweiterbarer Ereigniskatalog; neue Schlüssel benötigen immer einen definierten fachlichen Auslöser und können deshalb nicht allein durch einen freien Wertelisteneintrag funktionsfähig werden
- optionale `VALARM`-Erinnerungen für konkrete Aufgaben- und Terminzeitpunkte
- Abnahme mit zwei Benutzern, Browser-/Mobil-Push sowie den vier E-Mail-Häufigkeiten nach ausdrücklicher Versandfreigabe
