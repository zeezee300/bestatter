import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(import.meta.dirname, '..')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')

const service = read('lib/Service/StabilizationService.php')
assert.match(service, /templateSourceAvailable/)
assert.match(service, /resources\/templates/)
assert.match(service, /normalizedTemplateName/)
assert.match(service, /expectsCondolence/)
assert.match(service, /statusBeforeRemoteDeletion/)
assert.match(service, /Altbestand vor 0\.31\.5/)
assert.match(service, /'hint' => \$hint/)
assert.doesNotMatch(service, /->(?:insert|update|delete)\(/, 'Systemprüfung bleibt rein lesend')

const frontend = read('src/modules/administration.js')
assert.match(frontend, /bp-system-check-hint/)
assert.match(frontend, /Einordnung:/)
assert.match(read('appinfo/info.xml'), /<version>0\.(?:31\.(?:[6-9]|[1-9][0-9])|(?:3[2-9]|[4-9]\d)\.\d+)<\/version>/)
assert.match(read('lib/AppInfo/Application.php'), /VERSION = '0\.(?:31\.(?:[6-9]|[1-9][0-9])|(?:3[2-9]|[4-9]\d)\.\d+)'/)

console.log('0.31.6 aligned system-check classification checks passed.')
