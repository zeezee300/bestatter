import { Window } from 'happy-dom'
import { pathToFileURL } from 'node:url'
import path from 'node:path'
import assert from 'node:assert/strict'

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
	FormData: window.FormData,
	requestAnimationFrame: window.requestAnimationFrame.bind(window),
	OC: { requestToken: 'test', getCurrentUser: () => ({ uid: 'Bestatter-Test' }), generateUrl: (value) => value, Notification: { showTemporary: () => {} } },
})

const payload = (url) => {
	if (url.endsWith('/dashboard')) return { openCases: 0, newCases: 0 }
	if (url.endsWith('/team')) return { currentUid: 'Bestatter-Test', members: [{ uid: 'Bestatter-Test', displayName: 'Test' }], isBestatterAdmin: true }
	if (url.endsWith('/customizing')) return { lists: [] }
	if (url.endsWith('/articles')) return { articles: [] }
	if (url.endsWith('/schedule-presets')) return [{ key: 'BEISETZUNG', label: 'Beisetzung', title: 'Beisetzung', scheduleKind: 'EXTERNAL_APPOINTMENT', appointmentCategory: 'Beisetzung', durationMinutes: 90, priority: 'HOCH', source: 'CATALOG' }]
	return []
}
globalThis.fetch = async (input) => new Response(JSON.stringify(payload(String(input))), { status: 200, headers: { 'content-type': 'application/json' } })

await import(`${pathToFileURL(path.join(rootPath, 'js', 'main.js')).href}?ux027=${Date.now()}`)
await new Promise((resolve) => setTimeout(resolve, 30))

const app = window.document.getElementById('bestatter-app')
app.querySelector('[data-view="schedule"]').dispatchEvent(new window.Event('click', { bubbles: true }))
await new Promise((resolve) => setTimeout(resolve, 20))

let content = app.querySelector('.bp-content')
content.scrollTop = 444
const filter = app.querySelector('[data-record-filter="kind"]')
filter.value = 'EXTERNAL_APPOINTMENT'
filter.dispatchEvent(new window.Event('change', { bubbles: true }))
await new Promise((resolve) => setTimeout(resolve, 30))
content = app.querySelector('.bp-content')
assert.equal(content.scrollTop, 444, 'Filter-Render erhält die Scrollposition innerhalb der Terminansicht')

app.querySelector('#new-record').dispatchEvent(new window.Event('click', { bubbles: true }))
const modal = app.querySelector('.bp-modal')
assert.ok(modal, 'Terminformular wurde geöffnet')
assert.match(modal.textContent, /Terminart \/ Vorlage/)
const preset = modal.querySelector('[name="schedulePresetKey"]')
preset.value = 'BEISETZUNG'
preset.dispatchEvent(new window.Event('change', { bubbles: true }))
assert.equal(modal.querySelector('[name="title"]').value, 'Beisetzung')
assert.equal(modal.querySelector('[name="durationMinutes"]').value, '90')
assert.equal(modal.querySelector('[name="scheduleKind"]').value, 'EXTERNAL_APPOINTMENT')

window.happyDOM.abort()
console.log('0.27.0 runtime: scroll preservation and appointment preset form passed')
