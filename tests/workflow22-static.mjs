import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }

const ui = ['src/main.js', 'src/modules/cases.js', 'src/modules/records.js', 'src/modules/commercial.js', 'src/modules/documents.js', 'src/modules/administration.js', 'src/modules/customizing.js'].map(read).join('\n')
const documents = read('lib/Service/DocumentService.php')
const records = read('lib/Service/RecordService.php')
const deregistration = read('lib/Service/DeregistrationService.php')
const groupware = read('lib/Service/GroupwareService.php')
const articles = read('lib/Service/ArticleService.php')
const migration = read('lib/Migration/Version1800Date20260906000000.php')
const css = read('css/style.css')
const opList = read('docs/OP-LISTE.md')

for (const marker of ['documentOutputRow', 'preview-nextcloud-file', 'open-nextcloud-file', 'Dokumente durchsuchen', 'Dokumentenart', 'Vorlagen auswählen', 'Erstellte Ausgaben']) {
	assert(ui.includes(marker), `Dokumentenübersicht unvollständig: ${marker}`)
}
assert(documents.includes("'version' => $version") && documents.includes("$version = 1"), 'Kompatibilitätsversion muss immer 1 sein.')
assert(!documents.includes('nextVersion($targetFolder'), 'Dokumentausgabe verwendet weiterhin mehrere Versionen.')
assert(records.includes('isImmutableDocumentStatus') && records.includes("'VERSENDET'"), 'Finale/versendete Dokumente sind nicht geschützt.')
assert(documents.includes('Die Rechnung kann erst nach Pflege der Niederlassungs- und Bankdaten'), 'Rechnung prüft Absender- und Bankdaten nicht.')
for (const marker of ['bank_iban', 'branch_address', 'invoice_qr_payload']) assert(documents.includes(marker), `Rechnungsfeld fehlt: ${marker}`)

for (const marker of ['availableAttachments', 'attachmentWarning', 'Sterbeurkunde', 'previewMode', 'templateName']) {
	assert(deregistration.includes(marker), `Abmeldungsfunktion fehlt: ${marker}`)
}
assert(deregistration.includes("['ENTWURF', 'VORBEREITET']"), 'Vorbereitete Abmeldungen bleiben nicht bearbeitbar.')

for (const marker of ['assigneeUids', 'ATTENDEE', 'writeScheduleCopies', 'deleteCalendarObject']) {
	assert(groupware.includes(marker), `Mehrpersonen-Termin fehlt: ${marker}`)
}
assert(ui.includes('predefined-task-titles') && ui.includes('<datalist') && ui.includes('eigenen Freitext'), 'Aufgaben-Vorbelegung mit Freitext fehlt.')
assert(ui.includes('scrollTop') && ui.includes('requestAnimationFrame'), 'Scrollposition der Leistungsauswahl wird nicht erhalten.')

assert(migration.includes('exclusive_group'), 'Migration für exklusive Artikelgruppen fehlt.')
for (const marker of ['exclusiveGroup', "$row['delete']", 'gelöscht oder deaktiviert', 'CSV aktualisieren']) {
	assert(articles.includes(marker) || ui.includes(marker), `Katalogpflege unvollständig: ${marker}`)
}
assert(articles.includes('In der exklusiven Artikelgruppe'), 'Paket-/Artikelkonflikte werden nicht serverseitig geprüft.')
assert(opList.includes('Eigenständiges Modul für Leistungen und Artikel'), 'Zielarchitektur für separates Leistungsmodul fehlt.')
assert(css.includes('var(--color-primary-element') && css.includes('var(--color-main-background'), 'Nextcloud-Themevariablen werden nicht verwendet.')

console.log('Prozesskorrekturen 0.22.0: statischer Vertrag OK')
