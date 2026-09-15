# Aufbewahrungs- und Löschrichtlinie

Die Software liefert ein technisches Verfahren, legt aber keine rechtsverbindliche Frist fest. Verantwortlicher und Datenschutzbeauftragte müssen Zweck, Rechtsgrundlage, Fristbeginn und Ausnahmen je Datenkategorie freigeben. Die Voreinstellung beträgt zehn Jahre nach der letzten Änderung eines abgeschlossenen oder stornierten Falls und ist deaktiviert.

## Kontrollierter Ablauf

1. Richtlinie unter **Administration → Systemprüfung → Datenschutz und Betriebsverfahren** konfigurieren.
2. Bei Rechtsstreit, Prüfung oder ungeklärter Pflicht einen Legal Hold am Fall setzen.
3. Mit `occ bestatter:retention` ausschließlich die Vorschau erzeugen.
4. Vollständiges Backup samt Prüfsumme und Restore-Nachweis prüfen.
5. Liste fachlich und datenschutzrechtlich freigeben.
6. Erst dann `--execute --confirmation=BESTATTER-AUFBEWAHRUNG-ANWENDEN` verwenden.
7. Ergebnis und Nextcloud-Fallordner kontrollieren.

Der Hintergrundjob löscht niemals Daten. Die bestätigte Anonymisierung entfernt fallbezogene Detailtabellen, identifizierende Stammdaten und eindeutig zugeordnete Fallordner der konfigurierten Bestatter-Gruppen. Der Löschmodus entfernt zusätzlich den Fall. Das Ergebnis weist gelöschte und bereits fehlende Ordner einzeln aus. Externe Freigaben, Papierarchive, E-Mail-Systeme und Sicherungen sind separat nach ihrer Richtlinie zu behandeln.

Schutzmaßnahmen sind: abgeschlossener/stornierter Status, Legal Hold, individuelles Fälligkeitsdatum, reine Vorschau als Standard, eindeutiger Bestätigungscode, Datenbanktransaktion und System-Audit ohne erneute Speicherung der Personendaten.
