import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { Window } from 'happy-dom'
import { createAssistantModule } from '../src/modules/assistant.js'

const seed = JSON.parse(readFileSync(new URL('../resources/initial-customizing.json', import.meta.url), 'utf8'))
assert(!seed.lists.some((list) => list.key === 'FUNERAL_TYPE'))
const items = seed.lists.find((list) => list.key === 'BURIAL_VARIANT').items
const toTree = (parent = '') => items.filter((item) => (item.parent || '') === parent).map((item) => ({ ...item, children: toTree(item.value) }))
const window = new Window({ url: 'https://example.test/apps/bestatter/' })
Object.assign(globalThis, { window, document: window.document, localStorage: window.localStorage, FormData: window.FormData, OC: { getCurrentUser: () => ({ uid: 'tester' }) } })
const state = { view: 'capture', assistantCapture: null, assistantConfiguration: {}, customizing: [{ key: 'BURIAL_VARIANT', tree: toTree() }], branches: [], team: { currentUid: 'tester', members: [] } }
let renderCount = 0
const module = createAssistantModule({ state, esc: (value) => String(value ?? ''), api: async () => ({}), apiBase: '/api', urls: { cases: '/api/cases' }, render: () => { renderCount++; document.body.innerHTML = module.guidedCaptureView(); module.bindAssistant() }, notifyError: (message) => { throw new Error(message) }, notifySuccess: () => {}, notifyWarning: () => {}, createToken: () => 'token' })
module.initializeAssistantCapture()
state.assistantCapture.step = 2
document.body.innerHTML = module.guidedCaptureView()
module.bindAssistant()
const root = document.querySelector('[data-capture-burial-level="0"]')
assert(root.querySelector('option[value="TREE"]'))
assert(root.querySelector('option[value="FOREST"]'))
assert(!document.querySelector('[name="funeral_type"]'))
document.querySelector('[name="first_name"]').value = 'Maria'
root.value = 'TREE'
assert.equal(root.value, 'TREE')
root.dispatchEvent(new window.Event('change', { bubbles: true }))
assert.equal(renderCount, 1, 'Auswahl löst Neuaufbau aus')
assert.equal(state.assistantCapture.data.burial_variant_code, 'TREE')
assert.equal(state.assistantCapture.data.first_name, 'Maria', 'Andere Eingaben bleiben beim Wechsel erhalten')
const child = document.querySelector('[data-capture-burial-level="1"]')
assert(child.querySelector('option[value="FOREST_BASIC"]'))
assert(!child.querySelector('option[value="FOREST_ANONYMOUS"]'))
child.value = 'FOREST_BASIC'
child.dispatchEvent(new window.Event('change', { bubbles: true }))
assert.equal(state.assistantCapture.data.burial_variant_code, 'FOREST_BASIC')
assert(renderCount >= 2)
state.assistantCapture.step = 3
assert.match(module.guidedCaptureView(), /Baumbestattung → Basisplatz im FriedWald®/)
window.happyDOM.abort()
console.log('0.65.0 gemeinsamer Variantenbaum im Aufnahmeassistenten: OK')
