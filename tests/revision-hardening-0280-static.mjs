import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { readApiControllers } from './helpers/controller-source.mjs'

const root = process.argv[2] || '.'
const read = (file) => readFileSync(join(root, file), 'utf8')

const middleware = read('lib/Middleware/BestatterAccessMiddleware.php')
for (const marker of ['\\InvalidArgumentException', '\\JsonException', 'BESTATTER_VALIDATION_ERROR', 'Http::STATUS_BAD_REQUEST']) {
	assert.ok(middleware.includes(marker), `zentrale API-Fehlerbehandlung enthält ${marker}`)
}
const controller = readApiControllers(root)
assert.ok(!controller.includes('configurationResponse('), 'punktueller Konfigurations-Wrapper ist entfernt')
assert.ok(!controller.includes('commercialResponse('), 'punktueller Kommerz-Wrapper ist entfernt')
assert.ok(!controller.includes('catch (\\InvalidArgumentException'), 'Controller kopiert keinen lokalen Validierungs-catch')

const workflow = read('lib/Service/WorkflowService.php')
for (const marker of ['private AuditService $audit', 'claimExecution(', 'execution_key', "'RUNNING'", "'COMPLETED'", "'FAILED'", 'beginTransaction()', 'rollBack()', "'WORKFLOW'"]) {
	assert.ok(workflow.includes(marker), `Workflow-Härtung enthält ${marker}`)
}
const workflowMigration = read('lib/Migration/Version1800Date20260906000000.php')
for (const marker of ['execution_key', 'run_status', 'updated_at', 'addUniqueIndex']) assert.ok(workflowMigration.includes(marker), `Workflow-Migration enthält ${marker}`)

const documents = read('lib/Service/DocumentService.php')
assert.ok(!documents.includes("$targetFolder->lock(ILockingProvider::LOCK_EXCLUSIVE)"), 'Der Zielordner darf nicht exklusiv gesperrt werden, weil Nextcloud dadurch die eigene Dateierzeugung verweigert.')
assert.ok(documents.includes('$targetFolder->newFile($docxName)'), 'Dokument wird über die sperrende Nextcloud-Dateisystem-API erzeugt')
assert.ok(!documents.includes('function nextVersion('), 'tote, ungesicherte Versionssuche ist entfernt')

const groupware = read('lib/Service/GroupwareService.php')
for (const marker of ['CalendarObjectDeletedEvent', 'importCalendarObject(', 'markRemoteDeleted(']) {
	const combined = groupware + read('lib/Listener/CalendarObjectChangedListener.php')
	assert.ok(combined.includes(marker), `Ereignisbasierter Remote-Löschabgleich enthält ${marker}`)
}
assert.ok(!groupware.includes('markMissingFromCalendar('), 'Kalendersuche leitet keine Löschungen mehr aus fehlenden Treffern ab')

assert.match(read('appinfo/info.xml'), /<version>0\.(?:2[89]|[3-9]\d)\./, 'Release enthält mindestens die Härtung aus 0.28.0')
console.log('0.28.0 API, workflow, document locking and remote deletion contracts passed')
