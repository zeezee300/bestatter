import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8')
const config = read('lib/Service/InstallationConfigService.php')
const team = read('lib/Service/TeamService.php')
const activity = read('lib/Service/BestatterActivityService.php')
const settings = read('lib/Activity/Setting/AbstractBestatterSetting.php')
const routes = read('appinfo/routes.php')

for (const key of ['member_group','admin_groups','storage_root','cases_folder','templates_folder','assistant_folder','article_images_folder','case_subfolders','billing_folder']) assert.ok(config.includes(key), `Installationsparameter fehlt: ${key}`)
assert.ok(team.includes('installationConfig->memberGroup()'))
assert.ok(team.includes('installationConfig->adminGroups()'))
assert.ok(activity.includes('!$this->team->hasBestatterRole($recipient)'))
assert.ok(settings.includes('isBestatterUser()'))
assert.ok(routes.includes('/api/installation-settings'))
console.log('0.41.1 Installationskonfiguration und gruppenbegrenzte Aktivitäten geprüft.')
