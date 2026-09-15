import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const service = read('lib/Service/AssistantService.php')
const frontend = read('src/modules/assistant.js')
const info = read('appinfo/info.xml')
const op = read('docs/OP-LISTE.md')
const fixture = read('tests/assistant-0322-text-fixture.php')

if (!/<version>0\.(?:36\.(?:[1-9]|\d{2,})|(?:3[7-9]|[4-9]\d)\.\d+)<\/version>/.test(info)) throw new Error('App version is older than 0.36.1')
for (const marker of ['es\\s+geht\\s+um', 'letzte\\s+wohnsitz', 'suggestAdministrativeDetails', 'suggestClientDetails', 'suggestCertificateCounts']) {
	if (!service.includes(marker)) throw new Error(`Recognition marker missing: ${marker}`)
}
for (const marker of ['capture-transcription-retry', 'Status erneut prüfen', 'transcriptionTaskId', "'delayed'"]) {
	if (!frontend.includes(marker)) throw new Error(`Delayed transcription marker missing: ${marker}`)
}
for (const marker of ["'first_name' => 'Maria'", "'date_of_death' => '2022-08-23'", "'cemetery_contact' => 'Riensberger Friedhof'", "'certificate_paid_count' => '3'"]) {
	if (!fixture.includes(marker)) throw new Error(`Current dictation fixture marker missing: ${marker}`)
}
if (!op.includes('OP-020 – Kontrollierte Übergabe aus Nextcloud Talk')) throw new Error('Talk handoff OP is missing')
console.log('release-0361-static: ok')
