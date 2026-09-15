# Frontend-Architektur

## Verzeichnisstruktur

- `src/main.js`: Bootstrap, gemeinsamer Zustand, Navigation, übergreifende Event-Bindings
- `src/modules/cases.js`: Fallliste, Dashboard, Stammdaten und Checklisten im Fall
- `src/modules/records.js`: Aufgaben, Termine, Dokumentlisten und Datensatzdialoge
- `src/modules/commercial.js`: Auftrag/KVA, Leistungsauswahl und kaufmännische Hilfsfunktionen
- `src/modules/documents.js`: Falldokumente, Vorschauen, Abmeldungen und Fallakte
- `src/modules/administration.js`: Artikel, Niederlassungen, Rechnungs- und Finanzadministration
- `src/modules/customizing.js`: Checklisten-, Workflow-, Vorlagen- und Abmeldungs-Customizing
- `src/modules/assistant.js`: geführte Schnellerfassung, Audioaufnahme, Transkriptionsstatus, Vorschlagsprüfung und bestätigte Intent-Ausführung
- `js/main.js`: ausschließlich erzeugtes, an Nextcloud auszulieferndes IIFE-Bundle

Die Module erhalten einen gemeinsamen App-Kontext. Damit bleibt der Zustand während des ersten Refactorings zentral, während fachliche Rendering- und Binding-Funktionen getrennt gepflegt werden. Eine spätere Umstellung auf einen Store oder Vue-Komponenten kann dadurch fachbereichsweise erfolgen.

## Build

Vite bündelt alle ES-Module in ein klassisches IIFE. Das ist erforderlich, weil `PageController` die Datei weiterhin über `Util::addScript('bestatter', 'main')` lädt. Im produktiven Bundle verbleiben keine `import`-Anweisungen und keine separat nachzuladenden Chunks.

Vor jedem Release:

```sh
pnpm install --frozen-lockfile
pnpm run build
pnpm run test
```

Das erzeugte `js/main.js` wird eingecheckt beziehungsweise in das Releasepaket aufgenommen. Quelländerungen direkt in `js/main.js` gehen beim nächsten Build verloren und sind deshalb unzulässig.
