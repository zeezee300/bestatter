import assert from 'node:assert/strict'
import { readFileSync, readdirSync } from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (name) => readFileSync(path.join(root, name), 'utf8')
const info = read('appinfo/info.xml')
const composer = JSON.parse(read('composer.json'))
const pkg = JSON.parse(read('package.json'))

assert.match(info, /<version>0\.65\.0<\/version>/)
assert.match(info, /<nextcloud min-version="34" max-version="35"\s*\/>/)
assert.equal(pkg.version, '0.65.0')
assert.match(read('lib/AppInfo/Application.php'), /VERSION = '0\.65\.0'/)
assert.equal(composer['require-dev']['nextcloud/ocp'], '^34.0 || 35.0.0.x-dev')

const commandDir = path.join(root, 'lib', 'Command')
const commands = readdirSync(commandDir).filter((name) => name.endsWith('.php'))
assert.equal(commands.length, 7)
for (const command of commands) {
	assert.match(read(path.join('lib', 'Command', command)), /protected function execute\s*\([^)]*\)\s*:\s*int\s*\{/, `${command} benötigt für Symfony 7 einen int-Rückgabewert.`)
}

function sources(directory) {
	return readdirSync(path.join(root, directory), { withFileTypes: true }).flatMap((entry) => {
		const name = path.join(directory, entry.name)
		return entry.isDirectory() ? sources(name) : /\.(?:php|js)$/.test(name) ? [name] : []
	})
}

const removedApi = /\b(?:oc_appswebroots|oc_config|oc_current_user|oc_debug|oc_defaults|oc_isadmin|oc_requesttoken|oc_webroot|OCDialogs|ClipboardJS)\b|\bType::lookupName\s*\(|->setOptions\s*\(|IPreview::registerProvider\s*\(|\\OCP\\Remote\\/
for (const file of ['lib', 'src', 'templates'].flatMap(sources)) {
	assert.doesNotMatch(read(file), removedApi, `${file} verwendet eine in Nextcloud 35 entfernte API.`)
}

console.log('0.65.0: Nextcloud-34/35-Metadaten und dokumentierte Bruchstellen statisch geprüft; NC35-Laufzeittest bleibt Pflicht.')
