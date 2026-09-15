# Qualitätssicherung der Standardvorlagen

Der Vorlagen-Abnahmelauf erzeugt alle im App-Paket enthaltenen DOCX- und Textvorlagen mit dem realistischen Musterfall `2026-0042 Helene Müller`. Er dient als reproduzierbarer technischer und visueller Test vor einer Freigabe.

Geprüft werden:

- alle verwendeten Platzhalter gegen den vollständigen Fallfeldkatalog und die zusätzlich berechneten Dokumentfelder,
- vollständige Entfernung nicht belegter Platzhalter,
- deutsche Datumsdarstellung und Umlaute,
- Seitenzahl, Seitengröße, leere oder auffällige Seiten sowie DOCX-/PDF-Rendergleichheit,
- Einbettung eines echten EPC-Zahlungs-QR in der Rechnung,
- Erzeugung einer PDF-Fassung zu jeder DOCX-Standardvorlage.

Die Ergebnisse werden als JSON und CSV unter `output/template-qa-0.43.0` abgelegt. Dort liegen zusätzlich die materialisierten DOCX- und PDF-Dateien sowie die nur zur visuellen Prüfung benötigten Seitenbilder.

## Schutz eigener Vorlagen

Eine im konfigurierten Nextcloud-Vorlagenordner vorhandene Datei besitzt immer Vorrang. Die App kopiert eine Paketvorlage nur, wenn dort noch keine Datei mit diesem Namen existiert. Aktualisierungen, auch der Rechnungsvorlage, verändern keine vorhandene kundeneigene Datei. Neue Paketfassungen müssen bewusst unter einem neuen Namen bereitgestellt oder administrativ übernommen werden.
