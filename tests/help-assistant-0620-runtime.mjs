import assert from 'node:assert/strict'
import {Window} from 'happy-dom'
import {createHelpModule} from '../src/modules/help.js'

const window = new Window()
globalThis.document = window.document
const root = window.document.createElement('div')
window.document.body.append(root)
const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]))
const state = {help:{available:false,open:false,pending:false,draft:'',entries:[]},assistantSidebar:{open:true}}
const calls = []
const api = async (url, options) => {
	calls.push({url,options})
	if (url.endsWith('/status/12')) return {status:'SUCCESSFUL',answer:'Öffnen Sie Fälle und wählen Sie „Neuen Sterbefall anlegen“.',sources:[{file:'BEDIENUNG.md',heading:'Neuen Sterbefall anlegen'}]}
	if (options?.body?.get('question')?.includes('Kaffeemaschine')) return {status:'NO_SOURCE',answer:'Dazu enthält die freigegebene Bedienhilfe keine ausreichende Information.',sources:[]}
	return {status:'SCHEDULED',taskId:12,sources:[{file:'BEDIENUNG.md',heading:'Neuen Sterbefall anlegen'}]}
}
let module
const render = () => { root.innerHTML = `${state.help.available ? '<button id="help-toggle">Bedienhilfe</button>' : ''}${module.helpView()}`; module.bindHelp() }
module = createHelpModule({state,root,apiBase:'/api',api,esc,render})
render()
assert(!root.querySelector('#help-toggle'), 'Ohne Chat-Provider darf Hilfe nicht sichtbar sein')
state.help.available = true; render()
root.querySelector('#help-toggle').click()
assert.equal(state.assistantSidebar.open,false, 'Fallassistent und Bedienhilfe dürfen nicht übereinander liegen')
const form = root.querySelector('#help-question')
form.querySelector('textarea').value = 'Wie lege ich einen neuen Fall an?'
form.querySelector('textarea').dispatchEvent(new window.Event('input', {bubbles:true}))
form.dispatchEvent(new window.Event('submit', {bubbles:true,cancelable:true}))
assert.equal(root.querySelector('#help-question textarea').disabled,true,'Während der Verarbeitung bleibt die Eingabe gesperrt')
await new Promise((resolve)=>setTimeout(resolve,10))
assert.match(root.textContent,/BEDIENUNG.md \/ Neuen Sterbefall anlegen/)
assert.equal(state.help.entries[0].status,'SUCCESSFUL')
const unknown = root.querySelector('#help-question')
unknown.querySelector('textarea').value = 'Wie funktioniert die Kaffeemaschine?'
unknown.querySelector('textarea').dispatchEvent(new window.Event('input', {bubbles:true}))
unknown.dispatchEvent(new window.Event('submit', {bubbles:true,cancelable:true}))
await new Promise((resolve)=>setTimeout(resolve,10))
assert.equal(state.help.entries[1].status,'NO_SOURCE')
assert.equal(state.help.entries[1].sources.length,0)
assert.equal(calls.filter((item)=>item.url.includes('/status/')).length,1)
console.log('Hilfe-Assistent UI: verborgen ohne Provider, Quellen, asynchroner Status und unbekanntes Thema OK')
