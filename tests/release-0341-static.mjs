import fs from 'node:fs'
import path from 'node:path'

const root = process.argv[2] || '.'
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }

const assistant = read('lib/Service/AssistantService.php')
const admin = read('src/modules/administration.js')
const commercial = read('src/modules/commercial.js')
const main = read('src/main.js')
const styles = read('css/style.css')
const template = read('templates/main.php')
const stabilization = read('lib/Service/StabilizationService.php')

for (const marker of ['PREPARE_DOCUMENT_OUTPUT', 'GENERATE_DOCUMENT_TEMPLATE', 'prepareDocumentOutput', 'documentTemplateChoice', 'documentsForTemplate']) assert(assistant.includes(marker), `Allgemeiner Dokumentassistent fehlt: ${marker}`)
assert(assistant.includes("$key === 'RECHNUNG'"), 'Rechnungen müssen weiterhin über den geschützten Finanzprozess laufen')
for (const marker of ['Technische Details anzeigen', 'Einordnung:', 'bp-system-action', 'Version ${esc(ctx.appVersion']) assert(admin.includes(marker), `Systemprüfung/Version fehlt: ${marker}`)
for (const marker of ['details', 'action', 'Dokumentvorlagen öffnen', 'Niederlassungen öffnen']) assert(stabilization.includes(marker), `Systemprüfungsdetails fehlen: ${marker}`)
for (const marker of ['order_mode_choice', 'Kostenvoranschlag', 'verbindliche Beauftragung', 'logicalGroupOrder', 'bp-service-workspace', 'Aktuelle Auswahl']) assert(commercial.includes(marker), `Auftragsauswahl fehlt: ${marker}`)
assert(main.includes('[name="order_mode_choice"]'), 'Eindeutige KVA-/Auftragswahl ist nicht gebunden')
for (const marker of ['bp-mode-choice', 'bp-selection-summary', '#capture-record.recording', 'bp-version-badge']) assert(styles.includes(marker), `UX-Stil fehlt: ${marker}`)
assert(template.includes('data-app-version'), 'Laufzeitversion wird nicht an die Oberfläche übergeben')
assert(/<version>0\.(?:34\.[1-9]|3[5-9]\.\d+|[4-9]\d\.\d+)<\/version>/.test(read('appinfo/info.xml')), 'Releaseversion ab 0.34.1 fehlt')

console.log('0.34.1 generic documents, diagnostics and commercial UX contracts passed')
