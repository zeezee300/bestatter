import { Window } from 'happy-dom'
import { pathToFileURL } from 'node:url'
import path from 'node:path'

const rootPath = path.resolve(process.argv[2] || '.')
const window = new Window({ url: 'https://test.example/apps/bestatter/' })
window.document.body.innerHTML = `<div id="bestatter-app"
	data-dashboard-url="/apps/bestatter/api/dashboard"
	data-team-url="/apps/bestatter/api/team"
	data-cases-url="/apps/bestatter/api/cases"
	data-customizing-url="/apps/bestatter/api/customizing"
	data-case-schema-url="/apps/bestatter/api/case-schema"></div>`
window.setInterval = () => 0

Object.assign(globalThis, {
	window,
	document: window.document,
	HTMLElement: window.HTMLElement,
	Event: window.Event,
	CustomEvent: window.CustomEvent,
	FormData: window.FormData,
	OC: {
		requestToken: 'test-token',
		getCurrentUser: () => ({ uid: 'Bestatter-Test' }),
		generateUrl: (value) => value,
		Notification: { showTemporary: () => {} },
	},
})

const payload = (url) => {
	if (url.includes('/cases/search')) return { items: [], total: 0, limit: 25, offset: 0 }
	if (url.endsWith('/dashboard')) return { openCases: 0, newCases: 0 }
	if (url.endsWith('/team')) return { currentUid: 'Bestatter-Test', members: [{ uid: 'Bestatter-Test', displayName: 'Test' }], isBestatterAdmin: true }
	if (url.endsWith('/customizing')) return { lists: [] }
	if (url.endsWith('/articles')) return { articles: [] }
	if (url.endsWith('/invoice-settings')) return { prefix: 'RE', pattern: '{PREFIX}-{YYYY}-{SEQ}', sequenceLength: 6, sequenceScope: 'YEAR_GLOBAL' }
	return []
}
globalThis.fetch = async (input) => new Response(JSON.stringify(payload(String(input))), { status: 200, headers: { 'content-type': 'application/json' } })

await import(`${pathToFileURL(path.join(rootPath, 'js', 'main.js')).href}?runtime=${Date.now()}`)
await new Promise((resolve) => setTimeout(resolve, 25))

const app = window.document.getElementById('bestatter-app')
if (!app.querySelector('.bp-app-shell')) throw new Error('App-Shell wurde nicht gerendert.')
if (app.querySelector('.bp-error')) throw new Error(`Frontend meldet Fehler: ${app.querySelector('.bp-error').textContent}`)
if (!app.textContent.includes('Arbeitsübersicht')) throw new Error('Dashboard wurde nicht gerendert.')

for (const view of ['cases', 'task', 'schedule', 'document', 'customizing', 'administration']) {
	const button = [...app.querySelectorAll('[data-view]')].find((entry) => entry.dataset.view === view)
	if (!button) throw new Error(`Navigationspunkt ${view} fehlt.`)
	button.dispatchEvent(new window.Event('click', { bubbles: true }))
	await new Promise((resolve) => setTimeout(resolve, 5))
	if (!app.querySelector(`.bp-view-${view}`)) throw new Error(`Ansicht ${view} wurde nicht gerendert; aktiv: ${app.querySelector('.bp-workspace')?.className || 'keine'}.`)
}
if (app.querySelector('.bp-error')) throw new Error(`Frontend meldet nach Navigation einen Fehler: ${app.querySelector('.bp-error').textContent}`)

window.happyDOM.abort()
console.log('Frontend 0.23.0: Bootstrap, API-Mocks, Dashboard und sechs Hauptansichten im DOM-Smoketest OK')
