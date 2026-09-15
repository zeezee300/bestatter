import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const expect = (condition, message) => { if (!condition) throw new Error(message) }

const migration = read('lib/Migration/Version1800Date20260906000000.php')
for (const table of ['bestatter_incoming_invoices', 'bestatter_incoming_items']) expect(migration.includes(table), `Migrationstabelle ${table} fehlt`)
for (const index of ['bestatter_incoming_supplier', 'bestatter_incoming_document', 'bestatter_incoming_position']) expect(migration.includes(index), `Dubletten-/Positionsindex ${index} fehlt`)

const service = read('lib/Service/IncomingInvoiceService.php')
for (const marker of ['TRUE_PASS_THROUGH', 'THIRD_PARTY_SERVICE', 'EXPENSE_FEE', 'NOT_BILLABLE', 'CLASSIFICATION_PENDING', 'document_sha256', 'assertUnique', 'varianceReason', 'TRANSFERRED', 'INCOMING_INVOICE']) expect(service.includes(marker), `Eingangsrechnungslogik ${marker} fehlt`)
expect(service.includes('beginTransaction') && service.includes('rollBack'), 'Transaktionale Übernahme fehlt')

const routes = read('appinfo/routes.php')
for (const route of ['/incoming-invoices', '/incoming-invoices/{id}/transfer', '/incoming-invoices/{id}/transition']) expect(routes.includes(route), `API-Route ${route} fehlt`)
const ui = read('src/modules/administration.js')
for (const marker of ['Eingangsrechnungen und durchlaufende Posten', 'showIncomingInvoiceForm', 'bindIncomingInvoices', 'Positionen übernehmen']) expect(ui.includes(marker), `Eingangsrechnungs-UI ${marker} fehlt`)

const op = read('docs/OP-LISTE.md')
expect(op.includes('OP-022 – Optionale Paperless-ngx-Anbindung'), 'Zurückgestellte Paperless-Stufe fehlt in der OP-Liste')
expect(op.includes('ohne externe Abhängigkeit in 0.38.0 umgesetzt'), 'Eigenständige Stufe ist nicht dokumentiert')

console.log('0.38.0 Eingangsrechnungen ohne Paperless: statischer Vertrag OK')
