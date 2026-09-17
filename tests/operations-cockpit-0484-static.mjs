import assert from 'node:assert/strict'
import fs from 'node:fs'
const read = (path) => fs.readFileSync(path, 'utf8')

assert(read('appinfo/info.xml').includes('<version>0.65.0</version>'))
assert(read('lib/AppInfo/Application.php').includes("VERSION = '0.65.0'"))
const service = read('lib/Service/OperationsCockpitService.php')
for (const marker of ['bestatter_workflow_runs', 'bestatter_integration_jobs', 'bestatter_external_documents', 'retention->preview', 'maintenance_last_run', "'readOnly'=>true"]) assert(service.includes(marker), `Cockpit-Aggregat fehlt: ${marker}`)
const controller = read('lib/Controller/OperationsApiController.php')
assert(controller.includes('function cockpit()') && controller.includes('requireBestatterAdmin()'), 'Cockpit-Endpunkt ist nicht admin-geschützt')
assert(read('appinfo/routes.php').includes("'/api/operations/cockpit'"), 'Cockpit-Route fehlt')
const ui = read('src/modules/administration.js')
for (const marker of ['Betriebs-Cockpit', 'loadOperationsCockpit', 'bindOperationsCockpit', 'cockpit-open-case', 'cockpit-target']) assert(ui.includes(marker), `Cockpit-Oberfläche fehlt: ${marker}`)
const op = read('docs/OP-LISTE.md')
for (const marker of ['Stufe 3A', 'Stufe 3B', 'DATEV-EXTF', 'Dublettenschutz', 'OP-005']) assert(op.includes(marker), `DATEV-Roadmap fehlt: ${marker}`)
assert(fs.existsSync('docs/BETRIEBS-COCKPIT.md'))
console.log('0.48.4 Betriebs-Cockpit und gestufte DATEV-Roadmap statisch geprüft.')
