import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }

const ui = ['src/main.js', 'src/modules/cases.js', 'src/modules/records.js', 'src/modules/commercial.js', 'src/modules/documents.js', 'src/modules/administration.js', 'src/modules/customizing.js'].map(read).join('\n')
for (const marker of ['+ Checkliste', '+ Dokumentenvorlage', '+ Abmeldevorgang', 'Mitarbeiter aus der Gruppe Bestatter', 'preview-document-template', 'preview-dereg-template']) {
	assert(ui.includes(marker), `Korrektur in der Pflegeoberfläche fehlt: ${marker}`)
}
assert(ui.includes("contactSelect.value = contacts.some"), 'Empfängerauswahl der Abmeldung wird beim Aktualisieren nicht erhalten.')
assert(ui.includes("values.status !== 'ENTWURF' && missingFields.length"), 'Unvollständige Abmeldungsentwürfe können nicht gespeichert werden.')
assert(ui.includes("querySelectorAll('tr[data-case-id]')"), 'Fallzeilen-Handler kollidiert weiterhin mit dem Aufgaben-Neuanlagebutton.')
assert(ui.includes("death_time_mode: 'Angabe des Todeszeitpunkts'"), 'Auswahl exakt/Zeitraum fehlt.')
for (const marker of ['scheduleFilters', 'scheduleSort', 'documentFilters', 'documentSort', 'data-record-filter', 'data-record-sort']) {
	assert(ui.includes(marker), `Filter-/Sortierfunktion für Termine und Dokumente fehlt: ${marker}`)
}
for (const label of ['Alle Dokumentarten', 'Termin aufsteigend', 'Erstellung absteigend']) {
	assert(ui.includes(label), `Auswahl für Termine/Dokumente fehlt: ${label}`)
}

const schema = JSON.parse(read('resources/case-field-schema.json'))
assert(schema.some((field) => field.key === 'death_time_mode'), 'death_time_mode fehlt im Fallfeldkatalog.')

const configuration = read('lib/Service/ConfigurationService.php')
assert(configuration.includes('darf keine Leerzeichen enthalten'), 'Verständliche Niederlassungsschlüssel-Validierung fehlt.')
assert(configuration.includes("gehört nicht zur Nextcloud-Gruppe Bestatter"), 'Serverseitige Prüfung der Mitarbeiterzuordnung fehlt.')

const groupware = read('lib/Service/GroupwareService.php')
const synchronize = groupware.slice(groupware.indexOf('public function synchronize'), groupware.indexOf('public function importCalendarObject'))
assert(!synchronize.includes('markMissingFromCalendar('), 'Unvollständige Kalendersuchen dürfen nicht als Löschbeweis verwendet werden.')
assert(groupware.includes('CalendarObjectDeletedEvent') && groupware.includes('markRemoteDeleted('), 'Der ereignisbasierte Abgleich extern gelöschter Kalenderobjekte fehlt.')
assert(synchronize.includes('catch (\\Throwable $error)') && synchronize.includes("$errors[]"), 'Fehlerhafte Kalenderläufe werden nicht als Synchronisationsfehler ausgewiesen.')
assert(groupware.includes('createCalendarObject((int)$calendar->getKey(), $uri, $ics)'), 'VTODO-Neuanlage verwendet nicht das Nextcloud-DAV-Backend.')
assert(!groupware.includes('createFromStringMinimal('), 'VTODO-Neuanlage verwendet weiterhin den VEVENT-orientierten Kalender-Wrapper.')
assert(groupware.includes('supported-calendar-component-set'), 'Kalenderauswahl prüft die unterstützten VEVENT-/VTODO-Komponenten nicht.')
assert(groupware.includes("supportsCalendarComponent($calendar, $requiredComponent)"), 'Schreibpfad filtert nicht nach dem benötigten Komponententyp.')

const documents = read('lib/Service/DocumentService.php')
assert(documents.includes("$result['pdfWarning']"), 'DOCX wird bei einem PDF-Fehler nicht unabhängig behandelt.')
assert(documents.includes("if (in_array($status, ['FINAL', 'UNTERSCHRIEBEN'], true)) { $file->delete(); throw $error; }"), 'Fehlgeschlagene finale Ausgabe lässt ein bearbeitbares DOCX zurück.')

const opList = read('docs/OP-LISTE.md')
assert(opList.includes('Visueller DOCX-Vorlageneditor und Feldkatalog'), 'Vorlageneditor fehlt in der OP-Liste.')
assert(opList.includes('PDF-Konvertierung mit EuroOffice'), 'EuroOffice-PDF-Integration fehlt in der OP-Liste.')

console.log('Festgestellte Testpunkte: Korrekturvertrag OK')
