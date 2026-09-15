import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const read = (path) => readFileSync(path, 'utf8')
const routes = read('appinfo/routes.php')
const operations = read('lib/Controller/OperationsApiController.php')
const backup = read('lib/Service/BackupService.php')
const main = read('src/main.js')
const ui = read('src/modules/ui.js')
const administration = read('src/modules/administration.js')
const customizing = read('src/modules/customizing.js')

assert(routes.includes("operationsApi#createBackup"))
assert(routes.includes("operationsApi#verifyBackup"))
assert(routes.includes("operationsApi#downloadBackup"))
assert(routes.includes("operationsApi#deleteBackup"))
assert(operations.includes('requireBestatterAdmin()'))
assert(backup.includes('MANAGED_FILE_PATTERN'))
assert(backup.includes('inspectManaged'))
assert(main.includes("ui.download(url, filename, OC.requestToken"))
assert(ui.includes("headers: { requesttoken: requestToken }"))
assert(ui.includes("window.showSaveFilePicker"))
assert(main.includes("await download(`${apiBase}/cases/${state.currentCase.id}/export.json`"))
assert(administration.includes('Backup & Restore'))
assert(administration.includes('await download(`${ctx.apiBase}/reports/'))
assert(customizing.includes("download-template-fields"))
assert(!customizing.includes('href="${apiBase}/template-fields.csv"'))

console.log('0.51.1 sichere Downloads und Backup-/Restore-Cockpit statisch geprüft.')
