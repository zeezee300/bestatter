import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(import.meta.dirname, '..')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')

const records = read('src/modules/records.js')
const documents = read('lib/Service/DocumentService.php')
const workflows = read('lib/Service/WorkflowService.php')
const groupware = read('lib/Service/GroupwareService.php')

assert.match(records, /name="externalParticipants" list="external-contact-suggestions"/)
assert.doesNotMatch(records, /name="externalContactIds" multiple/)
assert.match(records, /Mehrere Beteiligte mit Semikolon trennen/)
assert.match(records, /showWorkflowDocumentResult\(result\)/)
assert.match(records, /PDF öffnen \/ drucken/)
assert.match(documents, /resolveConfiguredTemplateName/)
assert.match(documents, /normalizedTemplateName/)
assert.match(workflows, /Dokumentpaket konnte nicht vollständig erzeugt werden/)
assert.match(groupware, /CalendarObjectDeletedEvent/)
assert.doesNotMatch(groupware, /markMissingFromCalendar\(/)
assert.match(documents, /nodeExists\(\$docxName\).*delete/)
assert.match(documents, /nodeExists\(\$name\).*delete/)
assert.match(read('appinfo/info.xml'), /<version>0\.(?:31\.(?:[3-9]|[1-9][0-9])|(?:3[2-9]|[4-9]\d)\.\d+)<\/version>/)

console.log('0.31.3 workflow input, orphan replacement and document-result checks passed.')
