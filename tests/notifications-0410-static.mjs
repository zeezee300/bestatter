import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')

const info = read('appinfo/info.xml')
assert.match(info, /<version>0\.41\.[1-9]\d*<\/version>|<version>0\.(?:4[2-9]|[5-9]\d)\.\d+<\/version>/)
for (const setting of ['CaseAssignedSetting', 'TaskChangedSetting', 'ScheduleChangedSetting', 'DocumentFinalizedSetting', 'ProcessAttentionSetting']) {
	assert.ok(info.includes(`OCA\\Bestatter\\Activity\\Setting\\${setting}`), `Activity-Einstellung fehlt: ${setting}`)
}
assert.ok(info.includes('OCA\\Bestatter\\Activity\\Provider'))

const baseSetting = read('lib/Activity/Setting/AbstractBestatterSetting.php')
for (const marker of ['extends ActivitySettings', "return 'bestatter'", "return 'Bestatter'", 'canChangeNotification', 'isDefaultEnabledNotification']) {
	assert.ok(baseSetting.includes(marker), `Nextcloud-Push-Vertrag fehlt: ${marker}`)
}

const provider = read('lib/Activity/Provider.php')
for (const subject of ['case_assigned', 'task_assigned', 'task_completed', 'schedule_changed', 'schedule_deleted', 'document_finalized', 'invoice_finalized', 'workflow_failed']) {
	assert.ok(provider.includes(`'${subject}'`), `Activity-Text fehlt: ${subject}`)
}
assert.ok(provider.includes('linkToRouteAbsolute'))
assert.ok(provider.includes("http_build_query(['caseId' => $caseId])"))
assert.match(provider, /function parse\(\$language, IEvent \$event/)

const activity = read('lib/Service/BestatterActivityService.php')
for (const marker of [
	"'bestatter_case_assigned'", "'bestatter_task_changed'", "'bestatter_schedule_changed'",
	"'bestatter_document_finalized'", "'bestatter_process_attention'", "$recipient === $actor",
	'recordContentChanged', "unset($data['nextcloud']", 'setAffectedUser', "setObject('bestatter_case'",
]) assert.ok(activity.includes(marker), `Benachrichtigungslogik fehlt: ${marker}`)

const audit = read('lib/Service/AuditService.php')
assert.ok(audit.includes('private BestatterActivityService $activity'))
assert.ok(audit.includes('$this->activity->publishAuditEvent'))

const commercial = read('lib/Service/CommercialService.php')
assert.ok(commercial.includes('private AuditService $audit'))
assert.ok(commercial.includes('$this->audit->log'))
assert.doesNotMatch(commercial, /private function audit\(/)

const stabilization = read('lib/Service/StabilizationService.php')
for (const marker of ['notificationCheck()', "'notifications'", "'activity'", "'backgroundjobs_mode'", "'mail_smtpmode'"]) {
	assert.ok(stabilization.includes(marker), `Systemprüfung fehlt: ${marker}`)
}

console.log('0.41.0 native Nextcloud-Activity-Benachrichtigungen statisch geprüft.')
