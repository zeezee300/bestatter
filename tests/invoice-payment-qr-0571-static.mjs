import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }

const commercial = read('lib/Service/CommercialService.php')
for (const marker of ['invoicePaymentSnapshot', 'payment_snapshot', 'bestatter-invoice-payment/1', "'qrStandard' => $qrStandard"]) {
	assert(commercial.includes(marker), `Zahlungs-Snapshot fehlt: ${marker}`)
}

const documents = read('lib/Service/DocumentService.php')
for (const marker of ['paymentSnapshot', 'snapshotUsed', 'DISABLED_BY_CONFIGURATION', 'invoice_payment_qr_label', 'Die Rechnung wurde nicht ohne QR-Code ausgegeben']) {
	assert(documents.includes(marker), `QR-Ausgabeschutz fehlt: ${marker}`)
}
assert(documents.includes("'RECHNUNG.docx' => ['94695c36"), 'Vorhandene unveränderte Standard-Rechnungsvorlage wird nicht aktualisiert.')

const migration = read('lib/Migration/Version3400Date20260911010000.php')
assert(migration.includes("addColumn('payment_snapshot', 'text', ['notnull' => false])"), 'Migrationssicherer Zahlungs-Snapshot fehlt.')

const templateBuilder = read('tests/build_invoice_template.py')
assert(templateBuilder.includes('{{case.invoice_payment_qr_label}}'), 'Dynamische Beschriftung des QR-Bereichs fehlt.')

const frontend = read('src/main.js')
for (const marker of ['SUPPRESSED_FOR_DIRECT_DEBIT', 'DISABLED_BY_CONFIGURATION', 'bestimmungsgemäß kein Überweisungs-QR']) {
	assert(frontend.includes(marker), `Verständliche QR-Rückmeldung fehlt: ${marker}`)
}

console.log('0.57.1 Zahlungs-Snapshot, QR-Ausgabeschutz und eindeutige Unterdrückungsgründe statisch geprüft.')
