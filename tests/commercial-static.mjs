import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }

const migration = read('lib/Migration/Version1800Date20260906000000.php')
for (const table of ['bestatter_commercial_docs', 'bestatter_invoices', 'bestatter_invoice_items', 'bestatter_audit_log']) {
	assert(migration.includes(`'${table}'`), `Migrationstabelle fehlt: ${table}`)
}
for (const column of ['service_status', 'ordered_quantity_milli', 'performed_quantity_milli', 'invoiced_quantity_milli', 'billability', 'classification_reason']) {
	assert(migration.includes(`'${column}'`), `Leistungslifecycle-Spalte fehlt: ${column}`)
}

const routes = read('appinfo/routes.php')
for (const route of ['commercialOverview', 'freezeQuote', 'freezeOrder', 'convertQuoteToOrder', 'updateServiceLifecycle', 'billingCheck', 'createInvoice', 'transitionInvoice', 'invoicePaymentData', 'generateInvoiceDocument']) {
	assert(routes.includes(`commercialApi#${route}`), `Commercial-API fehlt: ${route}`)
}

const service = read('lib/Service/CommercialService.php')
for (const rule of ['ORDER_MISSING', 'PERFORMANCE_MISSING', 'STATUS_NOT_BILLABLE', 'QUANTITY_VARIANCE', 'CLASSIFICATION_OPEN', 'REASON_MISSING', 'OVERBILLED', 'SEPA_MANDATE_INCOMPLETE']) {
	assert(service.includes(`'${rule}'`), `Abrechnungsregel fehlt: ${rule}`)
}
assert(service.includes("'QUOTE'"), 'KVA-Versionierung fehlt.')
assert(service.includes("'ORDER'"), 'Auftragsversionierung fehlt.')
assert(service.includes('VERSION_CREATED') && service.includes('QUOTE_CONVERTED'), 'Audit-Aktionen für KVA/Auftrag fehlen.')
assert(service.includes('Kritische Abrechnungsfehler verhindern die Rechnung'), 'Kritische Prüfung blockiert Rechnung nicht.')

const documents = read('lib/Service/DocumentService.php')
const euroOffice = read('lib/Service/EuroOfficeConversionService.php')
assert(documents.includes('IConversionManager'), 'Öffentliche Nextcloud-Konvertierungs-API wird nicht verwendet.')
assert(documents.includes("convert($docx, 'application/pdf')"), 'PDF-Konvertierung ist nicht an Nextcloud ConversionManager angebunden.')
assert(documents.includes("$this->euroOffice->convert($docx)"), 'EuroOffice-Fallback für PDF-Konvertierung fehlt.')
assert(documents.includes("['PRUEFUNG', 'FREIGEGEBEN', 'VERSENDET', 'TEILBEZAHLT', 'BEZAHLT']"), 'Geprüfte und freigegebene Rechnungen werden nicht als FINAL ausgegeben.')
assert(documents.includes("$pdf = $target->newFile($name)"), 'Konvertiertes PDF wird nicht im versionierten Fallordner gespeichert.')
assert(euroOffice.includes("eurooffice.callback.download"), 'EuroOffice-Downloadroute wird nicht verwendet.')
assert(euroOffice.includes("getConvertedUri($downloadUrl, 'docx', 'pdf'"), 'EuroOffice-Converter wird nicht für DOCX nach PDF aufgerufen.')
assert(euroOffice.includes('$docx->getOwner()'), 'EuroOffice-Downloadtoken berücksichtigt den tatsächlichen Dateieigentümer nicht.')
assert(euroOffice.includes("'userId' => $downloadUserId"), 'EuroOffice-Downloadtoken verwendet nicht die aufgelöste Eigentümer-ID.')
assert(!euroOffice.includes('jwt_secret'), 'Bestatter-App darf den EuroOffice-JWT-Schlüssel nicht selbst lesen oder speichern.')
assert(!documents.includes('OCA\\Richdocuments\\Service\\RemoteService'), 'Private Richdocuments-Schnittstelle ist noch enthalten.')
assert(fs.existsSync(path.join(root, 'resources/templates/RECHNUNG.docx')), 'Rechnungsvorlage fehlt.')

const ui = ['src/main.js', 'src/modules/cases.js', 'src/modules/records.js', 'src/modules/commercial.js', 'src/modules/documents.js', 'src/modules/administration.js', 'src/modules/customizing.js'].map(read).join('\n')
for (const marker of ['KVA-Version festschreiben', 'Auftrag verbindlich festschreiben', 'In Auftrag übernehmen', 'Abrechnungssicherheitsprüfung', 'Teilrechnung auswählen', 'Prüfdokument erzeugen']) {
	assert(ui.includes(marker), `Kaufmännische Bedienoberfläche fehlt: ${marker}`)
}
assert(!ui.includes('sendMail') && !ui.includes('mail/send'), 'Release darf keinen E-Mail-Versand aktivieren.')

const articles = read('lib/Service/ArticleService.php')
assert(articles.includes("=== 'INCOMING_INVOICE') continue"), 'Eingangsrechnungs-Zusatzleistungen sind nicht vor der Auftrags-Synchronisation geschützt.')

assert(documents.includes('SERVER_RENDERED_PNG') && documents.includes('SUPPRESSED_FOR_DIRECT_DEBIT'), 'Serverseitige Zahlungs-QR/Lastschrift-Ausgabelogik fehlt.')
assert(!documents.includes('LEGACY_INVOICE_TEMPLATE_HASHES') && documents.includes('A file in the configured Nextcloud template directory always wins.'), 'Kundeneigene Rechnungsvorlagen sind nicht ausnahmslos vor automatischem Überschreiben geschützt.')
assert(ui.includes('SEPA-Mandatsreferenz') && ui.includes('SERVER_RENDERED_PNG'), 'SEPA-Zahlungsart oder serverseitige QR-Erzeugung fehlt.')

console.log('Commercial Process 0.39.2: statischer Vertrag OK')
