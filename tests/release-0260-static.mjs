import assert from 'node:assert/strict'
import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'

const root = process.argv[2] || '.'
const read = (file) => readFileSync(join(root, file), 'utf8')
const migrations = readdirSync(join(root, 'lib', 'Migration')).filter((name) => name.endsWith('.php'))
assert.deepEqual(migrations, ['Version1800Date20260906000000.php', 'Version3000Date20260907000000.php', 'Version3100Date20260909000000.php', 'Version3200Date20260910000000.php', 'Version3300Date20260911000000.php', 'Version3400Date20260911010000.php', 'Version3500Date20260915000000.php', 'Version3600Date20260915010000.php'], 'konsolidierte Baseline sowie alle additiven Upgrade-Schritte werden erwartet')

const migration = read('lib/Migration/Version1800Date20260906000000.php')
for (const table of ['bestatter_cases','bestatter_records','bestatter_articles','bestatter_case_services','bestatter_document_templates','bestatter_invoices','bestatter_audit_log']) assert.ok(migration.includes(`'${table}'`), `Baseline enthält ${table}`)

const records = read('src/modules/records.js')
for (const marker of ['name="taskDate"','name="taskTime"',"'00:00'",'show-completed-schedules','showCompletedSchedules']) assert.ok(records.includes(marker), `Aufgaben-/Terminvertrag enthält ${marker}`)
assert.ok(records.includes("type === 'schedule' ? 'allgemeiner Termin' : 'allgemeine Aufgabe'"), 'Fallunabhängige Termine werden als Termin bezeichnet')

const configuration = read('lib/Service/ConfigurationService.php')
for (const marker of ['documentTemplateOptions','duplicateDocument','templateFileNames','allowedRequiredFields','validateTemplatePlaceholders','ZipArchive','folders->templatesFolder()']) assert.ok(configuration.includes(marker), `Vorlagenservice enthält ${marker}`)
assert.ok(configuration.includes('Unbekannte Pflichtfelder') && configuration.includes('Standard-Unterordner'), 'Vorlagen werden serverseitig validiert')
assert.ok(configuration.includes('Nicht unterstützte Platzhalter') && configuration.includes('header[0-9]*') && configuration.includes('footer[0-9]*'), 'DOCX-Inhalt, Kopf- und Fußzeilen werden gegen den Feldkatalog geprüft')

const customizing = read('src/modules/customizing.js')
for (const marker of ['duplicate-document-template','DOCX-Datei aus Bestatter/Vorlagen','Ziel-Unterordner der Fallakte']) assert.ok(customizing.includes(marker), `Vorlagenoberfläche enthält ${marker}`)
assert.match(read('appinfo/info.xml'), /<version>0\.(?:2[6-9]|[3-9]\d)\./, 'Release enthält mindestens die konsolidierte Basis aus 0.26.0')
console.log('Consolidated migration and UI contracts since 0.26.0 passed')
