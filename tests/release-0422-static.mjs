import fs from 'node:fs'
import path from 'node:path'

const root=path.resolve(process.argv[2]||'.')
const read=(file)=>fs.readFileSync(path.join(root,file),'utf8')
const assert=(condition,message)=>{if(!condition)throw new Error(message)}

const fields=['spouse_date_of_birth','spouse_birth_place','spouse_residence','spouse_date_of_death','spouse_death_place','marriage_date','marriage_place','partnership_date','divorce_date']
const schema=JSON.parse(read('resources/case-field-schema.json'))
for(const field of fields) assert(schema.some((entry)=>entry.key===field),`Feldkatalog enthält ${field} nicht.`)

const main=read('src/main.js')
const assistant=read('src/modules/assistant.js')
const assistantService=read('lib/Service/AssistantService.php')
for(const field of fields){
	assert(main.includes(field),`Fallformular enthält ${field} nicht.`)
	assert(assistant.includes(field),`Schnellerfassung enthält ${field} nicht.`)
	assert(assistantService.includes(`'${field}'`),`Assistentenkorrekturen enthalten ${field} nicht.`)
}

const cases=read('lib/Service/CaseService.php')
for(const field of ['spouse_date_of_birth','spouse_date_of_death','marriage_date','partnership_date','divorce_date'])assert(cases.includes(`'${field}' =>`),`Datumsvalidierung fehlt für ${field}.`)
assert(cases.match(/normalizeMasterDates\(\$data\)/g)?.length>=2,'Neue Datumsfelder werden nicht bei Anlage und Änderung validiert.')
assert(read('tests/php/Unit/CaseServiceTest.php').includes('testRejectsInvalidOptionalFamilyDate'),'PHP-Regressionstest für ungültige Datumswerte fehlt.')
const currentVersion=read('appinfo/info.xml').match(/<version>(\d+)\.(\d+)\.(\d+)<\/version>/)?.slice(1).map(Number)||[]
assert(currentVersion[0]>0||currentVersion[1]>42||(currentVersion[1]===42&&currentVersion[2]>=2),'App-Version ist älter als 0.42.2.')
assert(read('lib/AppInfo/Application.php').includes(`VERSION = '${currentVersion.join('.')}'`),'Laufzeitversion stimmt nicht mit info.xml überein.')
console.log('0.42.2 Ehe-/Partnerschaftsdaten statisch geprüft.')
