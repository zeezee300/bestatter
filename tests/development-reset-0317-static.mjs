import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(import.meta.dirname, '..')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')

const info = read('appinfo/info.xml')
const command = read('lib/Command/DevelopmentReset.php')
const service = read('lib/Service/DevelopmentResetService.php')

assert.match(info, /<version>0\.(?:31\.(?:[7-9]|[1-9][0-9])|(?:3[2-9]|[4-9]\d)\.\d+)<\/version>/)
assert.match(info, /OCA\\Bestatter\\Command\\DevelopmentReset/)
assert.match(command, /bestatter:development-reset/)
assert.match(command, /BESTATTER-ENTWICKLUNGSRESET/)
assert.match(command, /getOption\('execute'\)/)
assert.match(service, /function preview\(\)/)
assert.match(service, /function backup\(/)
assert.match(service, /chmod\(\$path, 0600\)/)
assert.match(service, /installationConfig->casesPath\(\)/)
assert.match(service, /deleteCalendarObject/)

for (const table of [
	'bestatter_invoice_items', 'bestatter_invoices', 'bestatter_commercial_docs',
	'bestatter_workflow_runs', 'bestatter_audit_log', 'bestatter_case_services',
	'bestatter_records', 'bestatter_cases', 'bestatter_invoice_sequences',
]) assert.ok(service.includes(`'${table}'`), `Reset-Tabelle fehlt: ${table}`)

for (const preserved of [
	'bestatter_branches', 'bestatter_articles', 'bestatter_article_groups',
	'bestatter_choice_lists', 'bestatter_checklist_templates', 'bestatter_workflows',
	'bestatter_document_templates', 'bestatter_dereg_templates', 'bestatter_invoice_settings',
]) assert.doesNotMatch(service.match(/OPERATIONAL_TABLES = \[[\s\S]*?\];/)?.[0] || '', new RegExp(`'${preserved}'`), `Konfiguration darf nicht gelöscht werden: ${preserved}`)

console.log('0.31.7 guarded development reset contracts passed.')
