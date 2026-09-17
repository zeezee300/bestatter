import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = async (file) => readFile(path.join(root, file), 'utf8')
const [routes, controller, files, records, documents, ui, rows, main] = await Promise.all([
	'appinfo/routes.php', 'lib/Controller/DocumentApiController.php', 'lib/Service/CaseFileService.php',
	'lib/Service/RecordService.php', 'lib/Service/DocumentService.php', 'src/modules/documents.js',
	'src/modules/records.js', 'src/main.js',
].map(read))

assert.match(routes, /documentApi#uploadPaperContract/)
assert.match(routes, /documentApi#confirmPaperContract/)
assert.match(controller, /public function uploadPaperContract/)
assert.match(controller, /public function confirmPaperContract/)
assert.match(files, /scanSha256/)
assert.match(files, /sourcePdfSha256/)
assert.match(files, /SIGNATURE_REVIEW/)
assert.match(records, /Papierverträge können nur über die Unterschriftenprüfung bestätigt werden/)
assert.match(records, /public function confirmPaperContract/)
assert.match(documents, /Final - R%02d/)
assert.doesNotMatch(ui, /<option value="UNTERSCHRIEBEN"/)
assert.match(ui, /showPaperContractUpload/)
assert.match(ui, /showPaperContractReview/)
assert.match(rows, /review-paper-contract/)
assert.match(main, /paper-contract-external/)
assert.match(await read('appinfo/info.xml'), /<version>0\.65\.0<\/version>/)
console.log('OP-056: Zwei Papierwege, Sichtprüfung, Statussperre und PDF-Revision statisch geprüft.')
