import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }

const migration = read('lib/Migration/Version1800Date20260906000000.php')
for (const column of ['required_fields', 'output_subfolder', 'supports_pdf', 'allowed_statuses', 'follow_up_days', 'document_template_key']) assert(migration.includes(column), `Migrationsspalte fehlt: ${column}`)

const routes = read('appinfo/routes.php')
for (const endpoint of ['/documents/{templateKey}/preview', '/documents/{templateKey}/generate', '/deregistrations/preview', '/deregistrations/{id}', '/deregistrations/{id}/transition']) assert(routes.includes(endpoint), `Phase-3/4-Route fehlt: ${endpoint}`)

const documents = JSON.parse(read('resources/document-templates.json')).templates
for (const template of documents.filter((entry) => String(entry.file || '').endsWith('.docx'))) {
	assert(Array.isArray(template.required_fields), `Dokumentpflichtfelder fehlen: ${template.key}`)
	assert(template.output_subfolder, `Dokumentablage fehlt: ${template.key}`)
	assert(template.supports_pdf === true, `PDF-Ausgabe fehlt: ${template.key}`)
}
for (const key of ['STAMMDATENBLATT', 'STERBEFALLANZEIGE_STANDESAMT', 'RENTENSERVICE_AENDERUNGSFORMULAR']) assert(documents.some((entry) => entry.key === key), `Ausführbare Vorlage fehlt: ${key}`)

const deregistrations = JSON.parse(read('resources/deregistration-templates.json')).templates
for (const template of deregistrations) {
	assert(template.requiredFields.includes('contact') && template.requiredFields.includes('delivery_channel'), `Empfängerprüfung fehlt: ${template.key}`)
	assert(template.allowedStatuses.join(',') === 'ENTWURF,VORBEREITET,VERSENDET,BESTAETIGT,ERLEDIGT', `Statusfolge fehlerhaft: ${template.key}`)
	assert(Number.isInteger(template.followUpDays) && template.followUpDays >= 0, `Wiedervorlage fehlerhaft: ${template.key}`)
}
assert(deregistrations.find((entry) => entry.key === 'STANDESAMT').documentTemplateKey === 'STERBEFALLANZEIGE_STANDESAMT', 'Standesamt-Dokumentverknüpfung fehlt.')
assert(deregistrations.find((entry) => entry.key === 'RENTENSERVICE').documentTemplateKey === 'RENTENSERVICE_AENDERUNGSFORMULAR', 'Rentenservice-Dokumentverknüpfung fehlt.')

const documentService = read('lib/Service/DocumentService.php')
for (const marker of ['createPdf', 'missingRequiredFields', 'UNTERSCHRIEBEN', 'IConversionManager', 'death_time_from', 'death_time_to']) assert(documentService.includes(marker), `Dokumentfunktion fehlt: ${marker}`)
assert(!documentService.includes('function nextVersion('), 'Die entfernte, konkurrierende Versionssuche wurde wieder eingeführt.')
const deregistrationService = read('lib/Service/DeregistrationService.php')
for (const marker of ['STATUS_FLOW', 'availableChannels', 'evidenceNote', 'createFollowUp', 'BESTAETIGT']) assert(deregistrationService.includes(marker), `Abmeldungsfunktion fehlt: ${marker}`)

const ui = ['src/main.js', 'src/modules/cases.js', 'src/modules/records.js', 'src/modules/commercial.js', 'src/modules/documents.js', 'src/modules/administration.js', 'src/modules/customizing.js'].map(read).join('\n')
for (const marker of ['documentPanel', 'showDocumentDialog', 'transition-deregistration', 'deregistrationUrl', 'open-nextcloud-file']) assert(ui.includes(marker), `UI-Funktion fehlt: ${marker}`)

console.log(`Phase 3/4 Vertrag OK: ${documents.length} Dokumentvorlagen, ${deregistrations.length} Abmeldungsarten`)
