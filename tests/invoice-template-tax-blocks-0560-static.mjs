import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = file => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }

const documents = read('lib/Service/DocumentService.php')
for (const marker of [
	'replaceInvoiceTaxRows', 'removeEmptyPassThroughSummary', 'removeEmptyPriorInvoiceSummary', 'invoice_has_prior', 'service.group_heading',
	'order.taxable_net', 'order.pass_through_total', 'Eigene Leistungen',
	'Fremdleistungen und verauslagte Beträge', 'Durchlaufende Posten – nicht Teil des Entgelts',
	'muss der vollständige Leistungszeitraum', 'vollständigem Rechnungsempfänger',
]) assert(documents.includes(marker), `Rechnungslogik fehlt: ${marker}`)

const commercial = read('lib/Service/CommercialService.php')
assert(commercial.includes('Echte durchlaufende Posten müssen mit 0 % Umsatzsteuer'), 'DP-Steuerplausibilisierung fehlt.')
assert(commercial.includes("'positionType'=>"), 'Positionstyp wird nicht in den Rechnungsdatensatz übernommen.')

const electronic = read('lib/Service/EInvoiceService.php')
for (const marker of ["$isPassThrough", "? 'O' : 'S'", '§ 10 Abs. 1 Satz 6 UStG', "'category'=>$category"])
	assert(electronic.includes(marker), `E-Rechnungsabgrenzung fehlt: ${marker}`)

const builder = read('tests/build_invoice_template.py')
for (const marker of [
	'Leistungszeitraum', 'service.group_heading', 'service.group_subtotal_amount',
	'Steuerpflichtiges Nettoentgelt', 'tax.rate', 'tax.net', 'tax.vat',
	'WD_TABLE_ALIGNMENT.RIGHT', 'order.taxable_net', 'order.pass_through_total',
]) assert(builder.includes(marker), `Vorlagenbaustein fehlt: ${marker}`)
assert(builder.includes('[0.9, 5.5, 1.4, 1.9, 1.3, 2.8], WD_TABLE_ALIGNMENT.CENTER'), 'Leistungstabelle ist nicht kompakt und zentriert definiert.')
assert(builder.includes('width=Cm(1.8)') && builder.includes('[13.0, 3.2]'), 'Zahlungs-QR ist nicht kompakt definiert.')
assert(builder.includes('meta = doc.add_table(rows=6, cols=3)') && builder.includes('[7.4, 3.2, 5.6]'), 'Kompakter Rechnungskopf mit Empfängeranschrift links fehlt.')

const qa = read('tests/template-visual-qa.py')
for (const marker of ['GROUP_LABELS', 'GROUP_TOTALS', 'TAX_ROWS', 'without-prior-invoice', 'remove_empty_invoice_summary_rows']) {
	assert(qa.includes(marker), `Visueller Rechnungstest fehlt: ${marker}`)
}

assert(fs.existsSync(path.join(root, 'resources/templates/RECHNUNG.docx')), 'Rechnungsvorlage fehlt.')
assert(read('docs/OP-LISTE.md').includes('OP-048 – Pflegbare Überschriften der Rechnungsblöcke'), 'Customizing-Folgepunkt fehlt.')
console.log('Rechnungsvorlage Leistungs-/Steuerblöcke: statischer Vertrag OK')
