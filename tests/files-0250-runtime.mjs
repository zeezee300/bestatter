import assert from 'node:assert/strict'
import path from 'node:path'
import { pathToFileURL } from 'node:url'
import { Window } from 'happy-dom'

const base = path.resolve(process.argv[2] || '.')
const window = new Window({ url: 'https://nextcloud.test/apps/bestatter/' })
globalThis.window = window
globalThis.document = window.document
globalThis.localStorage = window.localStorage
globalThis.OC = { generateUrl: (value) => value }
const root = document.createElement('main'); document.body.appendChild(root)
const { createDocumentsModule } = await import(pathToFileURL(path.join(base, 'src/modules/documents.js')))
const state = {
	currentCase: { id: 6, caseNumber: '2026-0006', masterData: {} },
	documentTemplates: [], deregistrationTemplates: [{ key: 'TEST', name: 'Test', active: true, formType: 'STANDARD', contactCategory: '', deliveryChannels: [], subjectTemplate: '', bodyTemplate: '' }],
	caseFiles: { folders: ['01 Stammdaten', '03 Behörden', '06 Abrechnung'], documentTypes: ['Urkunde', 'Sonstiges'], files: [{ fileId: 395, title: 'Sterbeurkunde', documentType: 'Urkunde', status: 'ABLAGE', source: 'NEXTCLOUD', relativePath: '03 Behörden/Sterbeurkunde.pdf', subfolder: '03 Behörden', extension: 'pdf', readOnly: false }] },
	records: { document: [], contact: [], deregistration: [] },
}
const noop = () => ''
const module = createDocumentsModule({ root, apiBase: '/api', state, api: async () => ({}), caseOverview: noop, checklistPanel: noop, contactsByCategory: () => [], deregistrationUrl: () => '/api/dereg', documentOutputRow: noop, documentUrl: noop, esc: (value) => String(value ?? ''), field: noop, financesPanel: noop, loadRecordType: async () => {}, loadCaseFiles: async () => {}, uploadCaseFile: async () => ({}), masterDataForm: noop, orderPanel: noop, overview: noop, recordItemUrl: noop, recordUrl: noop, recordsView: noop, render: noop, serviceSelectionPanel: noop, title: noop })

root.innerHTML = module.documentPanel()
assert(root.querySelector('#case-document-files')?.multiple, 'Dokumenten-Mehrfachauswahl fehlt.')
assert(root.textContent.includes('direkt in Nextcloud abgelegt'), 'Direkte Nextcloud-Datei wird nicht angezeigt.')
assert(root.textContent.includes('Sterbeurkunde'), 'Datei aus Fallakte fehlt.')

module.showDeregistrationForm()
assert(root.querySelector('#deregistration-local-files')?.multiple, 'Mehrfachauswahl für Abmeldungsanlagen fehlt.')
const selected = root.querySelector('input[name="attachmentIds"][value="395"]')
assert(selected?.checked, 'Vorhandene Sterbeurkunde wird nicht vorausgewählt.')

console.log('0.25 runtime document and attachment checks passed')
