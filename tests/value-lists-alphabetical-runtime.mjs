import assert from 'node:assert/strict'
import { Window } from 'happy-dom'
import { createCustomizingModule } from '../src/modules/customizing.js'

const window = new Window({ url: 'https://test.example/apps/bestatter/' })
Object.assign(globalThis, { window, document: window.document })
const root = document.createElement('div')
document.body.append(root)
const lists = [
	{ key: 'Z', name: 'Überführung', items: [{ id: 3, value: 'B', label: 'Zweite', sortOrder: 20 }, { id: 4, value: 'A', label: 'Erste', sortOrder: 10 }] },
	{ key: 'B', name: 'Bestattungsart', items: [] },
	{ key: 'A', name: 'Anrede', items: [] },
]
const state = { customizingTab: 'value-lists', valueListKey: 'Z', customizing: lists }
const module = createCustomizingModule({ root, state, esc: (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;') })
root.innerHTML = module.customizing()
assert.deepEqual([...root.querySelectorAll('[data-value-list]')].map((button) => button.textContent.trim()), ['Anrede0 Einträge', 'Bestattungsart0 Einträge', 'Überführung2 Einträge'])
assert.deepEqual([...root.querySelectorAll('[data-value-row] [data-value-label]')].map((input) => input.value), ['Zweite', 'Erste'], 'Einträge behalten ihre gepflegte Reihenfolge')
assert.equal(state.valueListKey, 'Z', 'die zuvor ausgewählte Werteliste bleibt geöffnet')
assert.deepEqual(lists.map((list) => list.key), ['Z', 'B', 'A'], 'nur die Anzeige wird sortiert')
window.happyDOM.abort()
console.log('Alphabetische Wertelisten-Navigation bei unveränderter Eintragsreihenfolge: OK')
