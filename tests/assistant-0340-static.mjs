import fs from 'node:fs'
import path from 'node:path'

const root = process.argv[2] || '.'
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }

const assistant = read('lib/Service/AssistantService.php')
const research = read('lib/Service/AssistantResearchService.php')
const contacts = read('lib/Service/ContactService.php')
const frontend = read('src/modules/assistant.js')
const administration = read('src/modules/administration.js')
const bundle = read('js/main.js')

for (const marker of ['RESEARCH_ORGANIZATION', 'CREATE_CONTACT', 'EXECUTE_WORKFLOW_ACTION', 'OPEN_EXISTING_DOCUMENTS', 'prepareCondolencePrint', 'sourceScheduleSnapshot']) assert(assistant.includes(marker), `Assistentenvertrag fehlt: ${marker}`)
for (const marker of ['OpenStreetMap/Nominatim', 'countrycodes', 'needsOfficialVerification', 'assistant_web_research_enabled']) assert(research.includes(marker), `Recherchevertrag fehlt: ${marker}`)
assert(contacts.includes('function duplicates'), 'Kontakt-Dublettenprüfung fehlt')
for (const marker of ['assistant-contact-choice', 'assistant-print-file', 'Rückfrage:', 'Browser-Druckdialog']) assert(frontend.includes(marker), `Assistenzoberfläche fehlt: ${marker}`)
assert(administration.includes('webResearchEnabled'), 'Administrativer Recherche-Schalter fehlt')
for (const marker of ['Geprüft ins Adressbuch übernehmen', 'PDF öffnen / drucken', 'webResearchEnabled']) assert(bundle.includes(marker), `Produktionsbundle fehlt: ${marker}`)

console.log('0.34.0 controlled research, contact and condolence print contracts passed')
