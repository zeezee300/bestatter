import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }

const routes = read('appinfo/routes.php')
const controller = read('lib/Controller/DocumentApiController.php')
const service = read('lib/Service/DocumentService.php')
const main = read('src/main.js')
const documents = read('src/modules/documents.js')
const commercial = read('src/modules/commercial.js')
const css = read('css/style.css')

assert(routes.includes("documentApi#previewOrderDocument") && routes.includes('preview.pdf'), 'Binärer PDF-Vorschau-Endpunkt fehlt.')
assert(controller.includes('DataDownloadResponse') && controller.includes("'application/pdf'"), 'Vorschau wird nicht als PDF-Downloadantwort ausgeliefert.')
assert(service.includes('previewOrderDocumentPdf') && service.includes('.bestatter-preview-'), 'Temporärer Vorschaupfad fehlt.')
assert(service.includes('finally') && service.includes('try { $temp->delete(); }'), 'Temporäre Vorschau-DOCX wird nicht garantiert bereinigt.')
assert(!main.includes('window.print()') && !commercial.includes('id="print-order"'), 'Die Erfassungsmaske darf nicht mehr direkt gedruckt werden.')
assert(main.includes('showOrderDocumentPreview') && commercial.includes('preview-order-docs'), 'Vorschau-first-Ablauf fehlt in der Auftragserfassung.')
for (const marker of ['Nebenwirkungsfreie PDF-Vorschau', 'PDF öffnen / drucken', 'Entwurfspaket erzeugen', 'URL.revokeObjectURL']) {
	assert(documents.includes(marker), `Vorschau-Bedienung unvollständig: ${marker}`)
}
assert(service.includes('generateOrderDocumentDraftPackage') && service.includes('replaceExistingOutput = true'), 'Staging für das Entwurfspaket fehlt.')
assert(controller.includes('beginTransaction()') && controller.includes('rollBack()') && controller.includes('discardGeneratedOutput'), 'Atomare Datenbank- und Datei-Rückabwicklung fehlt.')
assert(controller.indexOf('commit()') < controller.indexOf('cleanupSupersededOrderDrafts'), 'Alte Entwürfe dürfen erst nach dem Commit bereinigt werden.')
assert(documents.includes('createButton.disabled = true'), 'Doppelklickschutz bei der Entwurfserzeugung fehlt.')
assert(css.includes('.bp-order-preview-stage iframe') && css.includes('.bp-order-preview-tabs'), 'Responsive PDF-Vorschaugestaltung fehlt.')

console.log('BEST-113 Stufe 1: nebenwirkungsfreie PDF-Vorschau und atomare Entwurfspakete statisch geprüft.')
