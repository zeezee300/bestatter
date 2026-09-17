import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const routesSource = await readFile(path.join(root, 'appinfo', 'routes.php'), 'utf8')
const routePattern = /\['name' => '([^'#]+)#([^']+)'/g
const routes = [...routesSource.matchAll(routePattern)]

assert.equal(routes.length, 159, 'Die erwarteten 159 Seiten-, API- und Webhook-Routen müssen registriert sein.')

const apiRoutes = routes.filter(([, controller]) => !['page','paperlessWebhook'].includes(controller))
assert.equal(apiRoutes.length, 157, 'Alle 157 geschützten JSON-/Download-API-Routen müssen erfasst sein.')

const controllerSources = new Map()
for (const [, controller, method] of apiRoutes) {
	const className = `${controller[0].toUpperCase()}${controller.slice(1)}Controller`
	const file = path.join(root, 'lib', 'Controller', `${className}.php`)
	let source = controllerSources.get(file)
	if (source === undefined) {
		source = await readFile(file, 'utf8')
		controllerSources.set(file, source)
		assert.match(source, new RegExp(`class\\s+${className}\\s+extends\\s+ApiController\\b`), `${className} muss den zentral geschützten ApiController erweitern.`)
	}
	assert.match(source, new RegExp(`public\\s+function\\s+${method}\\s*\\(`), `${className}::${method} fehlt.`)
}

assert.equal(controllerSources.size, 13, 'Die geschützte API muss in dreizehn fachliche Controller aufgeteilt bleiben.')

const baseSource = await readFile(path.join(root, 'lib', 'Controller', 'ApiController.php'), 'utf8')
assert.match(baseSource, /abstract\s+class\s+ApiController\s+extends\s+Controller/)
assert.doesNotMatch(baseSource, /#\[NoAdminRequired\]/, 'Der Marker-Controller darf keine fachlichen Endpunkte enthalten.')

const webhookSource = await readFile(path.join(root, 'lib', 'Controller', 'PaperlessWebhookController.php'), 'utf8')
assert.match(webhookSource, /class\s+PaperlessWebhookController\s+extends\s+Controller\b/)
assert.match(webhookSource, /public\s+function\s+receive\s*\(/)
console.log('Controller-Split, 151 geschützte API-Routen und separater Paperless-Webhook geprüft.')
