import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const workflows = fs.readFileSync(path.join(root, 'lib/Service/WorkflowService.php'), 'utf8')
const documents = fs.readFileSync(path.join(root, 'lib/Service/DocumentService.php'), 'utf8')
const recordService = fs.readFileSync(path.join(root, 'lib/Service/RecordService.php'), 'utf8')
const records = fs.readFileSync(path.join(root, 'src/modules/records.js'), 'utf8')
const finances = fs.readFileSync(path.join(root, 'src/modules/administration.js'), 'utf8')

assert.ok(workflows.includes('alignBundledWorkflowCompatibility'))
assert.match(workflows, /KONDOLENZLISTEN_ERSTELLEN[\s\S]*required_status[\s\S]*ANY/)
assert.ok(workflows.includes('scheduleSelectionRequired'))
assert.ok(workflows.includes("$input['scheduleId']"))
assert.ok(workflows.includes('für welche Trauerfeier'))
assert.ok(records.includes('Trauerfeier für die Dokumente'))
assert.ok(records.includes('workflow-schedule-select'))
assert.ok(records.includes('input.scheduleId'))
assert.ok(documents.includes('documentContextKey'))
assert.ok(documents.includes('Trauerfeier '))
assert.ok(recordService.includes("$payload['documentContextKey']"))
assert.ok(finances.includes('erst „ABRECHENBAR“'))

console.log('0.38.3 Aufgaben-Workflow mit Mehrfach-Trauerfeier und Abrechnungshinweis geprüft.')
