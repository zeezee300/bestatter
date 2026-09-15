import assert from 'node:assert/strict'
import { Window } from 'happy-dom'
import { pathToFileURL } from 'node:url'
import path from 'node:path'

const rootPath = path.resolve(process.argv[2] || '.')
const window = new Window({ url: 'https://test.example/apps/bestatter/' })
Object.assign(globalThis, { window, document: window.document, localStorage: window.localStorage, FormData: window.FormData, URLSearchParams: window.URLSearchParams })
const root = document.createElement('div')
document.body.append(root)
const baseAction = { key: 'AKTION_1', type: 'TASK', label: 'Aufgabe anlegen', title: 'Titel', description: 'Beschreibung', dueOffsetDays: 1, priority: 'NORMAL', templateKey: '', templateKeys: [], sourceStatusAfter: '', caseStatusAfter: '', recipientCategory: '', repeatable: false }
const workflows = [
	{ id: 1, key: 'TEST_1', name: 'Erster Workflow', description: 'Beschreibung eins', triggerType: 'TASK', triggerEvent: 'MANUAL', triggerConfig: { schedulePresetKeys: [] }, taskTitlePattern: '', checklistKey: '', requiredStatus: 'ANY', active: true, sortOrder: 10, actions: [baseAction] },
	{ id: 2, key: 'TEST_2', name: 'Zweiter Workflow', description: 'Beschreibung zwei', triggerType: 'SCHEDULE', triggerEvent: 'CONFIRMED', triggerConfig: { schedulePresetKeys: ['TERMIN'] }, taskTitlePattern: '', checklistKey: '', requiredStatus: 'ANY', active: true, sortOrder: 20, actions: [{ ...baseAction, key: 'AKTION_2' }] },
]
const state = { workflowAdmin: structuredClone(workflows), workflowAdminId: 1, documentTemplates: [], checklists: [], customizing: [], checklistAdmin: [], documentTemplateOptions: {}, deregistrationTemplates: [] }
let savedPayload = null
let renderCalls = 0
const { createCustomizingModule } = await import(`${pathToFileURL(path.join(rootPath, 'src', 'modules', 'customizing.js')).href}?release0412=${Date.now()}`)
const module = createCustomizingModule({
	root, state, urls: { customizing: '/api/customizing' }, apiBase: '/api',
	esc: (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;'),
	api: async (url, options = {}) => {
		if (url === '/api/workflows/1' && options.method === 'PUT') { savedPayload = JSON.parse(options.body.get('workflow')); return { ...workflows[0], ...savedPayload, id: 1 } }
		if (url === '/api/workflows') return structuredClone(workflows)
		throw new Error(`Unerwarteter API-Aufruf: ${url}`)
	},
	workflowUrl: () => '/api/workflows', checklistUrl: () => '/api/checklists', checklistManageUrl: () => '/api/checklists/manage',
	render: () => { renderCalls++ }, confirmAction: async () => true, promptAction: async () => '', notifySuccess: () => {}, notifyError: (message) => { throw new Error(message) },
	branchUrl: () => '', deliveryChannels: () => [], deregistrationTemplateUrl: () => '', documentTemplateUrl: () => '', documentTemplateOptionsUrl: () => '', field: () => '', invoiceSettingsUrl: () => '', showTextPreview: () => {}, title: () => '',
})

root.innerHTML = module.workflowAdminView()
assert.equal(root.querySelectorAll('[data-workflow-order-row]').length, 2)
assert.equal(root.querySelectorAll('[data-workflow-action-row]').length, 1)
assert.match(root.textContent, /Inhalt und Logik/)
module.bindWorkflowAdmin()
root.querySelector('#add-workflow-action').click()
assert.equal(root.querySelectorAll('[data-workflow-action-row]').length, 2)
assert.ok(root.querySelector('#workflow-maintenance-form').classList.contains('is-dirty'))
root.querySelector('#workflow-maintenance-form').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }))
await new Promise((resolve) => setTimeout(resolve, 10))
assert.equal(savedPayload.actions.length, 2)
assert.equal(savedPayload.actions[0].description, 'Beschreibung')
assert.equal(renderCalls, 1)

window.happyDOM.abort()
console.log('0.41.2 tabellarische Workflow-Pflege speichert alle fachlichen Aktionsfelder.')
