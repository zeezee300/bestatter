import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { readApiControllers } from './helpers/controller-source.mjs'

const root = process.argv[2] || '.'
const read = (file) => readFileSync(join(root, file), 'utf8')

assert.ok(/<version>0\.(?:3[0-9]|[4-9][0-9])\./.test(read('appinfo/info.xml')), 'App-Version ist mindestens 0.30.0')
assert.ok(/VERSION = '0\.(?:3[0-9]|[4-9][0-9])\./.test(read('lib/AppInfo/Application.php')), 'Laufzeitversion ist mindestens 0.30.0')
assert.ok(read('appinfo/routes.php').includes("'/api/system-check'"), 'Systemprüfungsroute ist registriert')

const controller = readApiControllers(root)
assert.ok(controller.includes('function systemCheck()'), 'Systemprüfungsendpunkt fehlt')
assert.match(controller, /function systemCheck\(\)[\s\S]{0,180}requireBestatterAdmin\(\)/, 'Systemprüfung ist nicht auf Bestatter-Administratoren beschränkt')

const service = read('lib/Service/StabilizationService.php')
for (const marker of ['Rollen und Zugriff', 'Nextcloud-Synchronisation', 'Datenintegrität', 'Bearbeitungsnachweis', 'Workflow-Stabilität', 'orphanCheck()', 'missingActorCheck()', 'workflowCheck()']) {
	assert.ok(service.includes(marker), `Systemprüfung enthält ${marker}`)
}
assert.doesNotMatch(service, /->(?:insert|update|delete)\(/, 'Systemprüfung muss rein lesend bleiben')

const frontend = read('src/modules/administration.js')
for (const marker of ['Systemprüfung', 'Abnahme- und Systemprüfung', 'run-system-check', 'loadSystemCheck', 'verändert keine Fall-, Aufgaben- oder Termindaten']) {
	assert.ok(frontend.includes(marker), `Administrationsoberfläche enthält ${marker}`)
}
assert.ok(read('src/main.js').includes('bindSystemCheck()'), 'Systemprüfung wird im Hauptmodul gebunden')

const middleware = read('lib/Middleware/BestatterAccessMiddleware.php')
assert.ok(middleware.includes('requireBestatterMember()'), 'App-weite Mitgliedschaftsprüfung bleibt aktiv')
assert.ok(middleware.includes('BESTATTER_VALIDATION_ERROR'), 'Zentrale 400-Fehlerbehandlung bleibt aktiv')
console.log('0.30.0 acceptance and stabilization contracts passed')
