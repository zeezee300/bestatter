import assert from 'node:assert/strict'
import fs from 'node:fs'

const command = fs.readFileSync('lib/Command/PaperlessAcceptanceCheck.php', 'utf8')

for (const marker of [
	'bestatter:paperless-acceptance-check',
	'probe-first-inbox',
	'document-id',
	'connectionTest()',
	'downloadDocument(',
	'duplicateDocumentIds',
	"'readOnly'=>true",
	"'assignment'=>",
]) assert(command.includes(marker), `Abnahmefunktion fehlt: ${marker}`)

assert(!command.includes('assign('), 'Der lesende Abnahmetest darf keine Fallzuordnung auslösen.')
assert(!command.includes('receiveWebhook('), 'Der lesende Abnahmetest darf keinen Webhook simulieren.')
assert(fs.readFileSync('appinfo/info.xml', 'utf8').includes('OCA\\Bestatter\\Command\\PaperlessAcceptanceCheck'))

console.log('0.48.3 lesender Paperless-Abnahmetest statisch geprüft.')
