import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { createCasesModule } from '../src/modules/cases.js'

const root = path.resolve(process.argv[2] || '.')
const read = (name) => readFileSync(path.join(root, name), 'utf8')
const team = read('lib/Service/TeamService.php')
const cases = read('lib/Service/CaseService.php')
const mail = read('lib/Controller/BusinessMailApiController.php')

assert.match(team, /function canEditCase\(array \$case\)/)
assert.match(team, /\$uid === trim\(\(string\)\(\$case\['responsibleEmployee'\]/)
assert.match(team, /function requireCaseEditor\(array \$case\)/)
assert.match(cases, /\$this->team->assignee\(\$responsibleUid\)\['uid'\]/)
assert.match(cases, /if \(\$responsibleUid !== \(string\)\$case\['responsibleEmployee'\]\) \{/)
assert.match(cases, /\$this->team->requireBestatterAdmin\(\)/)
for (const operation of ['availability', 'preview', 'history', 'send']) assert.match(mail, new RegExp(`function ${operation}\\(`))
assert.equal((mail.match(/requireCaseEditor\(\$case\)/g) || []).length, 3)
assert.match(mail, /canEditCase\(\$case\)/)

const esc = (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('"', '&quot;')
const state = { newCase: false, team: { currentUid: 'bea', isBestatterAdmin: false, members: [
  { uid: 'bea', displayName: 'Bea Beispiel' }, { uid: 'alex', displayName: 'Alex Beispiel' },
] }, branches: [], customizing: [] }
const module = createCasesModule({ state, sections: {}, labels: { responsible_employee: 'Zuständig' }, listMap: {}, fixedOptions: {}, esc })
let html = module.field('responsible_employee', { responsible_employee: 'alex' })
assert.match(html, /value="alex" selected/)
assert.match(html, /disabled/)
assert.match(html, /type="hidden" name="responsible_employee" value="alex"/)
assert.doesNotMatch(html, /value="Alex Beispiel"/)
state.team.isBestatterAdmin = true
html = module.field('responsible_employee', { responsible_employee: 'alex' })
assert.doesNotMatch(html, /disabled/)
state.newCase = true
state.team.isBestatterAdmin = false
globalThis.OC = { getCurrentUser: () => ({ uid: 'bea' }) }
html = module.field('responsible_employee', {})
assert.match(html, /value="bea" selected/)
assert.doesNotMatch(html, /disabled/)
console.log('0.65.0: UID-Zuordnung, geschützte Umbesetzung und fallbezogene Mailrechte statisch/DOM-geprüft.')
