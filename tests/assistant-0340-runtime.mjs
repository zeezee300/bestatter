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
	assistantSidebar: { open: true, command: 'Standesamt suchen', caseId: 1, preview: null },
	assistantCapture: null,
	currentCase: { id: 1 }, cases: [{ id: 1, caseNumber: '2026-0001', firstName: 'Maria', lastName: 'Muster' }],
	branches: [], team: { currentUid: 'Bestatter-User1' },
}
const noop = () => {}
const module = createAssistantModule({ state, esc: (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('"', '&quot;'), api: async () => ({}), render: noop, notifyError: noop, notifySuccess: noop, notifyWarning: noop, confirmAction: async () => true, createToken: () => 'token', apiBase: '/api', urls: { cases: '/cases' } })
module.initializeAssistantCapture()

state.assistantSidebar.preview = {
	intent: 'RESEARCH_ORGANIZATION', label: 'Kontakt vorbereiten', writeOperation: true,
	answer: { title: 'Rechercheergebnisse', text: 'Bitte prüfen.', items: [] },
	choices: [{ label: 'Standesamt Hamburg', description: 'Hamburg', confirmationToken: 'signed-token', data: { sourceName: 'OpenStreetMap/Nominatim', sourceUrl: 'https://www.openstreetmap.org/node/1', retrievedAt: '2026-08-30', needsOfficialVerification: true, duplicates: [] } }],
}
let html = module.assistantSidebarView()
for (const marker of ['Standesamt Hamburg', 'Geprüft ins Adressbuch übernehmen', 'offiziellen Behördenwebsite']) if (!html.includes(marker)) throw new Error(`Rechercheansicht fehlt: ${marker}`)

state.assistantSidebar.preview = { intent: 'OPEN_EXISTING_DOCUMENTS', label: 'Dokumente', writeOperation: false, answer: { title: 'Kondolenzlisten sind vorhanden', text: 'Öffnen.', items: [] }, documents: [{ title: 'Kondolenzliste', path: '05 Trauerdruck/test.pdf', pdfFileId: 42, previewFileId: 42 }] }
html = module.assistantSidebarView()
for (const marker of ['Kondolenzliste', 'PDF öffnen / drucken', 'Browser-Druckdialog']) if (!html.includes(marker)) throw new Error(`Dokumentansicht fehlt: ${marker}`)

console.log('0.34.0 assistant result rendering passed')
