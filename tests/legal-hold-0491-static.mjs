import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')

assert(read('appinfo/info.xml').includes('<version>0.65.0</version>'))
const migration = read('lib/Migration/Version1800Date20260906000000.php')
for (const field of ['retention_hold_reason', 'retention_hold_responsible', 'retention_hold_set_at', 'retention_hold_review_at']) assert(migration.includes(field), `Legal-Hold-Feld fehlt: ${field}`)

const service = read('lib/Service/RetentionPolicyService.php')
for (const marker of ['LEGAL_HOLD_SET', 'LEGAL_HOLD_RELEASED', 'Überprüfungsdatum', 'verantwortliche Person', 'holdState']) assert(service.includes(marker), `Legal-Hold-Prüfung fehlt: ${marker}`)

const controller = read('lib/Controller/OperationsApiController.php')
assert(controller.includes('requireBestatterAdmin()'), 'Legal Hold muss administrativ geschützt bleiben')
assert(controller.includes("$this->team->assignee($responsibleUid)"), 'Verantwortung muss auf ein Bestatter-Mitglied geprüft werden')

const frontend = read('src/main.js') + read('src/modules/documents.js')
for (const marker of ['Grund des Legal Holds', 'Verantwortliche Person', 'Überprüfung am', 'Den Fall vor Löschung und Anonymisierung schützen.', 'Grund für das Aufheben']) assert(frontend.includes(marker), `Legal-Hold-Oberfläche fehlt: ${marker}`)
assert(fs.existsSync(path.join(root, 'docs/LEGAL-HOLD.md')))
console.log('0.49.1 Legal Hold, Adminschutz, Audit und Tooltips statisch geprüft.')
