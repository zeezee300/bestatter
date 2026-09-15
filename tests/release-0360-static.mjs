import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const expect = (condition, message) => { if (!condition) throw new Error(message) }

expect(/<version>0[.](?:3[6-9]|[4-9]\d)[.]\d+<\/version>/.test(read('appinfo/info.xml')), 'App-Version ab 0.36.0 fehlt')
expect(/VERSION = '0[.](?:3[6-9]|[4-9]\d)[.]\d+'/.test(read('lib/AppInfo/Application.php')), 'PHP-Version ab 0.36.0 fehlt')
const migration = read('lib/Migration/Version1800Date20260906000000.php')
for (const column of ['invoice_type', 'invoice_sequence', 'prior_gross_cents']) expect(migration.includes(column), `Migrationsspalte ${column} fehlt`)
const commercial = read('lib/Service/CommercialService.php')
for (const token of ['PARTIAL', 'FINAL', 'hasActiveFinalInvoice', 'nextCaseInvoiceSequence', 'priorInvoiceGross']) expect(commercial.includes(token), `Rechnungslogik ${token} fehlt`)
const assistant = read('lib/Service/AssistantService.php')
for (const token of ['function catalog', 'FIND_CASE', 'NAVIGATE', 'navigationPreview', 'Abmeldungen vorbereiten', 'Auftrag und Leistungen öffnen']) expect(assistant.includes(token), `Assistentenfähigkeit ${token} fehlt`)
const operational = read('lib/Service/OperationalService.php')
expect(operational.includes("'nextActions' => $nextActions"), 'Geführte nächste Prozessaktionen fehlen')
const documents = JSON.parse(read('resources/document-templates.json')).templates
for (const key of ['RUNDFUNKBEITRAG_ABMELDUNG', 'VERTRAGSABMELDUNG_STANDARD', 'NACHLASSMITTEILUNG_BANK', 'ARBEITGEBER_STERBEFALLMITTEILUNG']) {
	const template = documents.find((item) => item.key === key)
	expect(template, `Dokumentvorlage ${key} fehlt`)
	expect(fs.existsSync(path.join(root, 'resources/templates', template.file)), `DOCX-Datei ${template.file} fehlt`)
}
const deregistrations = JSON.parse(read('resources/deregistration-templates.json')).templates
expect(deregistrations.some((item) => item.key === 'RUNDFUNKBEITRAG'), 'Rundfunkbeitrag-Abmeldeprozess fehlt')
expect(read('docs/OP-LISTE.md').includes('OP-019 – Provider-neutrale LLM-Erweiterung'), 'LLM-OP fehlt')
const ui = read('src/modules/administration.js') + read('src/main.js')
for (const token of ['data-invoice-type="PARTIAL"', 'data-invoice-type="FINAL"', 'create-invoice']) expect(ui.includes(token), `Rechnungs-UI ${token} fehlt`)

console.log('release-0360-static: ok')
