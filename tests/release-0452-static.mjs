import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }
const cases = read('src/modules/cases.js')
const records = read('src/modules/records.js')
const main = read('src/main.js')
const css = read('css/style.css')

for (const marker of [
	'F\\u00e4llig: ${esc(formatRecordDate(item.date))}',
	"recordBadges(item, 'task')",
	'recordBadges(item, type)',
	'${esc(formatRecordDate(item.date))}',
]) assert(cases.includes(marker), `Dashboard-Darstellung 0.45.2 fehlt: ${marker}`)
for (const marker of ['Priorität: ${esc(priorityLabel)}', 'Status: ${esc(statusLabel)}']) assert(records.includes(marker), `Beschriftetes Kennzeichen 0.45.2 fehlt: ${marker}`)
assert(main.includes("badges.innerHTML = recordBadges(item, 'task')"), 'Direktes Abhaken aktualisiert die Badges nicht.')
for (const marker of ['bp-dashboard-record-meta', 'bp-agenda-meta', 'bp-mobile-record-meta', '.bp-agenda-item .bp-record-badges', '.bp-mobile-quick .bp-record-badges']) assert(css.includes(marker), `Dashboard-CSS 0.45.2 fehlt: ${marker}`)

const manifest = read('appinfo/info.xml').match(/<version>([^<]+)<\/version>/)?.[1]
const runtime = read('lib/AppInfo/Application.php').match(/VERSION = '([^']+)'/)?.[1]
assert(/^0\.(?:4[6-9]|[5-9]\d)\.\d+$/.test(manifest || '') && runtime === manifest, 'Versionsstand ab 0.46.0 ist nicht konsistent.')
console.log('0.45.2 einheitliche Datums-, Status- und Prioritätsdarstellung statisch geprüft.')
