import fs from 'node:fs'
import assert from 'node:assert/strict'

const read = (file) => fs.readFileSync(file, 'utf8')
const cases = read('src/modules/cases.js')
const main = read('src/main.js')
const commercial = read('src/modules/commercial.js')
const caseService = read('lib/Service/CaseService.php')
const sideOrders = read('lib/Service/SideOrderService.php')
const operations = read('lib/Service/OperationalService.php')
const cockpit = read('lib/Service/OperationsCockpitService.php')
const controller = read('lib/Controller/CaseApiController.php')
const migration = read('lib/Migration/Version3600Date20260915010000.php')
const css = read('css/style.css')

assert(!cases.includes("${orders.length ? '' : 'open'}"), 'Das Anlageformular darf im Leerzustand nicht automatisch geöffnet werden.')
for (const marker of ['sideOrderView', 'data-select-side-order', 'data-side-order-view', 'Kopfdaten', 'Finanzen / Abrechnung', 'case-side-order-filter', 'data-open-side-order']) assert(cases.includes(marker) || main.includes(marker), `Frontend-Marker fehlt: ${marker}`)
assert(commercial.includes('scopeLabel') && commercial.includes('activeSideOrder.firstName') && commercial.includes("caseTab = 'side-orders'"), 'Positionskopf oder Rücknavigation verliert den Nebenauftragskontext.')
for (const marker of ['sideOrderSummary', 'sideOrderCaseIdsForSearch', 'sideOrderCaseIdsForFilter', 'BILLING_OPEN', 'assertClosable', 'Offene Nebenaufträge']) assert(caseService.includes(marker), `CaseService-Marker fehlt: ${marker}`)
assert(caseService.includes('searchTerms($search)') && caseService.includes("preg_split('/\\s+/u'"), 'Mehrteilige Namen werden in der Fall- und Nebenauftragssuche nicht feldübergreifend gesucht.')
assert(cases.includes("summary.total === 1 ? '1 Nebenauftrag' : `${summary.total} Nebenaufträge`"), 'Die Mehrzahl Nebenaufträge wird in der Fallliste nicht korrekt dargestellt.')
assert(controller.includes("string $sideOrders = 'ALL'"), 'Der Nebenauftragsfilter fehlt im API-Vertrag.')
assert(sideOrders.includes('abgeschlossenen oder stornierten Fall'), 'Die Anlagesperre für geschlossene Fälle fehlt.')
assert(operations.includes('sideOrderCheck'), 'Die Vollständigkeitsprüfung berücksichtigt Nebenaufträge nicht.')
assert(cockpit.includes('inconsistentClosedCases') && cockpit.includes('inconsistentSideOrderCases'), 'Die Altbestandsdiagnose fehlt.')
for (const marker of ['bestatter_side_order_name', 'bestatter_side_order_status_case']) assert(migration.includes(marker), `Suchindex fehlt: ${marker}`)
for (const marker of ['bp-side-order-child', 'bp-side-order-context', 'bp-side-order-workspace']) assert(css.includes(marker), `Responsive CSS-Marker fehlt: ${marker}`)

console.log('BEST-115: Navigation, Suche, Abschlussprüfung und Diagnose statisch geprüft.')
