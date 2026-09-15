import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { readApiControllers } from './helpers/controller-source.mjs'

const root = process.argv[2] || '.'
const read = (file) => readFileSync(join(root, file), 'utf8')

const controller = readApiControllers(root)
assert.ok(controller.includes('use OCP\\AppFramework\\Http\\Attribute\\NoCSRFRequired;'), 'CSRF-Attribut ist importiert')
assert.match(controller, /#\[NoCSRFRequired\][\s\S]{0,120}function templateFieldsCsv/, 'read-only CSV-Download ist CSRF-frei')
assert.match(controller, /function templateFieldsCsv[\s\S]{0,180}requireBestatterAdmin/, 'CSV-Download bleibt Bestatter-Administratoren vorbehalten')

const main = read('src/main.js')
for (const marker of ["params.get('taskId')", 'Number(record.id) === taskId', 'verlinkte Aufgabe wurde in diesem Fall nicht gefunden']) {
	assert.ok(main.includes(marker), `stabiler Deep-Link enthält ${marker}`)
}

const groupware = read('lib/Service/GroupwareService.php')
for (const marker of ['X-BESTATTER-CASE-PERSON:', 'X-BESTATTER-RECORD-TITLE:', 'casePersonName', 'visibleTitle', "str_contains($workflowUrl, 'taskId=')", 'needsCategories']) {
	assert.ok(groupware.includes(marker), `Groupware-Reparatur enthält ${marker}`)
}
assert.ok(groupware.includes("$params['taskId'] = $recordId"), 'neue Aufgabenlinks enthalten die lokale Datensatz-ID')
assert.ok(groupware.includes("$person . ' · '"), 'sichtbarer Nextcloud-Titel enthält den Personennamen vor dem Aufgabentitel')

console.log('0.27.1 CSV download, stable deep links, display context and repair contracts passed')
