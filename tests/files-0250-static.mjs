import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { readApiControllers } from './helpers/controller-source.mjs'

const base = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(base, file), 'utf8')
const service = read('lib/Service/CaseFileService.php')
const folders = read('lib/Service/FolderService.php')
const controller = readApiControllers(base)
const routes = read('appinfo/routes.php')
const deregistration = read('lib/Service/DeregistrationService.php')
const documents = read('src/modules/documents.js')
const records = read('src/modules/records.js')
const eInvoice = read('lib/Service/EInvoiceService.php')
const documentService = read('lib/Service/DocumentService.php')

assert(routes.includes("'/api/cases/{caseId}/files'"), 'Fallakten-Dateiroute fehlt.')
assert(routes.includes("'/api/files'"), 'Fallübergreifende Dateiroute für das Hauptmenü fehlt.')
assert(controller.includes('uploadCaseFile') && controller.includes("getUploadedFile('file')"), 'Upload-Endpunkt fehlt.')
assert(service.includes('MAX_UPLOAD_BYTES') && service.includes('BLOCKED_EXTENSIONS'), 'Uploadgrenzen oder Dateitypschutz fehlen.')
assert(service.includes('getDirectoryListing') && service.includes("'source' => (string)"), 'Direkte Nextcloud-Dateien werden nicht inventarisiert.')
assert(service.includes('listAll') && documents.includes('Dateien in der Nextcloud-Fallakte'), 'Hauptmenü und Fallakte verwenden keine gemeinsame Dateiquelle.')
assert(records.includes('state.allCaseFiles') && records.includes('knownFileIds'), 'Direkte Dateien werden im Dokument-Hauptmenü nicht dedupliziert ergänzt.')
assert(service.includes('uniqueName') && !service.includes('overwrite'), 'Kollisionsfreie Dateinamen fehlen.')
assert(folders.includes('installationConfig->caseSubfolders()'), 'Konfigurierbare kanonische Fallordner fehlen.')
assert(deregistration.includes('attachmentFiles') && deregistration.includes("$item['fileId']"), 'Abmeldungen verwenden keine echten Fallakten-Dateien.')
assert(documents.includes('deregistration-local-files') && documents.includes('case-document-files'), 'Lokale Dateiauswahl fehlt.')
assert(documents.includes('direkt in Nextcloud abgelegt'), 'Direkt abgelegte Nextcloud-Dateien sind nicht gekennzeichnet.')
assert(eInvoice.includes('validateXml') && documentService.includes('STRUCTURE_VALIDATED'), 'Interne E-Rechnungsprüfung fehlt.')
assert(documentService.includes('normative') && documentService.includes('EN-16931') && documentService.includes('PDF/A-3'), 'Compliance-Abgrenzung fehlt.')

console.log('0.25 static file and invoice checks passed')
