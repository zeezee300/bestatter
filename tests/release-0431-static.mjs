import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }

const cases = read('src/modules/cases.js')
for (const marker of ['familyState', 'showDetails', 'Der Familienstand ist „ledig“', 'Bei „verwitwet“', "'Ehepartner/in', 'Ehe / Lebenspartnerschaft'"]) {
	assert(cases.includes(marker), `Familienstand-UI fehlt: ${marker}`)
}
const service = read('lib/Service/CaseService.php')
for (const marker of ['validateFamilyData', 'darf nicht vor dem Geburtsdatum', 'darf nicht vor dem Datum der Eheschließung', "$status === 'ledig'", "$status === 'verwitwet'", 'validationWarnings']) {
	assert(service.includes(marker), `Servervalidierung fehlt: ${marker}`)
}
const assistant = read('src/modules/assistant.js')
const analyze = assistant.slice(assistant.indexOf('async function analyzeTranscript'), assistant.indexOf('function applySuggestions'))
assert(!analyze.includes('applySuggestions(true)'), 'Erkannte Angaben werden noch ohne ausdrückliche Bestätigung übernommen.')
assert(assistant.includes('Ausgewählte Vorschläge übernehmen'), 'Explizite Bestätigung erkannter Angaben fehlt.')
assert(/<version>0\.(?:43\.(?:[1-9]|[1-9][0-9])|4[4-9]\.\d+|[5-9]\d\.\d+)<\/version>/.test(read('appinfo/info.xml')), 'Version ist älter als 0.43.1.')
console.log('0.43.1 Familienstandslogik und bestätigungspflichtige Assistentenvorschläge geprüft.')
