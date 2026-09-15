import { Window } from 'happy-dom'
import { pathToFileURL } from 'node:url'
import path from 'node:path'
import assert from 'node:assert/strict'

const rootPath = path.resolve(process.argv[2] || '.')
const window = new Window({ url: 'https://test.example/apps/bestatter/' })
window.document.body.innerHTML = '<div id="bestatter-app"><form><input required></form></div>'
Object.assign(globalThis, { window, document: window.document, HTMLElement: window.HTMLElement, requestAnimationFrame: window.requestAnimationFrame.bind(window) })

const { createUi } = await import(`${pathToFileURL(path.join(rootPath, 'src', 'modules', 'ui.js')).href}?ux029=${Date.now()}`)
const root = window.document.getElementById('bestatter-app')
const ui = createUi(root, (value) => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;'))
ui.enhanceAccessibility(root)
assert.equal(root.querySelector('[required]').getAttribute('aria-required'), 'true')

ui.notifySuccess('Gespeichert')
assert.match(window.document.querySelector('.bp-toast').textContent, /Gespeichert/)

const confirmation = ui.confirmAction('Wirklich fortfahren?')
await new Promise((resolve) => setTimeout(resolve, 5))
assert.equal(window.document.querySelector('.bp-ui-dialog').getAttribute('aria-modal'), 'true')
window.document.querySelector('.bp-dialog-confirm').click()
assert.equal(await confirmation, true)

const prompt = ui.promptAction('Begründung', 'Test', { label: 'Begründung' })
await new Promise((resolve) => setTimeout(resolve, 5))
window.document.querySelector('.bp-dialog-confirm').click()
assert.equal(await prompt, 'Test')

const done = ui.beginLoading('Test wird geladen …')
await new Promise((resolve) => setTimeout(resolve, 190))
assert.ok(window.document.querySelector('.bp-global-loading').classList.contains('visible'))
done()
assert.ok(!window.document.querySelector('.bp-global-loading').classList.contains('visible'))

window.happyDOM.abort()
console.log('0.29.0 runtime: toast, dialog, prompt, loading and ARIA passed')
