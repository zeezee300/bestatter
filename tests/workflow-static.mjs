import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }

const templates = JSON.parse(read('resources/workflow-templates.json')).workflows
assert(Array.isArray(templates) && templates.length >= 3, 'Start-Workflows fehlen.')
const allowedTypes = ['TASK', 'REMINDER', 'SCHEDULE', 'DOCUMENT', 'DOCUMENT_BUNDLE', 'EMAIL_DRAFT', 'CONTACT_ACTIVITY', 'CASE_STATUS']
const allowedDocuments = ['BESTATTUNGSAUFTRAG', 'BESTATTUNGSVOLLMACHT', 'STERBEFALLANZEIGE_STANDESAMT', 'RENTENSERVICE_AENDERUNGSFORMULAR', 'KONDOLENZLISTE_DECKBLATT', 'KONDOLENZLISTE_LISTE']
const workflowKeys = new Set()
for (const workflow of templates) {
	assert(/^[A-Z0-9_-]+$/.test(workflow.key), `Ungültiger Workflow-Schlüssel: ${workflow.key}`)
	assert(!workflowKeys.has(workflow.key), `Doppelter Workflow-Schlüssel: ${workflow.key}`)
	workflowKeys.add(workflow.key)
	assert(workflow.actions.length > 0, `Workflow ohne Aktionen: ${workflow.key}`)
	const actionKeys = new Set()
	for (const action of workflow.actions) {
		assert(allowedTypes.includes(action.type), `Ungültiger Aktionstyp: ${action.type}`)
		assert(!actionKeys.has(action.key), `Doppelter Aktionsschlüssel in ${workflow.key}: ${action.key}`)
		actionKeys.add(action.key)
		if (action.type === 'DOCUMENT') assert(allowedDocuments.includes(action.templateKey), `Nicht ausführbare Dokumentvorlage: ${action.templateKey}`)
		if (action.type === 'DOCUMENT_BUNDLE') assert(action.templateKeys.every((key) => allowedDocuments.includes(key)), `Nicht ausführbares Dokumentpaket: ${action.templateKeys.join(', ')}`)
	}
}

const registryWorkflow = templates.find((workflow) => workflow.key === 'STANDESAMT_ANZEIGE')
assert(registryWorkflow, 'Standesamt-Workflow fehlt.')
for (const type of ['DOCUMENT', 'EMAIL_DRAFT', 'CONTACT_ACTIVITY', 'REMINDER', 'CASE_STATUS']) {
	assert(registryWorkflow.actions.some((action) => action.type === type), `Standesamt-Aktion fehlt: ${type}`)
}

const routes = read('appinfo/routes.php')
for (const endpoint of ['/api/workflows', '/workflow-actions']) assert(routes.includes(endpoint), `Route fehlt: ${endpoint}`)
const migration = read('lib/Migration/Version1800Date20260906000000.php')
for (const table of ['bestatter_workflows', 'bestatter_workflow_runs']) assert(migration.includes(table), `Migrationstabelle fehlt: ${table}`)
const service = read('lib/Service/WorkflowService.php')
for (const type of allowedTypes.map((type) => `'${type}'`)) assert(service.includes(type), `Ausführungstyp fehlt: ${type}`)
const documentService = read('lib/Service/DocumentService.php')
assert(documentService.includes('configuration->documents'), 'Konfigurierbare Dokumentausführung fehlt.')
assert(fs.existsSync(path.join(root, 'resources/templates/STERBEFALLANZEIGE_STANDESAMT_BEARBEITBAR.docx')), 'Bearbeitbare Sterbefallanzeige fehlt.')
const openItems = read('docs/OP-LISTE.md')
assert(openItems.includes('Vier-Augen') && openItems.includes('Automatischer E-Mail-Versand'), 'Zurückgestellte Punkte fehlen in der OP-Liste.')

console.log(`Workflow-Vertrag OK: ${templates.length} Workflows, ${templates.reduce((sum, workflow) => sum + workflow.actions.length, 0)} Aktionen`)
