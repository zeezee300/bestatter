import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => readFileSync(path.join(root, file), 'utf8')
const ui = read('src/modules/customizing.js')
const lists = read('lib/Service/CustomizingService.php')
const checklists = read('lib/Service/ChecklistService.php')
const job = read('lib/BackgroundJob/MaintenanceJob.php')
const info = read('appinfo/info.xml')
const system = read('lib/Service/StabilizationService.php')

assert.match(ui, /data-drag-kind="\$\{kind\}"/)
assert.match(ui, /Alt\+Pfeil/)
assert.match(ui, /bp-checklist-maintenance/)
assert.match(ui, /data-checklist-offset/)
assert.match(ui, /data-checklist-priority/)
assert.match(lists, /function reorderItems/)
assert.match(lists, /beginTransaction\(\)/)
assert.match(checklists, /beginTransaction\(\)/)
assert.match(checklists, /Eine Checklistenposition wurde zwischenzeitlich geändert/)
assert.match(job, /extends TimedJob/)
assert.match(job, /setInterval\(6 \* 60 \* 60\)/)
assert.match(job, /setAllowParallelRuns\(false\)/)
assert.match(info, /OCA\\Bestatter\\BackgroundJob\\MaintenanceJob/)
assert.match(system, /scheduled_maintenance/)

console.log('0.40.3 Tabellenpflege, stabile Checklisten und Wartungsjob statisch geprüft.')
