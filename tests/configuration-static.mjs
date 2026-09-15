import fs from 'node:fs'
import path from 'node:path'
import { readApiControllers } from './helpers/controller-source.mjs'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }

const fields = JSON.parse(read('resources/case-field-schema.json'))
const keys = fields.map((field) => field.key)
for (const key of ['birth_registry_office', 'death_time_from', 'death_time_to', 'last_residence_postal_code', 'last_residence_city', 'last_residence_country', 'cemetery_contact']) assert(keys.includes(key), `Stammdatenfeld fehlt: ${key}`)
assert(!keys.includes('cemetery'), 'Das doppelte Friedhofsfeld ist noch vorhanden.')

const migration = read('lib/Migration/Version1800Date20260906000000.php')
for (const table of ['bestatter_branches', 'bestatter_document_templates', 'bestatter_dereg_templates']) assert(migration.includes(table), `Migrationstabelle fehlt: ${table}`)
assert(migration.includes('member_uids'), 'Niederlassungszuordnung zu Benutzern fehlt.')

const routes = read('appinfo/routes.php')
for (const endpoint of ['/api/document-templates', '/api/deregistration-templates', '/api/branches']) assert(routes.includes(endpoint), `Konfigurationsroute fehlt: ${endpoint}`)
const controller = readApiControllers(root)
assert((controller.match(/requireBestatterAdmin\(\)/g) || []).length >= 15, 'Serverseitige Administratorprüfungen fehlen.')
const configurationService = read('lib/Service/ConfigurationService.php')
assert(!configurationService.includes("lastInsertId('bestatter_"), 'Konfigurationsspeicherung darf nicht von treiberspezifischer lastInsertId-Auswertung abhängen.')
assert((configurationService.match(/findByKey\(/g) || []).length >= 4, 'Gespeicherte Konfigurationen müssen über ihren eindeutigen Schlüssel zurückgelesen werden.')

const documents = JSON.parse(read('resources/document-templates.json')).templates
for (const key of ['STAMMDATENBLATT', 'STERBEFALLANZEIGE_STANDESAMT', 'RENTENSERVICE_AENDERUNGSFORMULAR']) {
	const template = documents.find((entry) => entry.key === key)
	assert(template?.file, `Dokumentvorlage ist nicht konfiguriert: ${key}`)
	assert(fs.existsSync(path.join(root, 'resources/templates', template.file)), `Vorlagendatei fehlt: ${template.file}`)
}

const deregistrations = JSON.parse(read('resources/deregistration-templates.json')).templates
assert(deregistrations.some((entry) => entry.formType === 'PENSION_SERVICE'), 'Gesondertes Rentenservice-Formular fehlt.')
const ui = ['src/main.js', 'src/modules/cases.js', 'src/modules/records.js', 'src/modules/commercial.js', 'src/modules/documents.js', 'src/modules/administration.js', 'src/modules/customizing.js'].map(read).join('\n')
for (const marker of ['case_contact', 'deregistrationPanel', 'deliveryChannels', 'isBestatterAdmin', 'memberUids']) assert(ui.includes(marker), `UI-Funktion fehlt: ${marker}`)

console.log('Konfigurationsvertrag OK')
