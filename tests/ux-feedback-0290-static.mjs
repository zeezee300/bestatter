import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

const root = process.argv[2] || '.'
const read = (file) => readFileSync(join(root, file), 'utf8')
const source = ['src/main.js','src/modules/cases.js','src/modules/records.js','src/modules/commercial.js','src/modules/documents.js','src/modules/administration.js','src/modules/customizing.js'].map(read).join('\n')
assert.doesNotMatch(source, /\b(?:alert|confirm|prompt)\s*\(/, 'native Browserdialoge sind vollständig entfernt')

const ui = read('src/modules/ui.js')
for (const marker of ['notifyError', 'beginLoading', 'confirmAction', 'promptAction', 'aria-modal', 'aria-invalid', 'MutationObserver']) assert.ok(ui.includes(marker), `UI-Infrastruktur enthält ${marker}`)
const css = read('css/style.css')
for (const marker of ['bp-global-loading', 'bp-toast-region', 'bp-ui-dialog-overlay', 'prefers-reduced-motion', 'bestatter-loading']) assert.ok(css.includes(marker), `UI-CSS enthält ${marker}`)

const cases = read('lib/Service/CaseService.php')
for (const marker of ['searchCases(', 'setFirstResult(', 'setMaxResults(', 'responsible_employee', "'OPEN'"]) assert.ok(cases.includes(marker), `serverseitige Fallsuche enthält ${marker}`)
assert.ok(read('appinfo/routes.php').includes("'/api/cases/search'"), 'Fallsuchroute ist registriert')
assert.ok(read('src/modules/cases.js').includes('Meine offenen Fälle') && read('src/modules/cases.js').includes('data-case-page'), 'Fallliste enthält Schnellansichten und Pagination')
assert.match(read('appinfo/info.xml'), /<version>0\.(?:29|[3-9]\d)\./, 'Release enthält mindestens UX-Stand 0.29.0')
console.log('0.29.0 UX feedback, loading, accessibility and server-side case search contracts passed')
