import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

const root = process.argv[2] || '.'
const read = (file) => readFileSync(join(root, file), 'utf8')

const main = read('src/main.js')
for (const marker of ['renderLocationKey', 'renderedLocationKey', 'scrollPositions', 'scrollTo(scrollPosition)', 'schedulePresetsUrl', 'state.schedulePresets']) {
	assert.ok(main.includes(marker), `zentraler Render-/Terminvertrag enthält ${marker}`)
}

const records = read('src/modules/records.js')
for (const marker of ['Terminart / Vorlage', 'schedulePresetKey', 'durationMinutes', 'scheduleCategories', 'alle Angaben bleiben']) {
	assert.ok(records.includes(marker), `Terminformular enthält ${marker}`)
}

const workflow = read('lib/Service/WorkflowService.php')
for (const marker of ['schedulePresets()', 'normalizeSchedulePreset', "'CATALOG'", "'WORKFLOW'"]) {
	assert.ok(workflow.includes(marker), `Terminvorlagenservice enthält ${marker}`)
}

const routes = read('appinfo/routes.php')
assert.ok(routes.includes("recordApi#schedulePresets") && routes.includes('/api/schedule-presets'), 'Terminvorlagen sind über eine geschützte App-Route erreichbar')

const groupware = read('lib/Service/GroupwareService.php')
assert.ok(groupware.includes('X-BESTATTER-STATUS:') && groupware.includes("'ABGESAGT' ? 'CANCELLED' : 'CONFIRMED'"), 'Erledigte Termine bleiben bestätigt, abgesagte Termine werden korrekt als CANCELLED exportiert')
assert.ok(groupware.includes("$categories = ['Bestatter']") && !groupware.includes("'Bestatter,' . $caseNumber"), 'Nextcloud-Kategorien werden einzeln und ohne redundante Fallnummer ausgegeben')
assert.ok(groupware.includes('X-BESTATTER-DURATION-MINUTES:'), 'Termindauer wird verlustfrei synchronisiert')

const css = read('css/style.css')
assert.ok(css.includes('.bp-service-toolbar input:not([type="checkbox"]):not([type="radio"])'), 'Toolbar-Breitenregel vergrößert Checkboxen nicht')

const resource = JSON.parse(read('resources/workflow-templates.json'))
assert.ok(resource.schedulePresets.length >= 8, 'fachlicher Terminvorlagenkatalog enthält die zentralen Bestattungsereignisse')
assert.ok(resource.schedulePresets.some((entry) => entry.key === 'BEISETZUNG'), 'Beisetzung ist als Terminvorlage vorhanden')

console.log('0.27.0 UX, scroll preservation, schedule presets and groupware contracts passed')
