import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(import.meta.dirname, '..')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')

const records = read('src/modules/records.js')
const documents = read('lib/Service/DocumentService.php')
const workflows = read('lib/Service/WorkflowService.php')

assert.match(records, /type="button" class="bp-secondary execute-workflow"/)
assert.match(records, /event\.preventDefault\(\)/)
assert.match(records, /event\.stopPropagation\(\)/)
assert.match(records, /executeWorkflowUrl\(item\.id,/)
assert.doesNotMatch(records, /const saved = await persist\(\)\s*\n\s*const result = await api\(executeWorkflowUrl/)
assert.match(documents, /safeOutputTitle/)
assert.match(documents, /discardGeneratedOutput/)
assert.match(workflows, /beginTransaction\(\)[\s\S]*saveGeneratedDocument[\s\S]*commit\(\)/)
assert.match(workflows, /foreach \(\$files as \$file\) \$this->documents->discardGeneratedOutput\(\$file\)/)
assert.match(workflows, /records->saveDocument/)
assert.match(read('appinfo/info.xml'), /<version>0\.(?:31\.(?:[4-9]|[1-9][0-9])|(?:3[2-9]|[4-9]\d)\.\d+)<\/version>/)

console.log('0.31.4 transactional document workflow checks passed.')
