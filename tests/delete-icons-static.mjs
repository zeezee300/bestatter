import fs from 'node:fs'
import assert from 'node:assert/strict'

const read = (file) => fs.readFileSync(new URL('../' + file, import.meta.url), 'utf8')
const icons = read('src/modules/icons.js')
const ui = read('src/modules/ui.js')
const commercial = read('src/modules/commercial.js')
const css = read('css/style.css')
const notice = read('docs/THIRD-PARTY-ICONS.md')

for (const marker of ['bp-trash-icon', 'viewBox="0 0 24 24"', 'Tabler Icons', 'MIT License']) {
	assert(icons.includes(marker) || notice.includes(marker), `Icon-Nachweis fehlt: ${marker}`)
}
for (const selector of ['#delete-case', '#cleanup-backups', '.delete-backup', '.delete-article', '.delete-checklist', '.delete-workflow', '[data-remove-service-line]']) {
	assert(ui.includes(selector), `Löschaktion wird nicht zentral auf das Mülleimer-Icon umgestellt: ${selector}`)
}
assert(commercial.includes("trashButton('', 'Position löschen'"), 'Positionslöschung verwendet das freigegebene Mülleimer-Icon nicht direkt.')
assert(!commercial.includes('data-duplicate-service-line'), 'Duplizieren ist noch im Positionsmodul enthalten.')
for (const marker of ['.bp-delete-icon-button', '.bp-trash-icon', 'stroke: currentColor', 'width: 20px']) assert(css.includes(marker), `Icon-Stil fehlt: ${marker}`)

console.log('Einheitliche Mülleimer-Icons und ausgeblendetes Duplizieren: statischer Vertrag OK')
