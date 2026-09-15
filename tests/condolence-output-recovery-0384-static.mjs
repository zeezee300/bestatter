import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const workflow = fs.readFileSync(path.join(root, 'lib/Service/WorkflowService.php'), 'utf8')
const records = fs.readFileSync(path.join(root, 'src/modules/records.js'), 'utf8')

assert.ok(workflow.includes('documentOutputsComplete'))
assert.ok(workflow.includes('documentRecordExists'))
assert.ok(workflow.includes('DOCUMENT_OUTPUTS_MISSING'))
assert.ok(workflow.includes('STALE_DOCUMENT_RUN'))
assert.ok(workflow.includes("'schedule:' . $expectedScheduleId"))
assert.match(workflow, /getUserFolder\(\$userId\)[\s\S]*getById\(\$fileId\)/)
assert.ok(workflow.includes("$action['outputMissing']"))
assert.ok(records.includes('Die Ausgaben der früheren Ausführung sind nicht vollständig vorhanden.'))
assert.ok(records.includes('Bitte die Dokumente erneut erzeugen.'))
assert.ok(records.includes('Vorhandene Ausgaben anzeigen'))

console.log('0.38.4 Wiederherstellung fehlender Kondolenzlisten-Ausgaben geprüft.')
