import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => readFileSync(path.join(root, file), 'utf8')
const ui = read('src/modules/customizing.js')
const css = read('css/style.css')
const controller = read('lib/Controller/RecordApiController.php')
const service = read('lib/Service/WorkflowService.php')
const routes = read('appinfo/routes.php')
const info = read('appinfo/info.xml')

for (const marker of ['bp-workflow-workspace', 'bp-workflow-maintenance', 'data-workflow-order-row', 'data-workflow-action-row', 'Inhalt und Logik', 'workflow-admin-id']) assert.match(ui, new RegExp(marker))
assert.match(css, /bp-checklist-header[^\n]*minmax\((?:2[89]\d|3\d\d)px/)
assert.match(css, /checklist-description[^\n]*min-height:\s*(?:9[6-9]|1\d\d)px/)
assert.match(css, /bp-workflow-action-fields/)
assert.match(routes, /recordApi#reorderWorkflows/)
assert.match(controller, /function reorderWorkflows/)
assert.match(service, /function reorder\(array \$workflowIds\)/)
assert.match(service, /Für die Sortierung müssen alle vorhandenen Workflows genau einmal/)
assert.match(service, /beginTransaction\(\)/)
assert.match(info, /<version>0\.(?:4[1-9]|[5-9][0-9])\.[0-9]+<\/version>/)

console.log('0.41.2 Checklisten-Layout und tabellarische Workflow-Pflege statisch geprüft.')
