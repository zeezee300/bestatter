const STORAGE_KEY = 'bestatter:workspace:v1'
const ALLOWED_VIEWS = new Set(['dashboard', 'capture', 'cases', 'case-detail', 'task', 'schedule', 'document', 'inbox', 'customizing', 'administration'])

export function readWorkspace() {
	let value = {}
	try { value = JSON.parse(globalThis.sessionStorage?.getItem(STORAGE_KEY) || '{}') || {} } catch (_) { value = {} }
	return { ...value, view: ALLOWED_VIEWS.has(value.view) ? value.view : 'dashboard' }
}

export function rememberWorkspace(state) {
	if (state.newCase) return
	try {
		globalThis.sessionStorage?.setItem(STORAGE_KEY, JSON.stringify({
			view: state.view, caseId: Number(state.currentCase?.id || state.currentCaseId || 0),
			caseTab: state.caseTab, masterTab: state.masterTab, customizingTab: state.customizingTab,
			administrationTab: state.administrationTab, dashboardDate: state.dashboardDate,
		}))
	} catch (_) {}
}

export async function restoreWorkspace(ctx) {
	const { state, urls, apiBase, api, loadRecordType, loadCaseFiles, loadAllCaseFiles, loadCommercial, loadSystemCheck, loadOperationsCockpit, loadReportingSummary, loadPaperlessInbox, loadPaperlessConfiguration, checklistManageUrl, workflowUrl, schedulingUrl, deregistrationUrl, notifyWarning } = ctx
	try {
		if (state.view === 'case-detail') {
			if (!state.currentCaseId) { state.view = 'cases'; return }
			state.currentCase = await api(`${urls.cases}/${state.currentCaseId}`, { feedback: false })
			state.caseCompleteness = await api(`${apiBase}/cases/${state.currentCaseId}/completeness`, { feedback: false })
			state.assistantSidebar.caseId = state.currentCaseId
			if (['task', 'schedule', 'document', 'history'].includes(state.caseTab)) await loadRecordType(state.caseTab === 'history' ? 'activity' : state.caseTab, state.currentCaseId)
			if (state.caseTab === 'document') await Promise.all([
				loadCaseFiles(state.currentCaseId),
				api(`${apiBase}/cases/${state.currentCaseId}/business-mail/availability`, { feedback: false }).then((result) => { state.businessMailAvailability = result }),
				api(`${apiBase}/cases/${state.currentCaseId}/business-mail`, { feedback: false }).then((result) => { state.businessMailHistory = result; state.businessMailCaseId = Number(state.currentCaseId) }),
			])
			if (state.caseTab === 'contact') await Promise.all([loadRecordType('contact'), loadRecordType('case_contact', state.currentCaseId)])
			if (state.caseTab === 'deregistration') await Promise.all([loadRecordType('contact'), loadRecordType('document', state.currentCaseId), loadCaseFiles(state.currentCaseId), api(deregistrationUrl(state.currentCaseId), { feedback: false }).then((items) => { state.records.deregistration = items })])
			if (['order', 'services', 'finances'].includes(state.caseTab)) await loadCommercial()
		}
		if (state.view === 'document') await loadAllCaseFiles()
		if (state.view === 'inbox') await loadPaperlessInbox()
		if (state.view === 'customizing') {
			if (state.customizingTab === 'checklists') state.checklistAdmin = await api(checklistManageUrl(), { feedback: false })
			if (state.customizingTab === 'workflows') state.workflowAdmin = await api(workflowUrl(), { feedback: false })
			if (state.customizingTab === 'scheduling') state.scheduling = await api(schedulingUrl(), { feedback: false })
		}
		if (state.view === 'administration' && state.administrationTab === 'system') await loadSystemCheck()
		if (state.view === 'administration' && state.administrationTab === 'cockpit') await loadOperationsCockpit()
		if (state.view === 'administration' && state.administrationTab === 'reports') await loadReportingSummary()
		if (state.view === 'administration' && state.administrationTab === 'paperless') await loadPaperlessConfiguration()
	} catch (error) {
		state.currentCase = null; state.currentCaseId = 0; state.view = 'cases'
		notifyWarning(`Der zuletzt geöffnete Arbeitsbereich konnte nicht vollständig wiederhergestellt werden: ${error.message}`)
	}
}
