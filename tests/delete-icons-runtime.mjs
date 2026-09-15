import { Window } from 'happy-dom'
import { pathToFileURL } from 'node:url'
import path from 'node:path'
import assert from 'node:assert/strict'

const rootPath = path.resolve(process.argv[2] || '.')
const window = new Window({ url: 'https://test.example/apps/bestatter/' })
window.document.body.innerHTML = '<div id="bestatter-app"><button class="bp-danger delete-article" data-article-id="17">Löschen</button><button class="bp-danger remove-component" disabled>Entfernen</button><button class="bp-danger">Bestätigen</button></div>'
Object.assign(globalThis, { window, document: window.document, HTMLElement: window.HTMLElement, requestAnimationFrame: window.requestAnimationFrame.bind(window) })

const { createUi } = await import(`${pathToFileURL(path.join(rootPath, 'src', 'modules', 'ui.js')).href}?deleteIcons=${Date.now()}`)
const root = window.document.getElementById('bestatter-app')
const ui = createUi(root, String)
ui.enhanceAccessibility(root)

const articleDelete = root.querySelector('.delete-article')
assert(articleDelete.classList.contains('bp-delete-icon-button'))
assert.equal(articleDelete.getAttribute('aria-label'), 'Artikel löschen')
assert.equal(articleDelete.getAttribute('title'), 'Artikel löschen')
assert(articleDelete.querySelector('.bp-trash-icon'))
assert.equal(articleDelete.dataset.articleId, '17')

const componentDelete = root.querySelector('.remove-component')
assert(componentDelete.querySelector('.bp-trash-icon'))
assert(componentDelete.disabled, 'Der Icon-Austausch darf eine fachliche Sperre nicht aufheben.')
assert.equal(root.querySelector('.bp-danger:not(.delete-article):not(.remove-component)').textContent, 'Bestätigen', 'Andere Gefahr-/Bestätigungsaktionen dürfen nicht pauschal zu Mülleimern werden.')

window.happyDOM.abort()
console.log('Einheitliche Mülleimer-Icons: DOM-Laufzeittest OK')
