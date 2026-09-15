import { readFileSync } from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => readFileSync(path.join(root, file), 'utf8')
const expect = (condition, message) => { if (!condition) throw new Error(message) }
const documents = read('lib/Service/DocumentService.php')
const records = read('lib/Service/RecordService.php')
const controller = read('lib/Controller/CommercialApiController.php')
const frontend = read('src/main.js')

expect(documents.includes('$this->eInvoices->epcQrImage'), 'EPC-Zahlcode wird nicht serverseitig erzeugt')
expect(documents.includes('$paymentQrPlaceholderFound') && documents.includes('keinen kompatiblen QR-Code-Platzhalter'), 'Rechnungsvorlage wird nicht auf den QR-Platzhalter geprüft')
expect(records.includes('bool $allowInvoiceReplacement = false') && records.includes('bool $allowImmutableDocument = false'), 'Kontrollierte Prüfdokument-Neuerzeugung fehlt')
expect(controller.includes("$invoice['status'] !== 'PRUEFUNG'") && controller.includes("saveDocument($invoice['caseId'], $file['title'], $file['status'], $file + ['invoiceId' => $id], true)"), 'Neuerzeugung ist nicht sicher auf den Prüfstatus begrenzt')
expect(!frontend.includes('paymentQrPng') && !frontend.includes('QRCode.toDataURL'), 'Veraltete clientseitige QR-Erzeugung ist noch enthalten')

console.log('0.40.2 Zahlungs-QR, Vorlagenprüfung und sichere Neuerzeugung statisch geprüft.')
