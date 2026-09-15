import { Window } from 'happy-dom'
import { createAssistantModule } from '../src/modules/assistant.js'

const window = new Window({ url: 'https://example.test/apps/bestatter/' })
globalThis.window = window
globalThis.document = window.document
globalThis.localStorage = window.localStorage
globalThis.OC = { getCurrentUser: () => ({ uid: 'Bestatter-User1' }) }

const state = {
	view: 'capture',
	team: { currentUid: 'Bestatter-User1', members: [{ uid: 'Bestatter-User1', displayName: 'Bestatter User 1' }] },
	branches: [{ key: 'STAMMHAUS', name: 'Stammhaus', active: true, memberUids: ['Bestatter-User1'] }],
	cases: [{ id: 7, caseNumber: '2026-0001', firstName: 'Maria', lastName: 'Muster' }],
	assistantConfiguration: { guidedCaptureEnabled: true, assistantEnabled: true, providers: { speechToText: false } },
	assistantCapture: null,
}
const calls = []
const ctx = {
	state,
	esc: (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;'),
	apiBase: '/apps/bestatter/api', urls: { cases: '/apps/bestatter/api/cases' },
	api: async (url, options = {}) => { calls.push([url, options]); return {} },
	render: () => {}, notifyError: () => {}, notifySuccess: () => {}, notifyWarning: () => {}, confirmAction: async () => true,
	createToken: () => 'capture-token-12345678',
}
const assistant = createAssistantModule(ctx)
assistant.initializeAssistantCapture()
if (state.assistantCapture.data.branch !== 'STAMMHAUS') throw new Error('Default branch was not resolved')
if (state.assistantCapture.data.responsible_employee !== 'Bestatter-User1') throw new Error('Default responsible employee was not resolved')
const html = assistant.guidedCaptureView()
for (const marker of ['Sterbefall schnell erfassen', 'Gesprächstext', 'Spracherkennung noch nicht eingerichtet', 'capture-speech-progress']) {
	if (!html.includes(marker)) throw new Error(`Rendered capture marker missing: ${marker}`)
}
state.assistantCapture.recording = true
const recordingHtml = assistant.guidedCaptureView()
for (const marker of ['■ Aufnahme beenden', 'Aufnahme läuft', 'aria-pressed="true"']) {
	if (!recordingHtml.includes(marker)) throw new Error(`Persistent recording marker missing: ${marker}`)
}
state.assistantCapture.recording = false
const sidebar = assistant.assistantSidebarView()
for (const marker of ['Bestatter-Assistent', '2026-0001', 'Mein Arbeitstag', 'Regelbasierter, lokaler Fallback']) {
	if (!sidebar.includes(marker)) throw new Error(`Rendered assistant sidebar marker missing: ${marker}`)
}
document.body.innerHTML = `<main id="root">${html}${sidebar}</main>`
assistant.bindAssistant()
document.getElementById('capture-transcript').value = 'Sterbefall Maria Muster, verstorben heute. Feuerbestattung.'
document.getElementById('capture-transcript').dispatchEvent(new window.Event('input'))
if (!localStorage.getItem('bestatter-assistant-capture')?.includes('Maria Muster')) throw new Error('Capture draft was not persisted')
console.log('0.32.0 runtime: capture defaults, render and local persistence passed')
