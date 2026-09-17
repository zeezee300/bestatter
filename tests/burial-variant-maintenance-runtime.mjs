import assert from 'node:assert/strict'
import { Window } from 'happy-dom'
import { createCustomizingModule } from '../src/modules/customizing.js'

const window = new Window({ url: 'https://test.example/apps/bestatter/' })
Object.assign(globalThis, { window, document: window.document, URLSearchParams: window.URLSearchParams })
const root = document.createElement('div')
document.body.append(root)
const variants = [
	{ id: 1, value: 'SEA', label: 'Seebestattung', parentItemId: null, metadata: { funeralScope: 'CREMATION' } },
	{ id: 2, value: 'SEA_NORTH', label: 'Nordsee', parentItemId: 1, metadata: { funeralScope: 'CREMATION' } },
]
const state = { customizingTab: 'value-lists', valueListKey: 'BURIAL_VARIANT', customizing: [{ key: 'BURIAL_VARIANT', name: 'Bestattungsvarianten', technicalValuesLocked: true, items: variants, itemCount: 2, tree: [] }], checklistAdmin: [], documentTemplates: [], documentTemplateOptions: {}, deregistrationTemplates: [], burialVariantRules: [], surchargeRules: [] }
let saved = null
let renderCount = 0
const module = createCustomizingModule({
	root, state, urls: { customizing: '/api/customizing' }, apiBase: '/api',
	esc: (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;'),
	api: async (url, options = {}) => {
		if (url === '/api/customizing/BURIAL_VARIANT' && options.method === 'POST') {
			saved = Object.fromEntries(options.body)
			variants.push({ id: 3, value: saved.value, label: saved.label, parentItemId: 2, metadata: { funeralScope: saved.funeralScope } })
			return variants[2]
		}
		if (url === '/api/customizing') return { lists: [{ ...state.customizing[0], items: variants, itemCount: 3 }] }
		throw new Error(`Unerwarteter API-Aufruf: ${url}`)
	},
	render: () => { renderCount++ }, notifySuccess: () => {}, notifyError: (message) => { throw new Error(message) },
	confirmAction: async () => true, promptAction: async () => '', branchUrl: () => '', checklistManageUrl: () => '', checklistUrl: () => '', deliveryChannels: () => [], deregistrationTemplateUrl: () => '', documentTemplateUrl: () => '', documentTemplateOptionsUrl: () => '', invoiceSettingsUrl: () => '', schedulingUrl: () => '', workflowUrl: () => '', showTextPreview: () => {}, title: () => '', download: () => {},
})
root.innerHTML = module.customizing()
assert.equal(root.querySelector('#add-value').disabled, false)
assert.equal(root.querySelector('[data-value-row="1"] [data-value-value]').readOnly, true)
assert.equal(root.querySelector('[data-value-row="2"] [data-value-parent]').value, 'SEA')
module.bindCustomizing()
root.querySelector('#add-value').click()
assert.equal(root.querySelectorAll('[data-value-row]').length, 3, root.innerHTML.slice(-1600))
const row = root.querySelector('[data-value-row="0"]')
row.querySelector('[data-value-value]').value = 'SEA_NORTH_SHIP_3'
row.querySelector('[data-value-label]').value = 'Mit Schiff 3'
row.querySelector('[data-value-parent]').value = 'SEA_NORTH'
row.querySelector('[data-value-scope]').value = 'CREMATION'
row.querySelector('.save-value').click()
await new Promise((resolve) => setTimeout(resolve, 10))
assert.deepEqual(saved, { value: 'SEA_NORTH_SHIP_3', label: 'Mit Schiff 3', parentValue: 'SEA_NORTH', funeralScope: 'CREMATION' })
assert.equal(renderCount, 1)
assert.equal(state.customizing[0].items[2].parentItemId, 2)
window.happyDOM.abort()
console.log('Bestattungs-Untervarianten: Pflege und API-Parameter im DOM-Laufzeittest OK')
