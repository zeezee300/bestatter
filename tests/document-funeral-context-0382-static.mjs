import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const service = fs.readFileSync(path.join(root, 'lib/Service/DocumentService.php'), 'utf8')
const controller = fs.readFileSync(path.join(root, 'lib/Controller/DocumentApiController.php'), 'utf8')
const ui = fs.readFileSync(path.join(root, 'src/modules/documents.js'), 'utf8')
const css = fs.readFileSync(path.join(root, 'css/style.css'), 'utf8')

for (const marker of [
	'withFuneralEventContext',
	'funeralEventOptions',
	"'BESTAETIGT'",
	"'funeral_event_date'",
	"'displayDate'",
	"'selectedScheduleId'",
]) assert.ok(service.includes(marker), `Termin-Kontext fehlt im DocumentService: ${marker}`)

assert.match(controller, /documentPreview\([^)]*int \$scheduleId = 0/)
assert.match(controller, /generateDocument\([^)]*int \$scheduleId = 0/)
assert.ok(ui.includes('Verknüpfte Trauerfeier'))
assert.ok(ui.includes("<dt>Trauerfeier</dt>"))
assert.ok(ui.includes('scheduleId: String'))
assert.match(css, /bp-save-state\[data-state="error"\][\s\S]*color-main-text/)
assert.match(css, /bp-save-state\[data-state="error"\]::before/)

console.log('Dokument-Trauerfeier-Kontext und lesbare Fehlermeldung sind abgesichert.')
