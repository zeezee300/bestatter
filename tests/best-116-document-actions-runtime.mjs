import { Window } from 'happy-dom'
import { pathToFileURL } from 'node:url'
import path from 'node:path'
import assert from 'node:assert/strict'

const rootPath = path.resolve(process.argv[2] || '.')
const window = new Window({ url: 'https://test.example/apps/bestatter/' })
Object.assign(globalThis, { window, document: window.document, HTMLElement: window.HTMLElement, File: window.File })
Object.defineProperty(globalThis, 'navigator', { value: window.navigator, configurable: true })
globalThis.OC = { generateUrl: (url) => url, requestToken: 'csrf-test-token' }

const { createRecordsModule } = await import(`${pathToFileURL(path.join(rootPath, 'src', 'modules', 'records.js')).href}?best116=${Date.now()}`)
const { documentMailto, prepareDocumentMail } = await import(`${pathToFileURL(path.join(rootPath, 'src', 'modules', 'document-actions.js')).href}?best116=${Date.now()}`)
const state = { cases: [], records: {} }
const module = createRecordsModule({
	root: window.document.body,
	apiBase: '/api',
	urls: {},
	state,
	recordLabels: {},
	esc: (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;').replaceAll('>', '&gt;'),
	recordCaseNumber: (item) => item.caseNumber || '',
})

const base = { id: 7, caseId: 99, caseNumber: '2026-0099', title: 'Auftrag & Vollmacht', date: '2026-09-15T10:00:00Z', status: 'FINAL' }
const pdf = module.documentOutputRow({ ...base, data: { fileId: 40, extension: 'DOCX', pdf: { fileId: 41 }, path: '04 Auftrag/Auftrag.docx' } })
assert(pdf.includes('print-nextcloud-pdf') && pdf.includes('data-file-id="41"'), 'Die PDF-Ausgabe muss direkt druckbar sein.')
assert(pdf.includes('data-print-url="/api/cases/99/files/41/content.pdf"'), 'Der Druck muss den geschützten PDF-Datenstrom verwenden.')
assert(pdf.includes('mail-nextcloud-file') && pdf.includes('data-file-id="41"'), 'Für die finale Ausgabe muss die PDF per Mail vorbereitet werden.')
assert(pdf.includes('Mit Mailprogramm teilen') && pdf.includes('bp-action-icon'), 'Aktionen müssen sichtbar beschriftet und mit Symbol versehen sein.')
assert(pdf.includes('data-share-url="/api/cases/99/files/41/content"') && pdf.includes('data-file-name='), 'Die fallgebundene Dateiübergabe ist nicht vorbereitet.')

const docx = module.documentOutputRow({ ...base, status: 'ENTWURF', data: { fileId: 50, extension: 'DOCX', path: '04 Auftrag/Entwurf.docx' } })
assert(!docx.includes('print-nextcloud-pdf'), 'Ein reiner DOCX-Entwurf darf keinen direkten Druck anbieten.')
assert(docx.includes('mail-nextcloud-file') && docx.includes('data-file-id="50"'), 'Ein DOCX-Entwurf muss für die manuelle Mailanlage geöffnet werden können.')

const directPdf = module.documentOutputRow({ ...base, data: { fileId: 60, mimeType: 'application/pdf', path: 'Sonstiges/Nachweis' } })
assert(directPdf.includes('print-nextcloud-pdf'), 'Eine direkt abgelegte PDF-Datei muss druckbar sein.')

assert.equal(documentMailto(), 'mailto:', 'Empfänger, Betreff und Text müssen im Rückfall vollständig leer bleiben.')

let sharedData = null
window.navigator.canShare = (data) => data.files?.length === 1
window.navigator.share = async (data) => { sharedData = data }
let fetchOptions = null
globalThis.fetch = async (_url, options) => { fetchOptions = options; return new window.Response(new window.Blob(['PDF'], { type: 'application/pdf' }), { status: 200 }) }
const button = window.document.createElement('button')
Object.assign(button.dataset, { fileId: '41', shareUrl: '/api/cases/99/files/41/content', fileName: 'Auftrag.pdf', mimeType: 'application/pdf' })
let success = ''
await prepareDocumentMail(button, () => assert.fail('Unterstützte Dateifreigabe darf nicht in den mailto-Rückfall wechseln.'), (message) => { success = message })
assert.equal(sharedData.files[0].name, 'Auftrag.pdf')
assert.equal(sharedData.files[0].type, 'application/pdf')
assert.equal(fetchOptions.headers.requesttoken, 'csrf-test-token', 'Der geschützte Dateiabruf benötigt das Nextcloud-Requesttoken.')
assert.equal(Object.keys(sharedData).join(','), 'files', 'Mailfelder dürfen nicht an den Systemdialog übergeben werden.')
assert(success.includes('kein Versand') || success.includes('nicht protokolliert'))

window.happyDOM.abort()
console.log('BEST-116/0.60.2: Dokumentaktionen und lokale Dateiübergabe im DOM-Laufzeittest OK')
