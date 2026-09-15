import fs from 'node:fs'
import path from 'node:path'

const root=path.resolve(process.argv[2]||'.')
const read=(file)=>fs.readFileSync(path.join(root,file),'utf8')
const assert=(condition,message)=>{if(!condition)throw new Error(message)}

const migration=read('lib/Migration/Version1800Date20260906000000.php')
for(const marker of ['service_period_from','service_period_to','bestatter_schedule_types','bestatter_resources','bestatter_schedule_history'])assert(migration.includes(marker),`Migration 2400 fehlt: ${marker}`)

const commercial=read('lib/Service/CommercialService.php')
for(const marker of ['selectInvoiceServices','invoiceQuantityMilli','servicePeriodFrom','Die gewählte Rechnungsmenge'])assert(commercial.includes(marker),`Teilrechnungslogik fehlt: ${marker}`)
const document=read('lib/Service/DocumentService.php')
for(const marker of ['invoice_service_period_from','invoice_service_period_to','invoice_service_period'])assert(document.includes(marker),`Dokumentfeld fehlt: ${marker}`)
assert(read('lib/Service/EInvoiceService.php').includes('ram:BillingSpecifiedPeriod'),'Leistungszeitraum fehlt im EN16931-Datensatz.')

const scheduling=read('lib/Service/SchedulingService.php')
for(const marker of ['bufferBeforeMinutes','bufferAfterMinutes','resourceKeys','Terminkonflikt','bestatter_schedule_history'])assert(scheduling.includes(marker),`Disposition fehlt: ${marker}`)
const routes=read('appinfo/routes.php')
for(const marker of ['schedulingCatalog','saveScheduleType','saveScheduleResource','scheduleHistory'])assert(routes.includes(marker),`Terminplanungsroute fehlt: ${marker}`)
const ui=[read('src/main.js'),read('src/modules/commercial.js'),read('src/modules/records.js'),read('src/modules/customizing.js'),read('src/modules/administration.js')].join('\n')
for(const marker of ['Kontrollierte Fakturierung','Offene Restmenge','Terminplanung und Disposition','Vorbereitungszeit','Konflikt ausnahmsweise übersteuern','relevantExceptions'])assert(ui.includes(marker),`Oberfläche 0.42 fehlt: ${marker}`)

for(const file of ['docs/ENTWICKLUNG.md','docs/UPGRADE.md'])assert(fs.existsSync(path.join(root,file)),`Release-Dokument fehlt: ${file}`)
assert(/<version>0\.(?:42\.(?:[0-9]|[1-9][0-9])|4[3-9]\.\d+|[5-9]\d\.\d+)<\/version>/.test(read('appinfo/info.xml')),'App-Version ist älter als 0.42.0.')
assert(/VERSION = '0\.(?:42\.(?:[0-9]|[1-9][0-9])|4[3-9]\.\d+|[5-9]\d\.\d+)'/.test(read('lib/AppInfo/Application.php')),'Laufzeitversion ist älter als 0.42.0.')

console.log('0.42.0 Fakturierung und Termin-Disposition statisch geprüft.')
