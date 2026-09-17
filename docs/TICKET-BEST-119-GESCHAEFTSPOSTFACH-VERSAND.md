# BEST-119 – Kontrollierter PDF-Versand über zentrales Geschäfts-Postfach

Status: Stufe 1 lokal implementiert; Versandkonfiguration in 0.64.2 an Nextcloud angeglichen. Testsystem-Abnahme und fachliche Freigabe ausstehend. Priorität: Muss vor produktiver Nutzung des App-internen Versands.

## Ziel und Abgrenzung

Ein Bestatter-Administrator wählt in einem Fall eine bereits registrierte finale PDF-Ausgabe, prüft die Datei und sendet sie nach manueller Eingabe von genau einer Empfängeradresse, Betreff und Nachrichtentext ausdrücklich über den Nextcloud-Mailer. Das Absenderpostfach wird zentral konfiguriert. Die Aktion „Mit Mailprogramm teilen“ bleibt unverändert und erzeugt keinen Versandnachweis. Workflow-E-Mail-Entwürfe werden nicht automatisch versendet.

Seit 0.64.2 nutzt die Funktion ausschließlich die zentral in Nextcloud eingerichtete Mailtransport- und Absenderkonfiguration; separate Bestatter-App-Schalter und SMTP-Zugangsdaten entfallen. Als `From` wird die Nextcloud-Systemadresse verwendet. Eine gültige E-Mail-Adresse der angemeldeten Person wird als `Reply-To` gesetzt; fehlt sie, bleibt `Reply-To` leer und Antworten gehen an die Systemadresse. Die App legt keine Nachricht automatisch im IMAP-Ordner „Gesendet“ ab; Versandversuche werden in ihrer eigenen Outbox dokumentiert. Die Mailbox-Verantwortlichen prüfen Antworten und Rückläufer organisatorisch.

## Technische Stufe 1

- Datenbankmigration 3900 legt `bestatter_mail_outbox` an. Die eindeutige Versandkennung wird vor dem SMTP-Aufruf gespeichert. Ein erneuter Aufruf mit derselben Kennung sendet nie erneut; abweichende Inhalte unter derselben Kennung werden abgewiesen.
- Zugelassen sind ausschließlich fallbezogene Dokumentdatensätze mit Status `FINAL`, `UNTERSCHRIEBEN` oder `VERSENDET` und tatsächlicher PDF-Datei im Fallordner. Der Server prüft PDF-Kopf, maximal 15 MB und SHA-256-Abgleich mit der unmittelbar vorher geöffneten Vorschau.
- Eine einzelne Empfängeradresse und ein manueller Nachrichtentext sind Pflicht. Empfänger, Fall und Anlage werden ausdrücklich bestätigt. Ein weiterer Versand desselben Dokuments an dieselbe Adresse erfordert eine Begründung; bei unklarem SMTP-Ergebnis wird nicht automatisch wiederholt.
- Die Outbox speichert Empfänger, Absender, Betreff, Nachrichtentext, Dateiname/-hash, Benutzer, Zeit und Status. `ACCEPTED` bezeichnet nur die Annahme durch den Mailserver; `UNCERTAIN` kann sowohl eine erfolgreiche als auch eine fehlgeschlagene Übermittlung bedeuten und erfordert manuelle Prüfung. Für diese Stufe gibt es keinen Zustellnachweis.
- Seit 0.64.1 dürfen Bestatter-Administratoren sowie die per Nextcloud-UID zugeordnete Person senden. Die Backend-Prüfung ist unabhängig von deaktivierten Buttons in der Oberfläche. Für fremd erzeugte Dokumente bleibt die gemeinsame Fallablage (OP-040) Voraussetzung.
- Migration 4000 ergänzt die verwendete Antwortadresse im Versandverlauf. Vor Versand zeigt die Oberfläche Absender und, sofern vorhanden, Antwortadresse an.

## Konfiguration im Testsystem

1. In Nextcloud unter Administration → Grundeinstellungen Mailtransport und Systemabsender auf das zentrale Geschäfts-Postfach einstellen. Die dortige Test-E-Mail an eine interne Adresse senden und den tatsächlichen Absender kontrollieren.
2. In den Nextcloud-Profilen der sendeberechtigten Mitarbeitenden eine gültige E-Mail-Adresse hinterlegen, wenn Antworten direkt an sie gehen sollen. Ohne gültige Profiladresse bleibt die zentrale Systemadresse die Antwortadresse.
3. 0.64.2 vollständig ausliefern und `occ upgrade` ausführen. `business_mail_from` und `business_mail_enabled` werden nicht mehr gelesen; alte Werte können nach Sicherung der Konfiguration entfernt werden. Die Funktion wird bei gültiger zentraler Mailkonfiguration angeboten. Vor produktiver Nutzung die unten genannte interne Abnahme durchführen.

## Abnahme

- Neuinstallation und Upgrade der Migrationen 3900 und 4000 auf der verwendeten MariaDB/Nextcloud-34-Umgebung; insbesondere Schema, Indexnamen und `reply_to` prüfen.
- Administrator, zugeordneter und nicht zugeordneter Bestatter-Benutzer: nur die ersten beiden dürfen senden. Fremder Fall und nicht finales Dokument werden serverseitig abgewiesen. Mit getrennten Benutzerkonten zusätzlich Dokumentzugriff prüfen (OP-040).
- Empfänger-/Anlagenvorschau, reale interne Testnachricht mit korrektem Absender, optionaler Mitarbeiter-Antwortadresse, Text und PDF, Eintrag in der Outbox. Auch den Fall ohne gültige Profiladresse testen. „Angenommen“ nie als „zugestellt“ anzeigen.
- Doppelklick/identische Versandkennung, abweichender Inhalt bei gleicher Kennung, begründeter Zweitversand, SMTP-Ausfall und Timeout. Bei `UNCERTAIN` Postfach prüfen, bevor ein neuer Versuch angelegt wird.
- Der Mailserver muss die konfigurierte Absenderadresse wirklich akzeptieren. Rückläufer, Antwortpostfach, Datenschutz und Aufbewahrung von Nachrichten fachlich freigeben. Erst dann Versand mit echten Falldaten aktivieren.

Nicht Bestandteil der Stufe 1: persönliche Nextcloud-Mail-Konten, automatischer Workflow-Versand, IMAP-„Gesendet“-Synchronisation, bestätigte Zustellung, Massenversand, CC/BCC und elektronische Signatur.
