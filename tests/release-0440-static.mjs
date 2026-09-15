import fs from 'node:fs'
import path from 'node:path'

const root=path.resolve(process.argv[2]||'.')
const read=(file)=>fs.readFileSync(path.join(root,file),'utf8')
const assert=(condition,message)=>{if(!condition)throw new Error(message)}

const migration=read('lib/Migration/Version1800Date20260906000000.php')
for(const marker of ['required_staff','required_vehicles','required_rooms','required_chapels','required_equipment','default_resource_keys','changes_json'])assert(migration.includes(marker),`Migration 2500 fehlt: ${marker}`)

const scheduling=read('lib/Service/SchedulingService.php')
for(const marker of ["'CHAPEL'",'assertRequirements','requiredStaff','defaultResourceKeys','Mögliche Alternativen','requireChangeReason','changes_json'])assert(scheduling.includes(marker),`Terminlogik 0.44 fehlt: ${marker}`)

const groupware=read('lib/Service/GroupwareService.php')
assert(groupware.includes("strtoupper($status) === 'ABGESAGT' ? 'CANCELLED' : 'CONFIRMED'"),'Abgesagte Termine werden nicht als CANCELLED exportiert.')
assert(groupware.includes("$type === 'schedule' && $status === 'CANCELLED'"),'CANCELLED wird bei der Rücksynchronisation nicht als ABGESAGT erkannt.')
for(const marker of ['X-BESTATTER-RESOURCE-KEYS','X-BESTATTER-BUFFER-BEFORE-MINUTES','X-BESTATTER-BUFFER-AFTER-MINUTES'])assert(groupware.includes(marker),`Dispositionsmetadatum fehlt in CalDAV: ${marker}`)

const controller=read('lib/Controller/RecordApiController.php')
assert(controller.includes('requireChangeReason')&&controller.includes("'COMPLETED'"),'Änderungsgrund oder Historienaktion fehlt.')

const ui=read('src/modules/customizing.js')+read('src/modules/records.js')
for(const marker of ['Pflichtbedarf festlegen','requiredChapels','Alternative Termine','Alt-/Neu-Werten','Kapelle'])assert(ui.includes(marker),`Oberfläche 0.44 fehlt: ${marker}`)

for(const file of ['docs/UPGRADE.md','docs/TERMINPLANUNG-UND-VERFUEGBARKEIT.md'])assert(fs.existsSync(path.join(root,file)),`Dokumentation fehlt: ${file}`)
const manifest=read('appinfo/info.xml').match(/<version>([^<]+)<\/version>/)?.[1]
const runtime=read('lib/AppInfo/Application.php').match(/VERSION = '([^']+)'/)?.[1]
assert(manifest===runtime,'Versionsstand von Manifest und Runtime ist nicht konsistent.')

console.log('0.44.0 Terminressourcen, Konfliktalternativen, Status und Historie statisch geprüft.')
