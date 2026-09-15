# Bestatter

Bestatter ist eine Nextcloud-App für die digitale Fachakte und Vorgangssteuerung in Bestattungsunternehmen. Sie unterstützt unter anderem Fallverwaltung, Auftrags- und Positionserfassung, Dokumentvorlagen, kaufmännische Abläufe, Aufgaben sowie fachliches Customizing.

Die Anwendung wurde mit Unterstützung durch AI entwickelt. Architektur, Implementierung, fachliche Entscheidungen und Freigaben liegen bei den menschlichen Projektverantwortlichen.

## Voraussetzungen

- Nextcloud 34
- PHP 8.2 bis 8.5 mit GD
- Node.js 22 und pnpm für die Frontend-Entwicklung
- Composer für PHP-Abhängigkeiten und Qualitätssicherung

## Entwicklung

```powershell
composer install
pnpm install --frozen-lockfile
pnpm run build
composer check
pnpm test
```

## Release-Paket

Das Installationspaket enthält ausschließlich die für die App erforderlichen Laufzeitdateien. Entwicklungsunterlagen, Tickets, Tests und Testergebnisse werden nicht aufgenommen.

```powershell
pwsh -File tools/build-release.ps1
```

`-SkipBuild` darf nur verwendet werden, wenn `js/main.js` bereits aus dem aktuellen Quellstand erzeugt wurde.

## Installation

Das Release-Archiv in das Nextcloud-Verzeichnis `apps/bestatter` entpacken und anschließend das Nextcloud-Upgrade ausführen:

```text
occ app:enable bestatter
occ upgrade
occ app:list
```

## Lizenz

Das Projekt steht unter der `AGPL-3.0-or-later`. Der Rechteinhaber ist Bestatterplattform; der vollständige Lizenztext steht in [LICENSE](LICENSE).
