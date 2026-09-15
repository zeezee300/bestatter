import { Window } from 'happy-dom'
import { createAssistantModule } from '../src/modules/assistant.js'

const window = new Window({ url: 'https://example.test/apps/bestatter/' })
globalThis.window = window
globalThis.document = window.document
globalThis.localStorage = window.localStorage
globalThis.HTMLElement = window.HTMLElement
globalThis.OC = { generateUrl: (value) => value, getCurrentUser: () => ({ uid: 'Bestatter-User1' }) }

const state = {
	assistantConfiguration: { assistantEnabled: true, providers: {} },
	assistantSidebar: { open: true, command: 'Dokument erzeugen', caseId: 1, preview: null },
	assistantCapture: null, currentCase: { id: 1 },
	cases: [{ id: 1, caseNumber: '2026-0001', firstName: 'Maria', lastName: 'Muster' }], branches: [], team: { currentUid: 'Bestatter-User1' },
}
const noop = () => {}
const module = createAssistantModule({ state, esc: (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('"', '&quot;'), api: async () => ({}), render: noop, notifyError: noop, notifySuccess: noop, notifyWarning: noop, confirmAction: async () => true, createToken: () => 'token', apiBase: '/api', urls: { cases: '/cases' } })
module.initializeAssistantCapture()
state.assistantSidebar.preview = {
	intent: 'PREPARE_DOCUMENT_OUTPUT', label: 'Dokumentausgabe', writeOperation: true,
	answer: { title: 'Dokumentvorlage auswählen', text: 'Bitte auswählen.', items: [] },
	choices: [
		{ kind: 'document', label: 'Bestattungsvollmacht', description: 'Auftrag · 02 Auftrag', actionLabel: 'Geprüft erzeugen', confirmationToken: 'doc-token', documents: [] },
		{ kind: 'document', label: 'Sterbefallanzeige', description: 'Behörde · 03 Behörden', actionLabel: 'Aktuelle Ausgabe öffnen', confirmationToken: '', documents: [{ title: 'Sterbefallanzeige', path: '03 Behörden/test.pdf', pdfFileId: 21, previewFileId: 21 }] },
	],
}
const html = module.assistantSidebarView()
for (const marker of ['Bestattungsvollmacht', 'Geprüft erzeugen', 'Sterbefallanzeige', 'PDF öffnen / drucken', 'Dokument ausgeben']) if (!html.includes(marker)) throw new Error(`Allgemeine Dokumentauswahl fehlt: ${marker}`)

console.log('0.34.1 generic document assistant rendering passed')
