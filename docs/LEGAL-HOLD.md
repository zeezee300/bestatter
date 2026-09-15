# Legal Hold (Löschsperre)

Ein Legal Hold schützt einen Fall unabhängig von der allgemeinen Aufbewahrungsfrist vor Löschung und Anonymisierung. Er ist für laufende Gerichts-, Versicherungs-, Prüfungs- oder Beweissicherungsverfahren vorgesehen.

## Berechtigung und Erfassung

Nur Benutzer mit der konfigurierten Bestatter-Administratorrolle können einen Legal Hold setzen oder aufheben. Beim Setzen sind ein nachvollziehbarer Grund und eine verantwortliche Person aus der Bestatter-Gruppe verpflichtend. Das Überprüfungsdatum ist optional und dient der Wiedervorlage; es hebt die Sperre nicht automatisch auf. Der verbindliche Setzzeitpunkt wird serverseitig erzeugt.

Beim Aufheben ist ebenfalls eine Begründung erforderlich. Dadurch ist erkennbar, warum die besondere Löschsperre nicht mehr benötigt wird.

## Protokollierung

Setzen und Aufheben werden im verketteten, unveränderlichen Audit-Protokoll des Falls dokumentiert. Erfasst werden insbesondere:

- handelnder Administrator und Zeitpunkt des Audit-Ereignisses,
- Grund der Aktion,
- verantwortliche Person,
- Setz- und Überprüfungsdatum,
- vollständiger Vorher-/Nachher-Zustand.

Ein fälliges Überprüfungsdatum ist eine organisatorische Wiedervorlage und keine automatische Freigabe. Vor dem Aufheben ist zu prüfen, ob der Sicherungszweck tatsächlich entfallen ist.
