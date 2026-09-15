import fs from 'node:fs'
import path from 'node:path'
import { readApiControllers } from './helpers/controller-source.mjs'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }

const application = read('lib/AppInfo/Application.php')
const middleware = read('lib/Middleware/BestatterAccessMiddleware.php')
const accessException = read('lib/Exception/BestatterAccessDeniedException.php')
const team = read('lib/Service/TeamService.php')
const groupware = read('lib/Service/GroupwareService.php')
const routes = read('appinfo/routes.php')
const api = readApiControllers(root)
const page = read('lib/Controller/PageController.php')
const webhook = read('lib/Controller/PaperlessWebhookController.php')
const info = read('appinfo/info.xml')

assert(/<version>0\.(?:2[3-9]|[3-9]\d)\.\d+<\/version>/.test(info) || /<version>0\.22\.(?:[2-9]|\d{2,})<\/version>/.test(info), 'Zugriffsschutz benötigt mindestens Version 0.22.2.')
assert(application.includes('registerMiddleware(BestatterAccessMiddleware::class)'), 'Zentrale Middleware ist nicht registriert.')
assert(middleware.includes('requireBestatterMember()'), 'Middleware erzwingt die Mitgliedschaft nicht.')
assert((middleware.match(/instanceof PaperlessWebhookController/g) || []).length === 1 && middleware.includes("methodName === 'receive'"), 'Die einzige öffentliche Integrationsroute ist nicht eng begrenzt.')
for (const marker of ['BESTATTER_MEMBERSHIP_REQUIRED', 'STATUS_FORBIDDEN', 'Cache-Control', 'no-store', 'instanceof ApiController']) {
	assert(middleware.includes(marker), `403-Behandlung unvollständig: ${marker}`)
}
assert(accessException.includes('extends \\RuntimeException'), 'Dedizierte Zugriffsausnahme fehlt.')
for (const marker of ['requireBestatterMember', 'isBestatterMember', 'hasBestatterRole', 'installationConfig->memberGroup()', 'installationConfig->adminGroups()', 'isAdmin']) {
	assert(team.includes(marker), `Rollenprüfung unvollständig: ${marker}`)
}
assert(team.includes('throw new BestatterAccessDeniedException') && team.includes('requireBestatterAdmin'), 'Admin-Ablehnung wird nicht als 403 behandelt.')
assert(groupware.includes('!$this->team->hasBestatterRole($ownerUid)'), 'Kalender-Ereignislistener ist nicht gegen Fremdkonten abgesichert.')
assert(!api.includes('PublicPage') && !page.includes('PublicPage'), 'Ein fachlicher Bestatter-Controller ist öffentlich markiert.')
assert(webhook.includes('#[PublicPage]') && webhook.includes('X-Bestatter-Webhook-Token') && webhook.includes('receiveWebhook'), 'Der öffentliche Paperless-Webhook ist nicht separat authentifiziert.')

const routeCount = (routes.match(/\['name' =>/g) || []).length
assert(routeCount > 60, 'Unerwartet wenige Routen im Schutzvertrag erfasst.')
console.log(`Zugriffsschutz 0.22.2: Middleware schützt ${routeCount} Routen, statischer Vertrag OK`)
