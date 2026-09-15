import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { readApiControllers } from './helpers/controller-source.mjs'

const root = process.argv[2] || '.'
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const records = read('src/modules/records.js')
const workflow = read('lib/Service/WorkflowService.php')
const groupware = read('lib/Service/GroupwareService.php')
const documents = read('lib/Service/DocumentService.php')
const controller = readApiControllers(root)

assert.match(records, /externalContactIds/)
assert.match(records, /state\.records\.contact/)
assert.match(records, /PDF öffnen \/ drucken/)
assert.match(records, /showWorkflowDocumentResult/)
assert.match(workflow, /SUPERSEDED/)
assert.match(workflow, /restartExecutionClaim/)
assert.match(workflow, /invalidateScheduleDependents/)
assert.match(controller, /reactivatedActions/)
assert.match(groupware, /X-BESTATTER-EXTERNAL-CONTACT-IDS/)
assert.match(documents, /condolenceTemplateFallback/)

console.log('schedule-workflow-0311-static: OK')
