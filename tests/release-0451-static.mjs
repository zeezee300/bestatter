import fs from 'node:fs'
import path from 'node:path'

const root=path.resolve(process.argv[2]||'.')
const read=(file)=>fs.readFileSync(path.join(root,file),'utf8')
const assert=(condition,message)=>{if(!condition)throw new Error(message)}
const records=read('src/modules/records.js')
const css=read('css/style.css')
for(const marker of ['function formatRecordDate','function recordBadges','bp-record-form-${type}','Priorität:','Status:'])assert(records.includes(marker),`Aufgabenübersicht 0.45.1 fehlt: ${marker}`)
for(const marker of ['bp-record-form','bp-record-badges','bp-status-in-bearbeitung','bp-priority-hoch'])assert(css.includes(marker),`Darstellung 0.45.1 fehlt: ${marker}`)
assert(records.includes("`${date}, ${match[4]}:${match[5]} Uhr`"),'Datum/Uhrzeit werden nicht deutsch dargestellt.')
const manifest=read('appinfo/info.xml').match(/<version>([^<]+)<\/version>/)?.[1]
const runtime=read('lib/AppInfo/Application.php').match(/VERSION = '([^']+)'/)?.[1]
assert(manifest&&runtime===manifest,'Versionsstand ab 0.45.1 ist nicht konsistent.')
console.log('0.45.1 große Bearbeitungsdialoge sowie lesbare Aufgabenstatus, Prioritäten und Fälligkeiten geprüft.')
