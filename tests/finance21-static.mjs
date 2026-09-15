import fs from 'node:fs'

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }

const article = read('lib/Service/ArticleService.php')
const commercial = read('lib/Service/CommercialService.php')
const document = read('lib/Service/DocumentService.php')
const einvoice = read('lib/Service/EInvoiceService.php')
const migration = read('lib/Migration/Version1800Date20260906000000.php')
const ui = ['src/main.js', 'src/modules/cases.js', 'src/modules/records.js', 'src/modules/commercial.js', 'src/modules/documents.js', 'src/modules/administration.js', 'src/modules/customizing.js'].map(read).join('\n')

assert(migration.includes("quantity_decimals") && migration.includes("bestatter_invoice_items"), 'Migration für Einheit und Mengenpräzision fehlt.')
assert(article.includes("performanceTotals") && article.includes("invoicedTotals") && article.includes("outstandingTotals"), 'Leistungs- und Abrechnungssummen fehlen.')
assert(article.includes("performedNetCents") && article.includes("performedGrossCents"), 'Einzelbeträge der erbrachten Leistung fehlen.')
assert(commercial.includes("$item['performedQuantityMilli'] > $item['invoicedQuantityMilli']"), 'Rechnungsauswahl muss auf erbrachter Restmenge basieren.')
assert(!commercial.includes("max($service['performedQuantityMilli'], $service['orderedQuantityMilli'])"), 'Ungeprüfte Auftragsmenge darf nicht fakturiert werden.')
assert(commercial.includes('validateQuantityPrecision') && commercial.includes('PRICE_MISSING'), 'Mengen- und Preisprüfung fehlen.')
assert(einvoice.includes('unitCode') && einvoice.includes("'HUR'") && einvoice.includes("'KMT'"), 'E-Rechnung bildet Mengeneinheiten nicht ab.')
assert(document.includes('unitLabel') && document.includes("'unit' =>"), 'Rechnungsdokument zeigt keine Mengeneinheit.')
assert(ui.includes('Menge (') && ui.includes('Einzel netto') && ui.includes('Erbracht brutto') && ui.includes('Noch abrechenbar'), 'Leistungserfassung zeigt Mengenlogik, Einzelbeträge oder Summen nicht vollständig an.')
assert(ui.includes('quantityDecimals') && ui.includes('Mengeneinheit'), 'Leistungskatalog kann Mengeneinheit und Präzision nicht pflegen.')

console.log('Leistungen und Finanzen 0.21.0: statischer Vertrag OK')
