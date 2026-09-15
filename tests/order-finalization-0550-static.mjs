import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const root = process.argv[2] || '.'
const read = (file) => readFileSync(`${root}/${file}`, 'utf8')
const service = read('lib/Service/CommercialService.php')
const cases = read('lib/Service/CaseService.php')
const controller = read('lib/Controller/CommercialApiController.php')
const routes = read('appinfo/routes.php')
const main = read('src/main.js')
const docs = read('docs/TICKET-PLAUSIBILITAET-FESTSCHREIBUNG-0550.md')

assert(routes.includes("commercialApi#finalizationCheck") && controller.includes('function finalizationCheck'))
assert(service.includes('function finalizationCheck') && service.includes('assertFinalizationAllowed'))
assert(service.includes('FREE_TEXT_PRICE_') && service.includes('SEPA_') && service.includes('POSITION_TYPE_'))
assert(service.includes('confirmedWarningCodes') && service.includes("'checkedBy'"))
assert(cases.includes('Statuswechsel ist nur über die geprüfte Festschreibung'), 'Direktes Speichern des Zielstatus muss gesperrt sein.')
assert(main.includes("finalizeCommercialDocument('QUOTE')") && main.includes("finalizeCommercialDocument('ORDER')"))
assert(main.includes('select:not([name="order_status"])'), 'Festschreibungsstatus darf nicht normal autospeichern.')
assert(main.includes("selected === 'KVA versendet'") && main.includes("selected === 'beauftragt'"))
assert(docs.includes('Blockierende Regeln') && docs.includes('Bestätigungspflichtige Hinweise'))
assert(read('appinfo/info.xml').includes('<version>0.59.0</version>'))

console.log('order finalization 0.55.0 static checks passed')
