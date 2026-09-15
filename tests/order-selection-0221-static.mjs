import fs from 'node:fs'
import path from 'node:path'
import { readApiControllers } from './helpers/controller-source.mjs'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }

const ui = ['src/main.js', 'src/modules/cases.js', 'src/modules/records.js', 'src/modules/commercial.js', 'src/modules/documents.js', 'src/modules/administration.js', 'src/modules/customizing.js'].map(read).join('\n')
const css = read('css/style.css')
const commercial = read('lib/Service/CommercialService.php')
const articles = read('lib/Service/ArticleService.php')
const controller = readApiControllers(root)
const info = read('appinfo/info.xml')

assert(/<version>0\.(?:2[2-9]|[3-9]\d)\.\d+<\/version>/.test(info), 'Mindestens Version 0.22.x wird benötigt.')
for (const marker of ['latestQuoteId', 'latestOrderId', 'lockCase', 'beginTransaction', 'UEBERNOMMEN', 'Eine zweite Übernahme ist nicht zulässig']) {
	assert(commercial.includes(marker), `Einmalige KVA-Übernahme unvollständig: ${marker}`)
}
assert(commercial.includes("from('bestatter_cases')") && commercial.includes("forUpdate"), 'Fallbezogene Datenbanksperre fehlt.')
assert(controller.includes("'BESTATTUNGSAUFTRAG', 'FINAL', true") && controller.includes('pdfWarning'), 'KVA wird nicht als finale PDF-Ausgabe festgeschrieben.')

for (const marker of ['service-group-filter', 'service-item-type-filter', 'service-selected-only', 'Paketinhalt', 'serviceConflictMessages', 'Auswahlkonflikt']) {
	assert(ui.includes(marker), `Katalogauswahl unvollständig: ${marker}`)
}
assert(articles.includes('$exclusiveGroups') && articles.includes('sourcePackageName'), 'Serverprüfung exklusiver Paketbestandteile fehlt.')
assert(ui.includes('Einmalig in Auftrag übernehmen') && ui.includes('Übernommen · nur Nachweis'), 'KVA-Aktionsstatus ist nicht eindeutig.')
assert(ui.includes('showDeregistrationTransitionForm') && ui.includes('Versandnachweis speichern (kein Versand)'), 'Versandnachweisformular fehlt.')
for (const marker of ['bp-deregistration-row', 'bp-deregistration-actions', 'bp-conflict-box', 'bp-rule-badge']) assert(css.includes(marker), `Layoutkorrektur fehlt: ${marker}`)

console.log('KVA, Katalog und Abmeldungen 0.22.1: statischer Vertrag OK')
