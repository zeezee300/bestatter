import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => readFile(path.join(root, file), 'utf8')
const [info, packageJson, composerJson, attributes, runner] = await Promise.all([
	read('appinfo/info.xml'),
	read('package.json'),
	read('composer.json'),
	read('.gitattributes'),
	read('tests/run-all.mjs'),
])

assert.match(info, /<version>0\.(?:36\.(?:[2-9]|\d{2,})|(?:3[7-9]|[4-9]\d)\.\d+)<\/version>/)
assert.match(JSON.parse(packageJson).version, /^0\.(?:36\.(?:[2-9]|\d{2,})|(?:3[7-9]|[4-9]\d)\.\d+)$/)

const composer = JSON.parse(composerJson)
for (const dependency of ['phpunit/phpunit', 'phpstan/phpstan', 'nextcloud/coding-standard', 'nextcloud/ocp']) {
	assert.ok(composer['require-dev']?.[dependency], `${dependency} fehlt.`)
}
for (const script of ['lint', 'test:unit', 'analyse', 'cs:check', 'check']) {
	assert.ok(composer.scripts?.[script], `Composer-Skript ${script} fehlt.`)
}

assert.match(attributes, /\* text=auto eol=lf/)
assert.match(runner, /discoverTests/)
assert.match(runner, /withFileTypes: true/)

console.log('0.36.2 quality-tooling contract passed')
