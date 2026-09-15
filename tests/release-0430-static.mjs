import fs from 'node:fs'
import path from 'node:path'

const root=path.resolve(process.argv[2]||'.')
const read=(file)=>fs.readFileSync(path.join(root,file),'utf8')
const assert=(condition,message)=>{if(!condition)throw new Error(message)}

const documents=read('lib/Service/DocumentService.php')
assert(!documents.includes('LEGACY_INVOICE_TEMPLATE_HASHES'),'Alte Rechnungsvorlage könnte weiterhin automatisch überschrieben werden.')
assert(documents.includes("if (!$folder->nodeExists($name))"),'Paketvorlagen werden nicht ausschließlich bei fehlender Benutzerdatei bereitgestellt.')
assert(documents.includes('A file in the configured Nextcloud template directory always wins.'),'Schutz kundeneigener Vorlagen ist nicht dokumentiert.')

const reporting=read('lib/Service/ReportingService.php')
for(const marker of ["'kpis' => $kpis",'Planzeit Minuten','Istzeit Minuten','overdueTasks','plannedMinutes','invoiceGross'])assert(reporting.includes(marker),`Kennzahl fehlt: ${marker}`)
const administration=read('src/modules/administration.js')
for(const marker of ['Kennzahlen & Auswertungen','Mitarbeiter & Zeit','Fälle & Leistungen','CSV-Exporte','Planbelastung'])assert(administration.includes(marker),`Auswertungsmenü fehlt: ${marker}`)

const qa=read('tests/template-visual-qa.py')
for(const marker of ['compare_pages','validate_catalog','EMPTY_KEYS','qrEmbedded','ISO-Datum statt deutschem Datumsformat'])assert(qa.includes(marker),`Vorlagen-QA fehlt: ${marker}`)
const infoVersion = read('appinfo/info.xml').match(/<version>([^<]+)<\/version>/)?.[1] || ''
const runtimeVersion = read('lib/AppInfo/Application.php').match(/VERSION = '([^']+)'/)?.[1] || ''
const versionParts=infoVersion.split('.').map(Number)
assert(versionParts[0]>0||versionParts[1]>43||(versionParts[1]===43&&versionParts[2]>=0),'App-Version ist älter als 0.43.0.')
assert(runtimeVersion === infoVersion,'Manifest- und Laufzeitversion unterscheiden sich.')
console.log('0.43.0 Vorlagenabnahme und Kennzahlen statisch geprüft.')
