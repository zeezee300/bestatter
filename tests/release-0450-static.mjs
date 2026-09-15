import fs from 'node:fs'
import path from 'node:path'

const root=path.resolve(process.argv[2]||'.')
const read=(file)=>fs.readFileSync(path.join(root,file),'utf8')
const assert=(condition,message)=>{if(!condition)throw new Error(message)}

const reporting=read('lib/Service/ReportingService.php')
for(const marker of ['actualTimeCoveragePercent','serviceCompletionRate','billingRate','committedInvoiceGross','qualityChecks','Rechnungssummen','Personalkapazität'])assert(reporting.includes(marker),`KPI/Plausibilitätsprüfung fehlt: ${marker}`)
assert(reporting.includes("'ABGESAGT' || $status === 'CANCELLED'"),'Absagequote berücksichtigt abgesagte Termine nicht.')
const handbook=read('docs/KENNZAHLEN-UND-AUSWERTUNGEN.md')
for(const marker of ['Zähler','Nenner','Plausibilitätsprüfungen','Dienstpläne','Rechnungsarithmetik'])assert(handbook.includes(marker),`Benutzerhandbuch unvollständig: ${marker}`)
const op=read('docs/OP-LISTE.md')
assert(op.includes('OP-031 – Dienstpläne und betriebliche Disposition'),'OP-031 fehlt.')
const ui=read('src/modules/administration.js')+read('css/style.css')
for(const marker of ['Datenqualität und Plausibilität','Istzeit-Abdeckung','Planbelastung je Mitarbeiter','bp-kpi-quality'])assert(ui.includes(marker),`KPI-Oberfläche fehlt: ${marker}`)
const manifest=read('appinfo/info.xml').match(/<version>([^<]+)<\/version>/)?.[1]
const runtime=read('lib/AppInfo/Application.php').match(/VERSION = '([^']+)'/)?.[1]
const packageVersion=JSON.parse(read('package.json')).version
assert(manifest===runtime&&packageVersion===manifest,'Versionsstand ist nicht konsistent.')
for(const file of ['docs/UPGRADE.md','docs/ENTWICKLUNG.md'])assert(fs.existsSync(path.join(root,file)),`Dokumentation fehlt: ${file}`)
console.log('0.45.0 Kennzahlen, Plausibilitätsprüfung und OP-031 statisch geprüft.')
