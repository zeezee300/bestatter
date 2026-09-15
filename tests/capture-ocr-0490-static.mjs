import assert from 'node:assert/strict'
import fs from 'node:fs'

const read = (path) => fs.readFileSync(path, 'utf8')
assert(read('appinfo/info.xml').includes('<version>0.59.0</version>'))
const migration=read('lib/Migration/Version1800Date20260906000000.php')
const service=read('lib/Service/CaptureImportService.php')
const paperless=read('lib/Service/PaperlessService.php')
const assistant=read('src/modules/assistant.js')
for(const marker of ['bestatter_capture_imports','document_sha256','suggestions_json','reviewed_at'])assert(migration.includes(marker),`Migration fehlt: ${marker}`)
for(const marker of ['%PDF-','MANUAL_REVIEW','CONFIRMED_AND_FILED','defaultUploadFolder','ocr_text'])assert(service.includes(marker),`Importservice fehlt: ${marker}`)
for(const marker of ['CASE_CAPTURE_FORM','captureDocumentTypeId','captureTagIds',"analyze($ocr,'OCR_FORM')"])assert(paperless.includes(marker),`Paperless-Trennung fehlt: ${marker}`)
for(const marker of ['capture-scan-file','bp-capture-split','Originalscan','confirmedFields','OCR erneut versuchen'])assert(assistant.includes(marker),`Schnellerfassungs-UI fehlt: ${marker}`)
for(const path of ['docs/AUFTRAGSSCHNELLERFASSUNG-OCR.md','docs/ENTWICKLUNG.md','docs/UPGRADE.md'])assert(fs.existsSync(path),`Dokumentation fehlt: ${path}`)
console.log('0.49 OCR-Auftragsschnellerfassung statisch geprüft.')
