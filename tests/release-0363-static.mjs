import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => readFile(path.join(root, file), 'utf8')
const [info, groupware, records, operations, frontend] = await Promise.all([
	read('appinfo/info.xml'),
	read('lib/Service/GroupwareService.php'),
	read('lib/Service/RecordService.php'),
	read('lib/Service/OperationalService.php'),
	read('src/main.js'),
])

assert.match(info, /<version>0\.(?:36\.(?:[3-9]|\d{2,})|(?:3[7-9]|[4-9]\d)\.\d+)<\/version>/)
for (const marker of ['isManagedComponent', 'hasBestatterMarker', 'excludedCalendarName', 'removedLocalMirrors', "'ignored' => $ignored"]) {
	assert.ok(groupware.includes(marker), `Groupware-Schutz fehlt: ${marker}`)
}
assert.ok(records.includes('removeImportedCalendarRecords'), 'Lokale, remote-sichere Kalenderbereinigung fehlt.')
assert.ok(records.includes("isNull('case_id')"), 'Bereinigung muss auf falllose Spiegel begrenzt sein.')
assert.ok(operations.includes('Festgeschriebener Auftrag fehlt'), 'Fehlende Auftragsversion ist nicht eindeutig bezeichnet.')
assert.ok(operations.indexOf('$nextActions = []') > operations.indexOf('public function completeness'), 'Folgeaktionen müssen in completeness berechnet werden.')
assert.ok(frontend.includes('Die Nextcloud-Kalendereinträge bleiben unverändert.'), 'Sicherer Bereinigungshinweis fehlt.')

console.log('0.36.3 calendar isolation and completeness corrections passed')
