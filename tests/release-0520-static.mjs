import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = async (file) => readFile(path.join(root, file), 'utf8')
const [info, app, routes, backup, restore, purge, onboarding, operations, main, administration, manual, opList] = await Promise.all([
	read('appinfo/info.xml'), read('lib/AppInfo/Application.php'), read('appinfo/routes.php'), read('lib/Service/BackupService.php'),
	read('lib/Command/Restore.php'), read('lib/Service/PurgeService.php'), read('lib/Service/OnboardingService.php'),
	read('lib/Controller/OperationsApiController.php'), read('src/main.js'), read('src/modules/administration.js'),
	read('docs/BETRIEBSHANDBUCH.md'), read('docs/OP-LISTE.md'),
])

assert(info.includes('<version>0.65.0</version>'))
assert(app.includes("VERSION = '0.65.0'"))
assert(info.includes('OCA\\Bestatter\\Command\\Purge'))
assert(routes.includes("operationsApi#onboarding"))
assert(routes.includes("operationsApi#completeOnboarding"))
assert(routes.includes("operationsApi#saveBackupSettings"))
assert(routes.includes("operationsApi#cleanupBackups"))
assert(routes.includes("operationsApi#deleteBackup"))
assert(backup.includes("'format' => 'bestatter-package/1'"))
assert(backup.includes("'bestatter_country_profiles'"))
assert(backup.includes("'manifest.json'"))
assert(backup.includes('restorePackage'))
assert(backup.includes('DEFAULT_DIRECTORY'))
assert(backup.includes('managedDirectories'))
assert(backup.includes('deleteManaged'))
assert(backup.includes('deleteExpired'))
assert(backup.includes('Das letzte gültige vollständige Sicherungspaket darf nicht gelöscht werden'))
assert(restore.includes("str_ends_with(strtolower($path),'.zip')"))
assert(purge.includes('class PurgeService'))
assert(purge.includes("'bestatter_country_profiles'"))
assert(purge.includes("if ($this->legalHolds() !== [])"))
assert(onboarding.includes("'onboarding_completed'"))
assert(onboarding.includes('createMissingGroups'))
assert(operations.includes("'application/zip'"))
assert(main.includes('Einrichtungshinweis:'))
assert(!main.includes('!setupRequired ||'))
assert(administration.includes('Vollständiges Sicherungspaket erstellen'))
assert(administration.includes('Geführte Ersteinrichtung'))
assert(administration.includes('Speichern unter …'))
assert(administration.includes('Fristabgelaufene Sicherungen löschen'))
assert(manual.includes('bestatter:purge'))
assert(opList.includes('In Version 0.52.0 umgesetzt'))

console.log('0.52.2 Ersteinrichtung ohne Betriebssperre, vollständiges Fachpaket und sicherer Purge statisch geprüft.')
