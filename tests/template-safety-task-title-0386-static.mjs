import assert from 'node:assert/strict'
import fs from 'node:fs'

const configuration = fs.readFileSync('lib/Service/ConfigurationService.php', 'utf8')
const documents = fs.readFileSync('lib/Service/DocumentService.php', 'utf8')
const workflows = fs.readFileSync('lib/Service/WorkflowService.php', 'utf8')
const groupware = fs.readFileSync('lib/Service/GroupwareService.php', 'utf8')

assert.match(configuration, /nodeExists\(\$fileName\)[\s\S]*?'status' => 'PRESERVED'[\s\S]*?continue;/)
assert.doesNotMatch(configuration, /nodeExists\(\$fileName\) \? \$target->get/)
assert.match(documents, /'templateSource' => \$templateSource/)
assert.match(documents, /hash\('sha256', \$content\)/)
assert.doesNotMatch(documents, /invoiceTemplateComplete\([^)]*\)[\s\S]{0,120}putContent/)
assert.match(workflows, /templateFingerprint\(\$templateKey, \$userId\)/)
assert.match(workflows, /hash_equals\(\$currentTemplateHash, \$storedTemplateHash\)/)
assert.match(groupware, /if \(\$type === 'task'\) return '\[' \. \$caseNumber \. '\] ' \. \$recordTitle \. \(\$person !== '' \? ' – ' \. \$person : ''\);/)

console.log('template safety and future task title static checks passed')
