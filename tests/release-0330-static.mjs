import fs from 'node:fs'
import path from 'node:path'
import { readApiControllers } from './helpers/controller-source.mjs'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }

const routes = read('appinfo/routes.php')
const controller = readApiControllers(root)
const operations = read('lib/Service/OperationalService.php')
const assistant = read('lib/Service/AssistantService.php')
const page = read('lib/Controller/PageController.php')
const frontend = read('src/modules/assistant.js')
const cases = read('src/modules/cases.js')
const css = read('css/style.css')

assert(page.includes('addAllowedMicrophoneDomain("\'self\'")'), 'Mikrofon-FeaturePolicy fehlt')
for (const marker of ['/api/dashboard/personal-day', '/api/cases/{id}/completeness']) assert(routes.includes(marker), `Route fehlt: ${marker}`)
for (const marker of ['function personalDay(', 'function caseCompleteness(']) assert(controller.includes(marker), `Controller-Endpunkt fehlt: ${marker}`)
for (const marker of ["'INTAKE'", "'ARRANGEMENT'", "'CEREMONY'", "'BILLING'", 'personalDay(', 'completeness(']) assert(operations.includes(marker), `Operative Prüfung fehlt: ${marker}`)
for (const marker of ['INFORMATION_REQUESTED', 'manualFallback', 'microphonePolicyRequired']) assert(assistant.includes(marker), `Assistenz-/Audit-Vertrag fehlt: ${marker}`)
for (const marker of ['assistantSidebarView', 'assistant-sidebar-command', 'data-assistant-question', 'Keine Ausführung ohne']) assert(frontend.includes(marker), `Seitenleiste fehlt: ${marker}`)
assert(frontend.includes('const button = event.currentTarget instanceof HTMLElement'), 'Aufnahmeknopf wird nicht vor asynchroner Berechtigungsprüfung gesichert')
assert(!frontend.includes("recorder.start(500); event.currentTarget.textContent"), 'Asynchron ungültiges event.currentTarget ist noch vorhanden')
for (const marker of ['recordingStartedAt', "capture().recording = true", "capture().recording = false", '■ Aufnahme beenden', 'aria-pressed']) assert(frontend.includes(marker), `Persistenter Aufnahmezustand fehlt: ${marker}`)
for (const marker of ['bp-mobile-quick', 'bp-completeness', 'personal.counts?.overdueTasks']) assert(cases.includes(marker), `Tages-/Vollständigkeitsansicht fehlt: ${marker}`)
for (const marker of ['bp-assistant-sidebar', 'bp-mobile-quick', 'focus-visible', 'prefers-reduced-motion']) assert(css.includes(marker), `Responsive/A11y-CSS fehlt: ${marker}`)

console.log('0.33.0 operational dashboard, completeness, microphone policy, assistant sidebar and fallback contracts passed')
