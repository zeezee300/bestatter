import assert from 'node:assert/strict'
import fs from 'node:fs'

const ui = fs.readFileSync('src/modules/administration.js', 'utf8')
const css = fs.readFileSync('css/style.css', 'utf8')
const concept = fs.readFileSync('docs/KONZEPT-EINGANGSRECHNUNGEN-DURCHLAUFENDE-POSTEN.md', 'utf8')
const op = fs.readFileSync('docs/OP-LISTE.md', 'utf8')

for (const marker of ['incomingFlowSteps', 'bp-incoming-wizard', 'data-incoming-step', 'incoming-review', 'Nächster Schritt:', 'Keine passende Position – einmalige Zusatzleistung anlegen']) assert.ok(ui.includes(marker), `UI-Marker fehlt: ${marker}`)
for (const marker of ['.bp-incoming-flow', '.bp-wizard-steps', '.bp-review-grid', '.bp-process-explainer']) assert.ok(css.includes(marker), `CSS-Marker fehlt: ${marker}`)
assert.match(concept, /Rechnung–Fallleistung–Kundenabrechnung-Abgleich/)
assert.match(concept, /Geführte Bedienung ab 0\.39\.0/)
assert.match(op, /OP-023 – Lieferantenbestellung und optionaler Drei-Wege-Abgleich/)

console.log('0.39.0 guided incoming-invoice workflow contract passed')
