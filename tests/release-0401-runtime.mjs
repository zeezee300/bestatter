import { Window } from 'happy-dom'
import { createAssistantModule } from '../src/modules/assistant.js'

const window = new Window({ url: 'https://example.test/apps/bestatter/' })
globalThis.window = window
globalThis.document = window.document
globalThis.localStorage = window.localStorage
globalThis.FormData = window.FormData
globalThis.OC = { generateUrl: (path) => path }

const calls = []
const state = {
	view: 'dashboard', currentCase: null,
	cases: [{ id: 7, caseNumber: '2026-0042', firstName: 'Maria Magdalena', lastName: 'Mustermann-Langname' }],
	assistantConfiguration: { assistantEnabled: true, providers: { textToText: false } },
	assistantSidebar: { open: true, command: '', caseId: 0, preview: null },
}
const assistant = createAssistantModule({
	state, apiBase: '/apps/bestatter/api', urls: { cases: '/apps/bestatter/api/cases' },
	esc: (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('"', '&quot;'),
	api: async (url, options) => { calls.push([url, options]); return { intent: 'INFORMATION', label: 'Test', answer: { title: 'Test', text: 'OK', items: [] } } },
	render: () => {}, notifyError: () => {}, notifySuccess: () => {}, notifyWarning: () => {}, confirmAction: async () => true,
})

document.body.innerHTML = assistant.assistantSidebarView()
assistant.bindAssistant()
const select = document.querySelector('select[name="caseId"]')
select.value = '7'
select.dispatchEvent(new window.Event('change', { bubbles: true }))
if (state.assistantSidebar.caseId !== 7 || localStorage.getItem('bestatter-assistant-case-id') !== '7') throw new Error('Fallbezug wurde nicht unmittelbar gespeichert')
if (!document.querySelector('.bp-assistant-case-field small')?.textContent.includes('Mustermann-Langname')) throw new Error('Vollständiger Fallbezug ist nicht sichtbar')

const textarea = document.querySelector('textarea[name="input"]')
textarea.value = 'Welche Angaben fehlen?'
textarea.dispatchEvent(new window.Event('input', { bubbles: true }))
textarea.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
await new Promise((resolve) => setTimeout(resolve, 0))
if (calls.length !== 1 || !String(calls[0][1].body).includes('caseId=7')) throw new Error('Enter hat die fallbezogene Prüfung nicht ausgelöst')
textarea.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Enter', shiftKey: true, bubbles: true }))
await new Promise((resolve) => setTimeout(resolve, 0))
if (calls.length !== 1) throw new Error('Umschalt+Enter darf die Prüfung nicht auslösen')

console.log('0.40.1 Assistent: Enter, sichtbarer und persistenter Fallbezug im Laufzeittest OK')
