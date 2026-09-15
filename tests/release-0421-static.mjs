import fs from 'node:fs'
import path from 'node:path'

const root=path.resolve(process.argv[2]||'.')
const read=(file)=>fs.readFileSync(path.join(root,file),'utf8')
const assert=(condition,message)=>{if(!condition)throw new Error(message)}

const records=read('lib/Service/RecordService.php')
assert(records.includes('escapeLikeParameter($uid)'),'Mehrfach-Zuständigkeiten fehlen in der regulären Datensatzabfrage.')

const cases=read('src/modules/cases.js')
for(const marker of ['data-dashboard-record-type="task"','const type = item.kind === \'Termin\' ? \'schedule\' : \'task\'','data-dashboard-record-type="${type}"','bp-agenda-item'])assert(cases.includes(marker),`Dashboard-Deep-Link fehlt: ${marker}`)

const main=read('src/main.js')+'\n'+read('src/modules/workspace.js')
for(const marker of ['bestatter:workspace:v1','rememberWorkspace','restoreWorkspace','data-dashboard-record-id','const activeCaseId'])assert(main.includes(marker),`Arbeitsbereich-/Dashboardlogik fehlt: ${marker}`)
assert(main.includes("qrState==='SERVER_RENDERED_PNG'"),'Verständliche QR-Ausgabebestätigung fehlt.')

const documents=read('lib/Service/DocumentService.php')
assert(documents.includes('docxContainsMedia'),'Binäre Prüfung des eingebetteten QR-Bildes fehlt.')
const euroOffice=read('lib/Service/EuroOfficeConversionService.php')
assert(euroOffice.includes("hash('sha256', (string)$docx->getContent())"),'PDF-Konvertierung verwendet keinen inhaltsbasierten Cache-Schlüssel.')

const css=read('css/style.css')
for(const marker of ['minmax(360px, .9fr)','min-height: 132px','bp-agenda-item'])assert(css.includes(marker),`Layoutkorrektur fehlt: ${marker}`)

const currentVersion=read('appinfo/info.xml').match(/<version>(\d+)\.(\d+)\.(\d+)<\/version>/)?.slice(1).map(Number)||[]
assert(currentVersion[0]>0||currentVersion[1]>42||(currentVersion[1]===42&&currentVersion[2]>=1),'App-Version ist älter als 0.42.1.')
assert(read('lib/AppInfo/Application.php').includes(`VERSION = '${currentVersion.join('.')}'`),'Laufzeitversion stimmt nicht mit info.xml überein.')
console.log('0.42.1 Dashboard, Pflegeoberflächen und Rechnungs-QR statisch geprüft.')
