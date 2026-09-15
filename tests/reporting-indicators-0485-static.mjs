import assert from 'node:assert/strict'
import fs from 'node:fs'
const read = (path) => fs.readFileSync(path, 'utf8')

assert(read('appinfo/info.xml').includes('<version>0.59.0</version>'))
const ui = read('src/modules/administration.js')
assert(!ui.includes('Empfohlene nächste Kennzahlen'), 'Konzeptionelle Kennzahlen dürfen nicht sichtbar angezeigt werden.')
for (const marker of ['Kennzahlenbericht', 'data-report="indicators"', "['indicators','Kennzahlenbericht'"]) assert(ui.includes(marker), `Abrufbarer Kennzahlenbericht fehlt: ${marker}`)
const service = read('lib/Service/ReportingService.php')
for (const marker of ["'indicators'", 'indicatorRowsFromKpis', 'actualPlanRatioPercent', 'taskCompletionRate', 'overdueRate', 'appointmentCancellationRate', 'performedNetShareByCostType', 'NOCH_NICHT_KONFIGURIERT']) assert(service.includes(marker), `Erzeugbare Kennzahl fehlt: ${marker}`)
assert(!service.includes('PARAM_STR_ARRAY'), 'Nicht portabler DB-Array-Parametertyp darf in der Arbeitsauswertung nicht verbleiben.')
assert(fs.existsSync('docs/ENTWICKLUNG.md'))
console.log('0.48.5 nicht sichtbare, serverseitig erzeugbare Zusatzkennzahlen und Vorabprüfung statisch geprüft.')
