import assert from 'node:assert/strict'
import { Window } from 'happy-dom'
import { pathToFileURL } from 'node:url'
import path from 'node:path'

const rootPath = path.resolve(process.argv[2] || '.')
const window = new Window({ url: 'https://test.example/apps/bestatter/' })
window.document.body.innerHTML = '<div id="bestatter-app"></div>'
Object.assign(globalThis, { window, document: window.document })

const { createAdministrationModule } = await import(`${pathToFileURL(path.join(rootPath, 'src', 'modules', 'administration.js')).href}?stability030=${Date.now()}`)
const report = {
	version: '0.30.0', generatedAt: '2026-08-27T12:00:00+02:00', overall: 'WARN',
	summary: { ok: 2, warnings: 1, errors: 0 },
	checks: [
		{ key: 'access', label: 'Rollen und Zugriff', status: 'OK', message: '2 Mitglieder.' },
		{ key: 'sync_errors', label: 'Nextcloud-Synchronisation', status: 'WARN', message: '1 Alteintrag.', hint: 'Gespeicherte Fehlermeldung fachlich prüfen.' },
	],
}
let apiCalls = 0
let renderCalls = 0
const state = { administrationTab: 'system', systemCheck: report, articles: [], articleGroupRules: [], branches: [], invoiceSettings: {}, retentionPolicy: {}, retentionPreview: {}, auditIntegrity: {} }
const root = window.document.getElementById('bestatter-app')
const module = createAdministrationModule({
	root, state, apiBase: '/apps/bestatter/api',
	esc: (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;'),
	api: async (url, options) => {
		apiCalls++
		assert.ok(['/apps/bestatter/api/system-check', '/apps/bestatter/api/installation-settings', '/apps/bestatter/api/operations/retention', '/apps/bestatter/api/operations/retention/preview', '/apps/bestatter/api/operations/audit-integrity'].includes(url))
		assert.equal(options.feedback, false)
		return url.endsWith('/installation-settings') ? {} : report
	},
	render: () => { renderCalls++ }, notifyError: (message) => { throw new Error(message) }, notifySuccess: () => {},
	costTypeLabels: {}, unitLabels: {}, articleUrl: () => '', commercialUrl: () => '', contactsByCategory: () => [], field: () => '', formatMoney: () => '', formatQuantity: () => '', funeralScope: () => '', hydrateServiceDraft: () => {}, listOptions: () => '', quantityStep: () => 1, title: () => '', confirmAction: async () => true,
})

root.innerHTML = module.administration()
assert.match(root.textContent, /Abnahme und Stabilität/)
assert.match(root.textContent, /Gesamtergebnis: Hinweis/)
assert.match(root.textContent, /Nextcloud-Synchronisation/)
assert.match(root.textContent, /Einordnung: Gespeicherte Fehlermeldung fachlich prüfen/)
assert.equal(root.querySelectorAll('.bp-system-check').length, 2)
module.bindSystemCheck()
root.querySelector('#run-system-check').click()
await new Promise((resolve) => setTimeout(resolve, 5))
assert.equal(apiCalls, 5)
assert.equal(renderCalls, 1)

window.happyDOM.abort()
console.log('0.30.0 runtime: admin system check report and refresh passed')
