import assert from 'node:assert/strict'
import { Window } from 'happy-dom'
import { createPaperlessModule } from '../src/modules/paperless.js'

const window = new Window({ url: 'https://nextcloud.example/apps/bestatter/' })
globalThis.window = window
globalThis.document = window.document
globalThis.FormData = window.FormData

const root = document.createElement('div')
document.body.append(root)
const state = {
	paperlessConfiguration: { mode: 'OFF', baseUrl: '', tagIds: [], tokenConfigured: false },
	paperlessConfigurationDraft: null,
}
const module = createPaperlessModule({
	root,
	state,
	esc: (value) => String(value ?? ''),
	api: async () => ({}),
	notifySuccess: () => {},
	notifyError: () => {},
	apiBase: '/apps/bestatter/api',
})

root.innerHTML = module.paperlessSettingsView()
module.bindPaperlessSettings()
const url = root.querySelector('[name="baseUrl"]')
url.value = 'https://paperless.example.test'
url.dispatchEvent(new window.Event('input', { bubbles: true }))

assert(root.querySelector('#paperless-settings').classList.contains('is-dirty'))
assert.equal(state.paperlessConfigurationDraft.baseUrl, 'https://paperless.example.test')
assert.match(root.textContent, /Ungespeicherte Eingaben/)
assert(module.paperlessSettingsView().includes('{{doc_id}}'))
assert(module.paperlessSettingsView().includes('{{doc_title}}'))
console.log('0.48.1 Paperless-Konfigurationsentwurf bleibt bei Hintergrundaktualisierung geschützt.')
