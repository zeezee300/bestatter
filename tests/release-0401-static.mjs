import { readFileSync } from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => readFileSync(path.join(root, file), 'utf8')
const expect = (condition, message) => { if (!condition) throw new Error(message) }
const frontend = read('src/modules/assistant.js')
const service = read('lib/Service/AssistantService.php')
const main = read('src/main.js')
const css = read('css/style.css')

for (const marker of ['previewSidebarIntent({ preventDefault()', 'bestatter-assistant-case-id', 'Enter startet die Prüfung', 'bp-assistant-case-field']) expect(frontend.includes(marker), `Assistenten-UX fehlt: ${marker}`)
expect(main.includes("bestatter-assistant-case-id"), 'Persistenter Fallbezug fehlt im Anwendungszustand')
expect(css.includes('width: min(540px, 100vw)'), 'Breiteres Assistentenfenster fehlt')
for (const marker of ['UPDATE_CASE_MASTER_DATA', 'CREATE_TASK_BATCH', 'prepareCaseUpdate', 'prepareTaskBatch', 'MASTER_DATA_APPLIED', 'TASK_BATCH_CREATED', 'batchId']) expect(service.includes(marker), `Neue Assistentenfähigkeit fehlt: ${marker}`)
expect(service.includes("array_replace($masterData"), 'Nicht genannte Falldaten werden beim Ergänzen nicht sicher bewahrt')

console.log('0.40.1 Assistentenfähigkeiten und Bedienkorrekturen statisch geprüft.')
