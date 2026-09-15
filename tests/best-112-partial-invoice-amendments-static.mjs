import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const root = process.argv[2] || '.'
const read = (file) => readFileSync(`${root}/${file}`, 'utf8')
const state = read('lib/Service/CommercialStateService.php')
const articles = read('lib/Service/ArticleService.php')
const commercial = read('lib/Service/CommercialService.php')
const reporting = read('lib/Service/ReportingService.php')
const ui = read('src/modules/commercial.js')
const ticket = read('docs/TICKET-BEST-112-NACHTRAEGE-NACH-TEILRECHNUNG.md')

assert(state.includes("invoice_type") && state.includes("'FINAL'") && state.includes("!$hasActiveFinalInvoice"))
assert(state.includes("'hasActiveFinalInvoice'") && state.includes('Teilrechnung vorhanden'))
assert(articles.includes("$amendmentLine['origin'] = 'NACHTRAG'") && articles.includes("'CONTRACT_AMENDMENT'"))
assert(articles.includes("invoicedContractChanged") && articles.includes('Bereits fakturierte Leistungen dürfen'))
assert(articles.includes("['NACHTRAG', 'INCOMING_INVOICE']"), 'NACHTRAG muss auch bei Freitext erhalten bleiben.')
assert(commercial.includes("$item['performedQuantityMilli']-$item['invoicedQuantityMilli']") || commercial.includes("$item['performedQuantityMilli'] - $item['invoicedQuantityMilli']"))
assert(ui.includes("item.origin === 'NACHTRAG' ? 'NTR'") && ui.includes('bp-service-row-invoiced'))
assert(reporting.includes("'Herkunft'") && reporting.includes("'NTR – Nachtrag'"), 'Der Leistungsbericht muss Nachträge ausweisen.')
for (let criterion = 1; criterion <= 8; criterion++) assert(ticket.includes(`${criterion}.`), `Akzeptanzkriterium ${criterion} fehlt.`)
assert(ticket.includes('Abstimmung mit der Buchhaltung') && ticket.includes('Risiko-Hinweis'))

console.log('BEST-112: Nachträge nach Teilrechnung statisch geprüft.')
