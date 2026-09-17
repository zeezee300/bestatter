import assert from 'node:assert/strict'
import {readFileSync} from 'node:fs'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const service = read('lib/Service/HelpAssistantService.php')
const controller = read('lib/Controller/HelpApiController.php')
const routes = read('appinfo/routes.php')
const doc = read('resources/help/BEDIENUNG.md')
const middleware = read('lib/Middleware/BestatterAccessMiddleware.php')

for (const method of ['hasProviders()', 'getAvailableTaskTypeIds()', 'TextToTextChat::ID', 'scheduleTask($task)', 'getTask($taskId)', 'getUserId()', 'getCustomId()', 'getTaskTypeId()', 'STATUS_SUCCESSFUL', 'STATUS_FAILED']) assert(service.includes(method), method)
assert(service.includes("'history' => []"), 'Chat-Aufgabentyp braucht die History-Eingabe')
assert(service.includes('resources/help/BEDIENUNG.md') && !service.includes('/docs/'), 'Keine internen Tickets/OP-Listen als Promptquelle')
assert(service.includes('if ($sources === []) return'), 'Ohne Fund darf kein Task ausgelöst werden')
assert(!/CaseService|InvoiceService|WorkflowService|RecordService|->(update|insert|save|createCase|executeIntent)\(/.test(service), 'Hilfe-Service muss strikt ohne Fachschreibzugriffe auskommen')
assert(controller.includes('extends ApiController') && middleware.includes('requireBestatterMember()'))
for (const route of ['/api/assistant/help/availability', '/api/assistant/help/status/{taskId}', '/api/assistant/help']) assert(routes.includes(route))
for (const section of ['Neuen Sterbefall anlegen', 'Rechnung stornieren', 'Fallstatus In Bearbeitung']) assert(doc.includes(section))
assert(read('src/main.js').includes('state.help.available') && read('src/main.js').includes('bindHelp()'))
console.log('Hilfe-Assistent: Provider-Gate, Quellenbeschränkung, Eigentümerprüfung und Schreibfreiheit OK')
