import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }

assert(fs.existsSync(path.join(root, 'lib/Service/StructuredReferenceGenerator.php')), 'RF-Referenzgenerator fehlt.')
assert(fs.existsSync(path.join(root, 'lib/Service/IbanValidator.php')), 'IBAN-Prüfdienst fehlt.')

const eInvoice = read('lib/Service/EInvoiceService.php')
for (const marker of ['PngWriter', 'ErrorCorrectionLevel::Medium', "['BCD', '002', '1', 'SCT'", 'fromInvoiceNumber', 'isValid($iban)', '99_999_999_999']) {
	assert(eInvoice.includes(marker), `EPC-Implementierung fehlt: ${marker}`)
}
assert(eInvoice.includes("'', $reference]"), 'Strukturierte Referenz steht nicht im EPC-Feld 10.')

const documentService = read('lib/Service/DocumentService.php')
assert(documentService.includes('$this->eInvoices->epcQrImage'), 'Rechnungsdienst erzeugt das QR-PNG nicht selbst.')
assert(documentService.includes('SERVER_RENDERED_PNG'), 'Serverseitige QR-Ausgabe wird nicht ausgewiesen.')

const controller = read('lib/Controller/CommercialApiController.php')
assert(!controller.includes('paymentQrPng'), 'Controller nimmt weiterhin ein vom Browser geliefertes QR-Bild an.')

const frontend = read('src/main.js')
assert(!frontend.includes("from 'qrcode'"), 'Frontend importiert weiterhin die QR-Bibliothek.')
assert(!frontend.includes('QRCode.toDataURL'), 'Frontend rendert weiterhin Zahlungs-QR-Codes.')
assert(!frontend.includes('paymentQrPng'), 'Frontend überträgt weiterhin ein QR-PNG an den Server.')
assert(frontend.includes('Rechnungs-Prüfdokument und Zahlungs-QR werden erzeugt'), 'Aussagekräftiger Ladehinweis für die Dokumentkonvertierung fehlt.')

const ui = read('src/modules/ui.js')
for (const marker of ['bp-loading-progress', 'Verarbeitung läuft seit', 'Der Vorgang dauert länger']) {
	assert(ui.includes(marker), `Langzeit-Fortschrittsanzeige fehlt: ${marker}`)
}

const administration = read('src/modules/administration.js')
assert(administration.includes('Verbindliches Prüfdokument erzeugen'), 'Der unveränderliche Prüfabschluss ist nicht eindeutig bezeichnet.')

const composer = JSON.parse(read('composer.json'))
assert(composer.require['endroid/qr-code'] === '6.0.9', 'Serverseitige QR-Abhängigkeit ist nicht reproduzierbar fixiert.')
assert(composer.require['ext-gd'] === '*', 'GD-Systemvoraussetzung fehlt.')
const npm = JSON.parse(read('package.json'))
assert(!npm.dependencies?.qrcode, 'Clientseitige QR-Abhängigkeit wurde nicht entfernt.')

console.log('0.49.2 EPC-Zahlcode, RF-Referenz und serverseitige QR-Erzeugung statisch geprüft.')
