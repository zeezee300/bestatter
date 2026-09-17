import fs from 'node:fs'
import path from 'node:path'
import assert from 'node:assert/strict'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const records = read('src/modules/records.js')
const documents = read('src/modules/documents.js')
const actions = read('src/modules/document-actions.js')
const icons = read('src/modules/icons.js')
const main = read('src/main.js')
const css = read('css/style.css')
const routes = read('appinfo/routes.php')
const controller = read('lib/Controller/DocumentApiController.php')
const caseFiles = read('lib/Service/CaseFileService.php')
const ticket = read('docs/TICKET-BEST-116-DOKUMENTE-DRUCKEN-MAIL-VORBEREITEN.md')
const opList = read('docs/OP-LISTE.md')

for (const marker of ['print-nextcloud-pdf', 'mail-nextcloud-file', 'printablePdfId', 'application/pdf', 'data-document-title', 'data-case-number', 'data-share-url', 'data-file-name', 'data-mime-type']) assert(records.includes(marker), `Dokumentzeile unvollständig: ${marker}`)
assert(documents.includes('const sharedRow = documentOutputRow('), 'Die Fallakte muss für Dateien dieselbe Dokumentzeile verwenden.')
for (const marker of ['printNextcloudPdf', 'bp-print-frame', 'contentWindow.print()', 'Strg+P', 'documentMailto', "return 'mailto:'", 'navigator.canShare', 'navigator.share', 'new File(', 'authenticatedUrl', 'requesttoken', 'credentials: \'same-origin\'', 'nicht protokolliert']) assert(actions.includes(marker), `Dokumentaktion unvollständig: ${marker}`)
assert(routes.includes("documentApi#displayCasePdf") && routes.includes('/content.pdf') && routes.includes("documentApi#displayCaseFile") && routes.includes("/{fileId}/content'"), 'Geschützte Datei-/PDF-Anzeigeroute fehlt.')
for (const marker of ['displayCasePdf', 'displayCaseFile', 'FileDisplayResponse', "Content-Disposition', 'inline", 'X-Content-Type-Options']) assert(controller.includes(marker), `Dateiantwort unvollständig: ${marker}`)
assert(caseFiles.includes('public function file(') && caseFiles.includes('public function pdf(') && caseFiles.includes('str_starts_with($node->getPath(), $rootPrefix)') && caseFiles.includes('application/pdf'), 'Fallzuordnung oder PDF-Prüfung fehlt.')
for (const marker of ['printIcon', 'mailIcon', 'printButton', 'mailButton', 'Tabler Icons', 'MIT License']) assert(icons.includes(marker), `Aktionssymbol unvollständig: ${marker}`)
assert(main.includes("querySelectorAll('.print-nextcloud-pdf')") && main.includes("querySelectorAll('.mail-nextcloud-file')"), 'Ereignisbindung für Dokumentaktionen fehlt.')
assert(css.includes('.bp-document-action-button') && css.includes('.bp-action-icon') && css.includes('.bp-print-frame'), 'Darstellung der Dokumentaktionen fehlt.')
assert(ticket.includes('Zielversion:** 0.60.2') && ticket.includes('OP-002') && opList.includes('OP-052 – BEST-116'), 'Ticket, Abgrenzung oder OP-Eintrag fehlt.')

console.log('BEST-116: Dokumentaktionen statisch geprüft.')
