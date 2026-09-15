# Betriebs-Cockpit

## Ziel und Abgrenzung

Das Betriebs-Cockpit führt Ausnahmefälle zusammen, die zuvor nur in getrennten Bereichen sichtbar waren. Es ist ausschließlich für konfigurierte Bestatter-Administratoren erreichbar und verändert keine Fach- oder Integrationsdaten.

Angezeigt werden fehlgeschlagene Workflow-Läufe, offene und endgültig fehlgeschlagene Paperless-Jobs, noch keinem Fall zugeordnete Paperless-Dokumente, fällige Aufbewahrungsfälle und der letzte Status des Bestatter-Wartungsjobs. Fehlertexte werden gekürzt; Zugangsdaten und technische Stacktraces werden nicht ausgegeben.

## Bedienung

Unter **Administration → Betriebs-Cockpit** zeigt die Kopfampel den zusammengefassten Zustand:

- **Betrieb unauffällig:** keine bekannten Ausnahmen und Wartungsjob aktuell;
- **Hinweise vorhanden:** offene Verarbeitung, Zuordnungsbedarf, fällige Aufbewahrung oder überfällige Wartung;
- **Handlungsbedarf:** mindestens ein fehlgeschlagener Workflow- oder Paperless-Lauf.

Die Kennzahlen führen in den zuständigen Arbeitsbereich. Fallbezogene Zeilen öffnen unmittelbar die Fallakte. Die Aufbewahrungsliste ist nur eine Vorschau; Löschung oder Anonymisierung bleibt dem bestätigten OCC-Verfahren vorbehalten.

## Abnahme

1. Als Bestatter-Administrator Cockpit öffnen und Aktualisierung ausführen.
2. Als normales Bestatter-Mitglied und als Außenstehender den API-Aufruf prüfen: jeweils keine administrativen Cockpitdaten.
3. Einen kontrolliert fehlgeschlagenen Integrationsjob beziehungsweise Test-Workflow prüfen und den Absprung nachvollziehen.
4. Sicherstellen, dass Aktualisierung weder Datensätze noch Jobstatus verändert.
5. Wartungsjob ausführen und aktualisierten Zeitpunkt sowie Status kontrollieren.

## Betriebshinweis

Das Cockpit ist eine Arbeitsübersicht, keine automatische Fehlerbehebung und kein Ersatz für Monitoring, Logüberwachung, Backup oder Restore-Tests.
