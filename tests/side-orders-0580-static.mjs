import fs from 'node:fs'
import assert from 'node:assert/strict'

const read = (file) => fs.readFileSync(file, 'utf8')
const migration = read('lib/Migration/Version3500Date20260915000000.php')
const service = read('lib/Service/SideOrderService.php')
const article = read('lib/Service/ArticleService.php')
const state = read('lib/Service/CommercialStateService.php')
const controller = read('lib/Controller/SideOrderApiController.php')
const routes = read('appinfo/routes.php')
const cases = read('src/modules/cases.js')
const commercial = read('src/modules/commercial.js')
const main = read('src/main.js')

for (const marker of ['bestatter_side_orders', 'side_order_number', 'required_by', "hasColumn('side_order_id')", 'notnull' ]) assert.ok(migration.includes(marker), `Migration marker missing: ${marker}`)
for (const marker of ['nextNumber', 'funeralEvent', 'create(', 'update(', 'cancel(', "'sideOrderNumber'"]) assert.ok(service.includes(marker), `Side-order service marker missing: ${marker}`)
for (const marker of ['?int $sideOrderId = null', 'side_order_id', 'sideOrderId']) assert.ok(article.includes(marker), `Position scope marker missing: ${marker}`)
for (const marker of ['?int $sideOrderId = null', 'side_order_id', 'sideOrderId']) assert.ok(state.includes(marker), `Commercial state marker missing: ${marker}`)
for (const marker of ['listSideOrders', 'createSideOrder', 'updateSideOrder', 'cancelSideOrder']) assert.ok(controller.includes(marker), `API marker missing: ${marker}`)
for (const marker of ['sideOrderApi#listSideOrders', 'sideOrderApi#createSideOrder', 'sideOrderApi#updateSideOrder', 'sideOrderApi#cancelSideOrder']) assert.ok(routes.includes(marker), `Route missing: ${marker}`)
for (const marker of ['sideOrdersPanel', 'side-order-form', 'data-select-side-order', 'data-side-order-view']) assert.ok(cases.includes(marker), `Frontend case marker missing: ${marker}`)
for (const marker of ['activeSideOrderId', 'sideOrderId', 'back-to-side-orders']) assert.ok(commercial.includes(marker) || main.includes(marker), `Frontend scope marker missing: ${marker}`)

console.log('Nebenaufträge: Datenmodell, API, Positionsscope und Fallübersicht statisch geprüft.')
