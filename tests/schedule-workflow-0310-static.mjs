import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { readApiControllers } from './helpers/controller-source.mjs'

const root = process.argv[2] || '.'
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const workflows = JSON.parse(read('resources/workflow-templates.json')).workflows
const templates = JSON.parse(read('resources/document-templates.json')).templates
const service = read('lib/Service/WorkflowService.php')
const groupware = read('lib/Service/GroupwareService.php')
const controller = readApiControllers(root)
const documentService = read('lib/Service/DocumentService.php')

const trigger = workflows.find((item) => item.key === 'TRAUERFEIER_VORBEREITEN')
assert.equal(trigger?.triggerType, 'SCHEDULE')
assert.equal(trigger?.triggerEvent, 'CONFIRMED')
assert.deepEqual(trigger?.triggerConfig.schedulePresetKeys, ['TRAUERFEIER_1', 'TRAUERFEIER_2'])
assert.equal(trigger?.actions.length, 3)
assert.deepEqual(trigger?.actions.map((item) => item.dueOffsetDays), [-3, -2, -1])

const bundle = workflows.find((item) => item.key === 'KONDOLENZLISTEN_ERSTELLEN')?.actions[0]
assert.equal(bundle?.type, 'DOCUMENT_BUNDLE')
assert.deepEqual(bundle?.templateKeys, ['KONDOLENZLISTE_DECKBLATT', 'KONDOLENZLISTE_LISTE'])
assert.ok(templates.some((item) => item.key === 'KONDOLENZLISTE_DECKBLATT'))
assert.ok(templates.some((item) => item.key === 'KONDOLENZLISTE_LISTE'))

assert.match(service, /handleScheduleEvent/)
assert.match(service, /sourceScheduleId/)
assert.match(service, /claimExecution/)
assert.match(groupware, /X-BESTATTER-SCHEDULE-PRESET-KEY/)
assert.match(controller, /handlePendingScheduleEvents/)
assert.match(documentService, /\{\{funeral_event\.date\}\}/)
assert.match(documentService, /weder im konfigurierten Vorlagenordner noch im App-Paket/)
assert.ok(fs.existsSync(path.join(root, 'lib/Migration/Version1800Date20260906000000.php')))
assert.ok(fs.existsSync(path.join(root, 'docs/PROZESS-TRAUERFEIER-KONDOLENZLISTEN.md')))

console.log('schedule-workflow-0310-static: OK')
