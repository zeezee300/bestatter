import fs from 'node:fs'
import path from 'node:path'

const root = process.argv[2] || '.'
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }
const assistant = read('lib/Service/AssistantService.php')

for (const marker of ['ausgeben\\w*', 'erzeug\\w*', 'oeffne\\w*', "if ($haystack === '') return false"]) {
	assert(assistant.includes(marker), `Aktionswort-Bereinigung fehlt: ${marker}`)
}
assert(/<version>0\.(?:34\.[3-9]|3[5-9]\.\d+|[4-9]\d\.\d+)<\/version>/.test(read('appinfo/info.xml')), 'Releaseversion ab 0.34.3 fehlt')

console.log('0.34.3 document action-word normalization passed')
