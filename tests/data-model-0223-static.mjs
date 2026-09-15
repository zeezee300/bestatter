import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }

const info = read('appinfo/info.xml')
const application = read('lib/AppInfo/Application.php')
const listener = read('lib/Listener/AddMissingIndicesListener.php')
const migration = read('lib/Migration/Version1800Date20260906000000.php')
const cases = read('lib/Service/CaseService.php')

assert(/<version>0\.(?:2[3-9]|[3-9]\d)\./.test(info) || /<version>0\.22\.(?:[3-9]|\d{2,})<\/version>/.test(info), 'Datenmodell benötigt mindestens Version 0.22.3.')
assert(application.includes('AddMissingIndicesEvent::class, AddMissingIndicesListener::class'), 'Index-Listener ist nicht registriert.')
for (const marker of [
	"['created_at', 'id']",
	"['status', 'created_at']",
	"['branch', 'created_at']",
	"['date_of_death']",
]) assert(listener.includes(marker), `Indexdefinition fehlt: ${marker}`)
for (const marker of ["'date_of_death','date'", "'created_at','datetime'", "'updated_at','datetime'", 'bestatter_case_created', 'bestatter_case_death']) {
	assert(migration.includes(marker), `Konsolidierte Baseline enthält nativen Datumstyp oder Index nicht: ${marker}`)
}
assert(cases.includes("addOrderBy('id', 'DESC')"), 'Stabile Zweitsortierung der Fallliste fehlt.')
assert(cases.includes('normalizeDateOfDeath'), 'Validierung des Sterbedatums fehlt.')
assert(cases.includes("format('Y-m-d H:i:s')"), 'UTC-Datenbankformat fehlt.')
assert(!cases.includes("->set('updated_at', $query->createNamedParameter(date('c')))"), 'Fallservice schreibt weiterhin ISO-Strings mit Zeitzonenoffset.')

console.log('Datenmodell 0.22.3: Datumstypen, Validierung, UTC-Format und vier Indizes statisch geprüft')
