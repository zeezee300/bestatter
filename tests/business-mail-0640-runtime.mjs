import assert from 'node:assert/strict'
import { Window } from 'happy-dom'
import { createDocumentsModule } from '../src/modules/documents.js'

const window = new Window({ url: 'https://test.example/apps/bestatter/' })
Object.assign(globalThis, { window, document: window.document, FormData: window.FormData })
globalThis.OC = { generateUrl: (url) => url, requestToken: 'csrf-test' }
const root = document.createElement('div'); document.body.append(root)
const state = {
	currentCase: { id: 9, caseNumber: '2026-0009' },
	records: { document: [{ id: 17, caseId: 9, title: 'Bestattungsauftrag', date: '2026-09-17', status: 'FINAL', data: { pdf: { fileId: 42, fileName: 'Auftrag.pdf' }, templateKey: 'BESTATTUNGSAUFTRAG' } }] },
	documentTemplates: [], caseFiles: { folders: [], documentTypes: [], files: [] },
	businessMailAvailability: { enabled: false, sender: '', reason: 'Noch nicht freigeschaltet.' },
	businessMailHistory: [], businessMailCaseId: 9,
}
let sent = null; let rendered = 0; let success = ''
const module = createDocumentsModule({
	root, state, apiBase: '/api', esc: (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;'),
	documentOutputRow: () => '', api: async (url, options = {}) => {
		if (url.endsWith('/preview')) return { title: 'Bestattungsauftrag', caseNumber: '2026-0009', sender: 'buero@example.test', replyTo: 'bea@example.test', fileName: 'Auftrag.pdf', fileSize: 1000, fileId: 42, fileSha256: 'a'.repeat(64), enabled: true }
		if (url.endsWith('/send')) { sent = JSON.parse(options.body.get('mail')); return { id: 4, status: 'ACCEPTED' } }
		if (url.endsWith('/business-mail')) return [{ id: 4, recordId: 17, recipient: 'kunde@example.test', status: 'ACCEPTED', fileName: 'Auftrag.pdf', subject: 'Auftrag', createdAt: '2026-09-17', createdBy: 'admin', replyTo: 'bea@example.test' }]
		throw new Error(`Unexpected URL: ${url}`)
	}, render: () => { rendered++ }, notifySuccess: (message) => { success = message }, notifyWarning: (message) => { throw new Error(message) },
})

root.innerHTML = module.documentPanel()
assert(root.querySelector('.business-mail-send')?.disabled, 'Ohne Freigabe muss der Versand gesperrt sein.')
state.businessMailAvailability = { enabled: true, sender: 'buero@example.test', replyTo: 'bea@example.test', reason: '' }
root.innerHTML = module.documentPanel()
assert(!root.querySelector('.business-mail-send')?.disabled, 'Nach Freigabe soll der Versand angeboten werden.')
assert.match(root.textContent, /Antworten an: bea@example\.test/)
await module.showBusinessMailDialog(17)
const form = root.querySelector('#business-mail-form')
assert(form, 'Die Versandvorschau muss geöffnet werden.')
assert.match(form.textContent, /Antworten an: bea@example\.test/)
form.elements.recipient.value = 'kunde@example.test'
form.elements.body.value = 'Guten Tag, anbei der Auftrag.'
form.elements.confirmed.checked = true
await form.onsubmit({ preventDefault() {} })
assert.equal(sent.recipient, 'kunde@example.test')
assert.equal(sent.fileSha256, 'a'.repeat(64))
assert.match(sent.requestKey, /^[a-f0-9-]{36}$/)
assert.equal(rendered, 1)
assert.match(success, /Mailserver angenommen/)
state.businessMailAvailability = { enabled: true, sender: 'buero@example.test', replyTo: '', reason: '' }
state.businessMailHistory = []
root.innerHTML = module.documentPanel()
assert.doesNotMatch(root.textContent, /Antworten an:/, 'Ohne gültige Profiladresse darf kein Reply-To-Hinweis erscheinen.')
console.log('OP-002 Stufe 1: gesperrte/freigeschaltete Ansicht und expliziter PDF-Versand im DOM-Test OK.')
