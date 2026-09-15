/* global OC */ import { createCasesModule } from './modules/cases.js'; import { createRecordsModule } from './modules/records.js'; import { createCommercialModule } from './modules/commercial.js'; import { createDocumentsModule } from './modules/documents.js'; import { createAdministrationModule } from './modules/administration.js'; import { createCustomizingModule } from './modules/customizing.js'; import { createUi } from './modules/ui.js'; import { createAssistantModule } from './modules/assistant.js'; import { readWorkspace, rememberWorkspace, restoreWorkspace } from './modules/workspace.js'
import { createPaperlessModule } from './modules/paperless.js'
(function () {
	'use strict'; const root = document.getElementById('bestatter-app'); if (!root) return
	const appVersion = root.dataset.appVersion || ''; const urls = {
		dashboard: root.dataset.dashboardUrl,
		team: root.dataset.teamUrl,
		cases: root.dataset.casesUrl,
		customizing: root.dataset.customizingUrl,
		schema: root.dataset.caseSchemaUrl,
	}; const apiBase = urls.cases.replace(/\/cases(?:$|[?].*)/, '')
	const recordUrl = (type) => `${apiBase}/records/${type}`
	const recordItemUrl = (id) => `${apiBase}/records/${id}`
	const articleUrl = () => `${apiBase}/articles`
	const caseServicesUrl = (caseId) => `${apiBase}/cases/${caseId}/services`
	const sideOrdersUrl = (caseId) => `${apiBase}/cases/${caseId}/side-orders`
	const commercialUrl = (caseId) => `${apiBase}/cases/${caseId}/commercial`
	const checklistUrl = () => `${apiBase}/checklists`
	const checklistManageUrl = () => `${apiBase}/checklists/manage`
	const workflowUrl = () => `${apiBase}/workflows`
	const schedulePresetsUrl = () => `${apiBase}/schedule-presets`
	const schedulingUrl = () => `${apiBase}/scheduling`
	const documentTemplateUrl = () => `${apiBase}/document-templates`
	const documentTemplateOptionsUrl = () => `${apiBase}/document-template-options`
	const deregistrationTemplateUrl = () => `${apiBase}/deregistration-templates`
	const deregistrationUrl = (caseId) => `${apiBase}/cases/${caseId}/deregistrations`
	const documentUrl = (caseId, key) => `${apiBase}/cases/${caseId}/documents/${encodeURIComponent(key)}`
	const caseFilesUrl = (caseId) => `${apiBase}/cases/${caseId}/files`
	const allCaseFilesUrl = () => `${apiBase}/files`
	const branchUrl = () => `${apiBase}/branches`
	const invoiceSettingsUrl = () => `${apiBase}/invoice-settings`
	const workflowActionsUrl = (recordId) => `${apiBase}/records/${recordId}/workflow-actions`
	const executeWorkflowUrl = (recordId, workflowId, actionKey) => `${workflowActionsUrl(recordId)}/${workflowId}/${encodeURIComponent(actionKey)}`
	const savedWorkspace = readWorkspace()
	const state = {
		dashboard: {},
		team: { currentUid: '', members: [] },
		cases: [],
		customizing: [],
		checklists: [],
		articles: [],
		articleGroupRules: [],
		positionTypes: [],
		quantityUnits: [],
		allowedVatRates: [0, 7, 19],
		countryProfiles: [],
		caseServices: { items: [], totals: { netCents: 0, vatCents: 0, grossCents: 0 } },
		sideOrders: [], activeSideOrderId: 0, sideOrderView: 'list', expandedCaseIds: {},
		commercial: { documents: [], invoices: [], incomingInvoices: { items: [], statuses: [], classifications: [], units: [] }, billingCheck: { result: 'OK', exceptions: [] }, statuses: { service: [], billability: [] } },
		serviceDraft: {},
		contractAmendmentReason: '', contractAmendmentCaseId: 0,
		checklistAdmin: [],
		workflowAdmin: [],
		schedulePresets: [],
		scheduling: { types: [], resources: [] },
		documentTemplates: [],
		documentTemplateOptions: { files: [], subfolders: [], requiredFields: [] },
		deregistrationTemplates: [],
		caseFiles: { folders: [], documentTypes: [], files: [] },
		allCaseFiles: [],
		branches: [],
		invoiceSettings: { prefix: 'RE', pattern: '{PREFIX}-{YYYY}-{SEQ}', sequenceLength: 6, sequenceScope: 'YEAR_GLOBAL', caseReference: true, zugferdEnabled: true, zugferdVersion: '2.5.2', zugferdProfile: 'EN16931', xrechnungEnabled: false, normativeValidationRequired: false, qrEnabled: true, preview: 'RE-2026-000001' },
		assistantConfiguration: { guidedCaptureEnabled: true, speechInputEnabled: true, assistantEnabled: true, providers: { speechToText: false, textToText: false } },
		installationSettings: null, retentionPolicy: null, retentionPreview: null, auditIntegrity: null, operationsCockpit: null, backupOperations: null, onboarding: null,
		paperlessInbox: { enabled: false, mode: 'OFF', items: [], manualFallback: true }, paperlessConfiguration: null,
		assistantCapture: null,
		assistantSidebar: { open: false, command: '', caseId: Number(globalThis.localStorage?.getItem('bestatter-assistant-case-id') || 0), preview: null },
		personalDay: { openCases: [], tasksToday: [], overdueTasks: [], schedulesToday: [], nextTasks: [], counts: { openCases: 0, tasksToday: 0, overdueTasks: 0, schedulesToday: 0 } },
		caseCompleteness: null,
		systemCheck: null,
		records: { task: [], schedule: [], document: [], contact: [], case_contact: [], deregistration: [], activity: [] },
		view: savedWorkspace.view, caseTab: String(savedWorkspace.caseTab || 'overview'),
		masterTab: String(savedWorkspace.masterTab || 'Personendaten'), customizingTab: String(savedWorkspace.customizingTab || 'value-lists'),
		currentCase: null, currentCaseId: Number(savedWorkspace.caseId || 0), administrationTab: String(savedWorkspace.administrationTab || 'services'),
		serviceQuery: '', serviceCostType: 'ALL', serviceArticleGroup: 'ALL', serviceItemType: 'ALL',
		serviceSelectedOnly: false, serviceCatalogOpen: false, servicePage: 1, servicePageSize: 20, serviceCatalogScrollTop: 0, serviceCatalogDialogScrollTop: 0,
		newCase: false,
		caseCreationToken: null,
		createInFlight: false,
		showCompletedTasks: false,
		showCompletedSchedules: false,
		taskFilters: { caseId: 'ALL', branch: 'ALL', assignee: 'ALL', status: 'ALL' },
		taskSort: 'DUE_ASC',
		scheduleFilters: { caseId: 'ALL', branch: 'ALL', status: 'ALL', kind: 'ALL' },
		scheduleSort: 'DATE_ASC',
		documentFilters: { caseId: 'ALL', branch: 'ALL', status: 'ALL', title: 'ALL', query: '' },
		documentSort: 'DATE_DESC',
		checklistPanelOpen: {},
		checklistOpen: {},
		query: '',
		caseSearch: { items: [], total: 0, limit: 25, offset: 0, view: 'ALL', branch: 'ALL', sideOrders: 'ALL' },
		reportFilters: { from: '', to: '', branch: 'ALL' },
		reportSummary: null,
		valueListKey: globalThis.localStorage?.getItem('bestatter:value-list-key') || '',
		checklistAdminKey: globalThis.localStorage?.getItem('bestatter:checklist-admin-key') || '',
		workflowAdminId: Number(globalThis.localStorage?.getItem('bestatter:workflow-admin-id') || 0),
		dashboardDate: /^\d{4}-\d{2}-\d{2}$/.test(String(savedWorkspace.dashboardDate || '')) ? String(savedWorkspace.dashboardDate) : new Date().toISOString().slice(0, 10),
	}
	const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
		'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
	}[char]))
	const ui = createUi(root, esc)
	const { notifySuccess, notifyError, notifyWarning, beginLoading, confirmAction, promptAction, enhanceAccessibility } = ui
	const download = (url, filename, options = {}) => ui.download(url, filename, OC.requestToken, options)
	async function api(url, options = {}) {
		const { feedback = true, loadingLabel = 'Wird verarbeitet …', ...requestOptions } = options
		const endLoading = beginLoading(loadingLabel)
		try {
			const response = await fetch(url, {
				credentials: 'same-origin',
				headers: { requesttoken: OC.requestToken, ...(requestOptions.headers || {}) },
				...requestOptions,
			})
			const text = await response.text()
			let data = {}
			try { data = text ? JSON.parse(text) : {} } catch (_) {
				const plain = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()
				const requestId = plain.match(/Request ID:\s*([A-Za-z0-9_-]+)/i)?.[1]
				throw new Error(response.ok ? (plain || 'Ungültige Antwort der Nextcloud-App.') : `Serverfehler (HTTP ${response.status})${requestId ? ` · Request-ID ${requestId}` : ''}.`)
			}
			if (!response.ok) throw new Error(data.message || 'Die Anfrage konnte nicht verarbeitet werden.')
			const method = String(requestOptions.method || 'GET').toUpperCase()
			if (feedback !== false && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
				notifySuccess(typeof feedback === 'string' ? feedback : (method === 'DELETE' ? 'Eintrag wurde gelöscht.' : 'Änderungen wurden gespeichert.'))
			}
			return data
		} finally {
			endLoading()
		}
	}
	async function loadCaseFiles(caseId = state.currentCase?.id || 0) {
		if (!caseId) return state.caseFiles
		state.caseFiles = await api(caseFilesUrl(caseId))
		return state.caseFiles
	}
	async function loadAllCaseFiles() {
		state.allCaseFiles = (await api(allCaseFilesUrl())).files || []
		return state.allCaseFiles
	}
	async function uploadCaseFile(file, subfolder = '', documentType = 'Sonstiges') {
		const body = new FormData()
		body.append('file', file)
		body.append('subfolder', subfolder)
		body.append('documentType', documentType)
		return api(caseFilesUrl(state.currentCase.id), { method: 'POST', body })
	}
	const mainNavigation = [
		['dashboard', 'Dashboard'], ['capture', 'Schnellerfassung'], ['cases', 'Fälle'], ['task', 'Aufgaben'],
		['schedule', 'Termine'], ['document', 'Dokumente'],
		['inbox', 'Belegeingang'],
		['customizing', 'Customizing'],
		['administration', 'Administration'],
	]
	const recordLabels = { task: 'Aufgaben', schedule: 'Termine', document: 'Dokumente', contact: 'Kontakte', activity: 'Fall-Verlauf' }
	const recordCaseNumber = (item) => String(item?.caseNumber || item?.data?.caseNumber || '')
	const recordAssigneeUid = (item) => String(item?.assigneeUid || item?.data?.assigneeUid || '')
	const recordAssigneeName = (item) => String(item?.assigneeName || item?.data?.assigneeName || recordAssigneeUid(item))
	const recordCase = (item) => state.cases.find((entry) => Number(entry.id) === Number(item?.caseId)) || null
	const recordBranch = (item) => String(recordCase(item)?.branch || recordCase(item)?.masterData?.branch || '')
	const createToken = () => (globalThis.crypto?.randomUUID
		? globalThis.crypto.randomUUID()
		: `${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`)
	const sections = {
		Personendaten: [
			['Persönliche Angaben', ['salutation', 'title', 'first_name', 'additional_first_names', 'last_name', 'birth_name', 'profession']],
			['Geburt', ['date_of_birth', 'birth_place', 'birth_registry_office']],
			['Familie und Zugehörigkeit', ['gender', 'civil_status', 'religion', 'religion_disclosure', 'nationality']],
			['Ehepartner/in', ['spouse_first_name', 'spouse_last_name', 'spouse_date_of_birth', 'spouse_birth_place', 'spouse_residence', 'spouse_date_of_death', 'spouse_death_place']], ['Ehe / Lebenspartnerschaft', ['marriage_date', 'marriage_place', 'partnership_date', 'divorce_date']],
			['Ausweisdaten', ['identity_document_type', 'identity_document_number', 'identity_document_issue_date', 'identity_document_issuer']],
			['Rentenversicherung', ['pension_insurance_number', 'pension_insurance_number_2', 'pension_institution', 'pension_notes']],
		],
		Sterbedaten: [
			['Todeszeitpunkt', ['death_time_mode', 'date_of_death', 'time_of_death', 'death_time_from', 'death_time_to', 'newborn_lifetime_hours']],
			['Feststellung und Sterbeort', ['death_determination', 'manner_of_death', 'medical_examiner', 'place_of_death', 'current_body_location']],
			['Letzter Wohnsitz', ['last_residence', 'last_residence_postal_code', 'last_residence_city', 'last_residence_country']],
			['Überführung und Freigabe', ['transfer_from', 'transfer_to', 'transfer_status', 'death_certificate_status', 'release_status']],
			['Standesamt', ['registry_office', 'registry_status', 'registry_due_date', 'registry_reference']],
		],
		Betreuung: [
			['Betreuungsstatus', ['guardianship_status']],
			['Betreuerkontakt', ['guardian_contact', 'guardian_name', 'guardian_street', 'guardian_postal_city', 'guardian_country', 'guardian_phone', 'guardian_email', 'guardianship_court', 'guardianship_notes']],
		],
		'Bestattung / Grab': [
			['Bestattung', ['funeral_type', 'cemetery_contact']],
			['Grab', ['grave_number', 'grave_type', 'grave_holder', 'grave_depth', 'grave_location', 'coffin_urn_size', 'grave_inscription']],
			['Urne und Hinweise', ['urn_handover_type', 'urn_handover_date', 'funeral_notes']],
		],
		Administration: [
			['Zuständigkeit', ['responsible_employee', 'branch']],
			['Fallführung', ['status', 'notes', 'close_reason', 'closed_at']],
		],
	}
	const labels = {
		salutation: 'Anrede', title: 'Titel', first_name: 'Vorname', additional_first_names: 'Weitere Vornamen',
		last_name: 'Nachname', birth_name: 'Geburtsname', profession: 'Beruf', date_of_birth: 'Geburtsdatum',
		birth_place: 'Geburtsort', birth_registry_office: 'Geburtsstandesamt', gender: 'Geschlecht',
		civil_status: 'Familienstand', spouse_first_name: 'Vorname Ehepartner/in', spouse_last_name: 'Nachname Ehepartner/in',
		spouse_date_of_birth: 'Geburtsdatum Ehepartner/in', spouse_birth_place: 'Geburtsort Ehepartner/in', spouse_residence: 'Wohnort Ehepartner/in', spouse_date_of_death: 'Todesdatum Ehepartner/in (falls vorverstorben)', spouse_death_place: 'Sterbeort Ehepartner/in', marriage_date: 'Datum der Eheschließung', marriage_place: 'Ort der Eheschließung', partnership_date: 'Datum der Begründung der Lebenspartnerschaft', divorce_date: 'Datum der Scheidung / Aufhebung', religion: 'Religion', religion_disclosure: 'Religionsangabe',
		nationality: 'Staatsangehörigkeit', identity_document_type: 'Ausweisart', identity_document_number: 'Ausweisnummer',
		identity_document_issue_date: 'Ausstellungsdatum', identity_document_issuer: 'Ausstellende Behörde',
		pension_insurance_number: 'Postrentennummer', pension_insurance_number_2: 'Weitere Postrentennummer',
		pension_institution: 'Rentenversicherungsträger', pension_notes: 'Hinweise zur Rentenabmeldung',
		death_time_mode: 'Angabe des Todeszeitpunkts', death_determination: 'Feststellung des Todes', date_of_death: 'Sterbedatum (exakt)', time_of_death: 'Sterbezeit (exakt)',
		death_time_from: 'Sterbedatum / -zeit von', death_time_to: 'Sterbedatum / -zeit bis', newborn_lifetime_hours: 'Lebensdauer in Stunden (Neugeborene)',
		manner_of_death: 'Todesart', medical_examiner: 'Arzt / Totenbeschauer', place_of_death: 'Sterbeort / Einrichtung',
		last_residence: 'Straße / Hausnummer', last_residence_postal_code: 'PLZ', last_residence_city: 'Ort', last_residence_country: 'Land', current_body_location: 'Aufenthaltsort des Leichnams', transfer_from: 'Überführung von',
		transfer_to: 'Überführung nach', transfer_status: 'Überführungsstatus', death_certificate_status: 'Todesbescheinigung',
		release_status: 'Ärztliche / behördliche Freigabe', registry_office: 'Standesamt', registry_status: 'Status Sterbefallanzeige',
		registry_due_date: 'Frist der Sterbefallanzeige', registry_reference: 'Aktenzeichen / Urkundenreferenz',
		guardianship_status: 'Betreuung vorhanden?', guardian_contact: 'Kontakt aus Adressbuch', guardian_name: 'Name', guardian_street: 'Straße',
		guardian_postal_city: 'PLZ / Ort', guardian_country: 'Land', guardian_phone: 'Telefon', guardian_email: 'E-Mail', guardianship_court: 'Betreuungsgericht',
		guardianship_notes: 'Hinweise', funeral_type: 'Bestattungsart', cemetery_contact: 'Friedhof aus Nextcloud-Kontakten',
		grave_number: 'Grabnummer', grave_type: 'Grabart', grave_holder: 'Grabinhaber', grave_depth: 'Grabtiefe', grave_location: 'Grablage',
		coffin_urn_size: 'Sarg- / Urnengröße', grave_inscription: 'Inschrift auf Grabmal', urn_handover_type: 'Übergabe der Urne',
		urn_handover_date: 'Urnenübergabedatum', funeral_notes: 'Bemerkung', responsible_employee: 'Verantwortlicher Mitarbeiter',
		branch: 'Niederlassung', status: 'Fallstatus', notes: 'Interne Notizen', close_reason: 'Abschlussgrund', closed_at: 'Abgeschlossen am',
	}
	const listMap = {
		salutation: 'SALUTATION', title: 'TITLE', gender: 'GENDER', civil_status: 'CIVIL_STATUS', religion: 'RELIGION',
		religion_disclosure: 'YES_NO', identity_document_type: 'IDENTITY_DOCUMENT_TYPE', death_determination: 'DEATH_DETERMINATION',
		manner_of_death: 'MANNER_OF_DEATH', transfer_status: 'TRANSFER_STATUS', death_certificate_status: 'DEATH_CERTIFICATE_STATUS',
		release_status: 'RELEASE_STATUS', registry_status: 'REGISTRY_STATUS', guardianship_status: 'GUARDIANSHIP_STATUS',
		funeral_type: 'FUNERAL_TYPE', grave_type: 'GRAVE_TYPE', grave_depth: 'GRAVE_DEPTH', grave_location: 'GRAVE_LOCATION',
		coffin_urn_size: 'COFFIN_URN_SIZE', urn_handover_type: 'URN_HANDOVER_TYPE', branch: 'BRANCH',
	}
	const fixedOptions = { death_time_mode: [['EXAKT', 'Exakter Zeitpunkt'], ['ZEITRAUM', 'Zeitraum von/bis']] }
	const context = {
		root, urls, apiBase, appVersion, state, mainNavigation, recordLabels, sections, labels, listMap, fixedOptions, api, download, ui, notifySuccess, notifyError, notifyWarning, confirmAction, promptAction, title, render, loadRecordType, loadSideOrders, loadCaseFiles, loadAllCaseFiles, uploadCaseFile, syncGroupware, refreshGroupwareSilently, bind, bindMasterForm, bindOrderForm, bindCommercial, showNewCase, openDeepLinkedTask, recordUrl, recordItemUrl, articleUrl, caseServicesUrl, sideOrdersUrl, commercialUrl, checklistUrl, checklistManageUrl, workflowUrl, schedulePresetsUrl, schedulingUrl, documentTemplateUrl, documentTemplateOptionsUrl, deregistrationTemplateUrl, deregistrationUrl, documentUrl, caseFilesUrl, allCaseFilesUrl, branchUrl, invoiceSettingsUrl, workflowActionsUrl, executeWorkflowUrl, esc, recordCaseNumber, recordAssigneeUid, recordAssigneeName, recordCase, recordBranch, createToken,
	}
	const assistantModule = createAssistantModule(context)
	Object.assign(context, assistantModule)
	const { guidedCaptureView, assistantSidebarView, bindAssistant, initializeAssistantCapture } = assistantModule
	const casesModule = createCasesModule(context)
	Object.assign(context, casesModule)
	const { currentUser, assigneeOptions, listOptions, contactsByCategory, fieldType, field, masterDataForm, caseList, overview, dashboardView, caseOverview, sideOrdersPanel, checklistPanel, loadCaseSearch, bindCaseSearch, bindSideOrders } = casesModule
	const recordsModule = createRecordsModule(context)
	Object.assign(context, recordsModule)
	const { filteredTaskRecords, filterOptions, taskToolbar, selectableRecordValues, recordToolbar, filteredRecordRecords, recordMeta, recordBadges, recordRows, recordsView, documentOutputRow, normalizeDateTime, caseOptions, showRecordForm, showEditRecord, saveRecord, showContactForm } = recordsModule
	const commercialModule = createCommercialModule(context)
	Object.assign(context, commercialModule)
	const { orderField, funeralScope, hydrateServiceDraft, loadCaseServices, serviceConflictMessages, saveCaseServices, serviceSelectionPanel, orderPanel, bindServiceSelection, showInvoiceDraftForm, finalizeCommercialDocument, relationOptions, orderTypes, commissioningTypes, euro, formatMoney, costTypeLabels, unitLabels, quantityStep, formatQuantity, serviceSaveTimer } = commercialModule
	const documentsModule = createDocumentsModule(context)
	Object.assign(context, documentsModule)
	const { caseContactsPanel, showCaseContactForm, expandDeregistration, deliveryChannels, showTextPreview, showLetterPreview, deregistrationPanel, showDeregistrationForm, showDeregistrationTransitionForm, documentPanel, showFilePreview, showOrderDocumentPreview, showDocumentDialog, caseDetail, showRetentionHoldDialog } = documentsModule
	const administrationModule = createAdministrationModule(context)
	Object.assign(context, administrationModule)
	const { branchesView, invoiceSettingsView, assistantSettingsView, reportingView, loadReportingSummary, loadOperationsCockpit, bindOperationsCockpit, bindReporting, loadCommercial, commercialVersionPanel, financesPanel, administration, articleOptions, packageComponentRows, supplierOptions, showArticleForm, bindArticleAdministration, bindIncomingInvoices, bindAssistantSettings, loadSystemCheck, bindSystemCheck, loadBackups, bindBackupRestore, loadOnboarding, bindOnboarding } = administrationModule
	const customizingModule = createCustomizingModule(context)
	Object.assign(context, customizingModule)
	const { documentTemplatesView, deregistrationTemplatesView, workflowAdminView, workflowActionRows, showWorkflowForm, customizing, bindCustomizing, bindConfigurationAdministration, bindWorkflowAdmin } = customizingModule
	const paperlessModule = createPaperlessModule(context)
	Object.assign(context, paperlessModule)
	const { loadPaperlessInbox, loadPaperlessConfiguration, paperlessInboxView, bindPaperlessInbox, bindPaperlessSettings } = paperlessModule
	function title() {
		if (state.view === 'case-detail') return 'Fallakte'
		return mainNavigation.find((item) => item[0] === state.view)?.[1] || 'Bestatter'
	}
	let renderedLocationKey = ''
	const scrollPositions = new Map()
	function renderLocationKey() {
		const parts = [state.view]
		if (state.view === 'case-detail') parts.push(String(state.currentCase?.id || 0), state.caseTab || '', state.masterTab || '', String(state.activeSideOrderId || 0), state.sideOrderView || '')
		if (state.view === 'customizing') parts.push(state.customizingTab || '')
		if (state.view === 'administration') parts.push(state.administrationTab || '')
		return parts.join(':')
	}
	function render() {
		const nextLocationKey = renderLocationKey()
		const currentContent = root.querySelector('.bp-content')
		if (renderedLocationKey && currentContent) scrollPositions.set(renderedLocationKey, { top: currentContent.scrollTop || 0, left: currentContent.scrollLeft || 0 })
		const scrollPosition = scrollPositions.get(nextLocationKey) || null
		let content = dashboardView()
		if (state.view === 'capture') content = guidedCaptureView()
		else if (state.view === 'cases') content = caseList()
		else if (state.view === 'case-detail') content = caseDetail()
		else if (state.view === 'inbox') content = paperlessInboxView()
		else if (recordLabels[state.view]) content = recordsView(state.view)
		else if (state.view === 'customizing') content = customizing()
		else if (state.view === 'administration') content = administration()
		const setupNotice = Boolean(state.team.isBestatterAdmin && state.onboarding?.ready === false)
		root.innerHTML = `<div class="bp-app-shell"><nav class="bp-app-nav" aria-label="Bestatter-Navigation">${mainNavigation.filter((item) => (item[0] !== 'capture' || state.assistantConfiguration.guidedCaptureEnabled) && (state.team.isBestatterAdmin || !['customizing', 'administration'].includes(item[0]))).map((item) => `<button class="${state.view === item[0] ? 'active' : ''}" data-view="${item[0]}">${item[1]}</button>`).join('')}</nav><main class="bp-main">${setupNotice ? '<aside class="bp-warning"><b>Einrichtungshinweis:</b> Mindestens eine Bereitschaftsprüfung ist noch offen. Der laufende Fachbetrieb bleibt zugänglich; Details stehen unter Administration → Ersteinrichtung.</aside>' : ''}<header class="bp-topbar"><div><p class="bp-eyebrow">Arbeitsbereich</p><h1>${esc(title())}</h1></div><div class="bp-user">${esc(currentUser() || 'Angemeldet')}</div></header><section class="bp-content"><div class="bp-workspace bp-view-${esc(state.view)}">${content}</div></section></main></div>${assistantSidebarView()}`
		renderedLocationKey = nextLocationKey
		rememberWorkspace(state)
		bind()
		enhanceAccessibility(root)
		if (scrollPosition) requestAnimationFrame(() => {
			const refreshedContent = root.querySelector('.bp-content')
			if (refreshedContent && renderedLocationKey === nextLocationKey) refreshedContent.scrollTo(scrollPosition)
		})
	}
	async function loadRecordType(type, caseId = 0) {
		state.records[type] = await api(`${recordUrl(type)}${caseId ? `?caseId=${caseId}` : ''}`)
	}
	async function loadSideOrders(caseId = 0) {
		state.sideOrders = caseId ? await api(sideOrdersUrl(caseId), { feedback: false }) : []
	}
	async function syncGroupware(type) {
		try {
			const result = await api(`${apiBase}/groupware/sync?type=${encodeURIComponent(type)}`, { method: 'POST', feedback: false, loadingLabel: 'Nextcloud-Daten werden synchronisiert …' })
			if (result.errors?.length) notifyWarning(`Synchronisation teilweise fehlgeschlagen: ${result.errors.join(' · ')}`)
			else if (Number(result.removedLocalMirrors || 0) > 0) notifyWarning(`${result.removedLocalMirrors} irrtümlich übernommene Einträge aus ausgeschlossenen Kalendern wurden nur aus der Bestatter-App entfernt. Die Nextcloud-Kalendereinträge bleiben unverändert.`); else notifySuccess(`Synchronisation mit Nextcloud wurde abgeschlossen.${Number(result.ignored || 0) > 0 ? ` ${result.ignored} fremde Einträge wurden ignoriert.` : ''}`)
		} catch (error) {
			notifyError(error.message)
		}
		await loadRecordType(type, state.view === 'case-detail' ? state.currentCase.id : 0)
		render()
	}
	let backgroundSyncInFlight = false
	async function refreshGroupwareSilently() {
		if (backgroundSyncInFlight || state.view === 'customizing' || root.querySelector('.bp-modal, form:focus-within, .is-dirty')) return
		backgroundSyncInFlight = true
		try {
			await Promise.allSettled([
				api(`${apiBase}/groupware/sync?type=task`, { method: 'POST', feedback: false }),
				api(`${apiBase}/groupware/sync?type=schedule`, { method: 'POST', feedback: false }),
			])
			const activeCaseId = state.view === 'case-detail' ? Number(state.currentCase?.id || 0) : 0
			await Promise.all([loadRecordType('task', activeCaseId), loadRecordType('schedule', activeCaseId)])
			state.personalDay = await api(`${apiBase}/dashboard/personal-day`, { feedback: false })
			// Eine während der Synchronisation begonnene Eingabe darf nicht durch
			// den abschließenden Neuaufbau der Ansicht verloren gehen.
			if (root.querySelector('.bp-modal, form:focus-within, .is-dirty')) return
			render()
		} finally {
			backgroundSyncInFlight = false
		}
	}
	function bind() {
		root.querySelectorAll('[data-dashboard-record-id]').forEach((button) => button.addEventListener('click', async () => {
			const type = button.dataset.dashboardRecordType === 'schedule' ? 'schedule' : 'task'
			const id = Number(button.dataset.dashboardRecordId || 0)
			await loadRecordType(type)
			const item = (state.records[type] || []).find((record) => Number(record.id) === id)
			if (!item) { notifyWarning(`${type === 'schedule' ? 'Der Termin' : 'Die Aufgabe'} wurde nicht gefunden oder ist nicht mehr zugänglich.`); return }
			showRecordForm(type, Number(item.caseId || 0), item)
		}))
		root.querySelectorAll('[data-view]').forEach((button) => button.addEventListener('click', async () => {
			state.view = button.dataset.view
			if (state.view === 'cases') await loadCaseSearch(true)
			if (recordLabels[state.view]) await loadRecordType(state.view)
			if (state.view === 'document') await loadAllCaseFiles()
			if (state.view === 'inbox') await loadPaperlessInbox()
			if (state.view === 'task') state.checklists = await api(checklistUrl())
			if (state.view === 'administration') { const catalog = await api(articleUrl()); state.articles = catalog.articles || []; state.articleGroupRules = catalog.groupRules || []; state.allowedVatRates = catalog.allowedVatRates || state.allowedVatRates; state.countryProfiles = catalog.countryProfiles || state.countryProfiles; state.branches = await api(branchUrl()); if (state.administrationTab === 'system') await loadSystemCheck(); if (state.administrationTab === 'cockpit') await loadOperationsCockpit(); if (state.administrationTab === 'backup') await loadBackups(); if (state.administrationTab === 'paperless') await loadPaperlessConfiguration() }
			render()
		}))
		const openCase = async (caseId, sideOrderId = 0) => { state.currentCase = await api(`${urls.cases}/${caseId}`); state.currentCaseId = Number(caseId); state.caseCompleteness = await api(`${apiBase}/cases/${caseId}/completeness`); await loadSideOrders(state.currentCase.id); state.activeSideOrderId = Number(sideOrderId); state.sideOrderView = sideOrderId ? 'header' : 'list'; state.assistantSidebar.caseId = Number(caseId); globalThis.localStorage?.setItem('bestatter-assistant-case-id', String(state.assistantSidebar.caseId)); state.view = 'case-detail'; state.caseTab = sideOrderId ? 'side-orders' : 'overview'; render() }
		root.querySelectorAll('tr[data-case-id]').forEach((row) => {
			const open = async () => openCase(row.dataset.caseId)
			row.addEventListener('click', open)
			row.addEventListener('keydown', (event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); open() } })
		})
		root.querySelectorAll('[data-expand-case]').forEach((button)=>button.addEventListener('click',(event)=>{event.stopPropagation();const id=Number(button.dataset.expandCase);state.expandedCaseIds[id]=!state.expandedCaseIds[id];render()}))
		root.querySelectorAll('[data-open-side-order]').forEach((button)=>button.addEventListener('click',async(event)=>{event.stopPropagation();await openCase(button.dataset.sideOrderCase,button.dataset.openSideOrder)}))
		root.querySelectorAll('[data-case-tab]').forEach((button) => button.addEventListener('click', async () => {
			state.activeSideOrderId = 0
			state.sideOrderView = 'list'
			state.caseTab = button.dataset.caseTab
			if (['overview', 'side-orders'].includes(state.caseTab)) await Promise.all([api(`${apiBase}/cases/${state.currentCase.id}/completeness`, { feedback: false }).then((result) => { state.caseCompleteness = result }), loadSideOrders(state.currentCase.id)])
			if (['task', 'schedule', 'document', 'history'].includes(state.caseTab)) await loadRecordType(state.caseTab === 'history' ? 'activity' : state.caseTab, state.currentCase.id)
			if (state.caseTab === 'document') await loadCaseFiles(state.currentCase.id)
			if (state.caseTab === 'contact') await Promise.all([loadRecordType('contact'), loadRecordType('case_contact', state.currentCase.id)])
			if (state.caseTab === 'deregistration') await Promise.all([loadRecordType('contact'), loadRecordType('document', state.currentCase.id), loadCaseFiles(state.currentCase.id), api(deregistrationUrl(state.currentCase.id)).then((items) => { state.records.deregistration = items }), api(deregistrationTemplateUrl()).then((items) => { state.deregistrationTemplates = items })])
			if (state.caseTab === 'task') state.checklists = await api(checklistUrl())
			if (['order', 'services', 'finances'].includes(state.caseTab)) await loadCommercial()
			render()
		}))
		bindSideOrders()
		root.querySelectorAll('[data-master-tab]').forEach((button) => button.addEventListener('click', () => { state.masterTab = button.dataset.masterTab; render() }))
		root.querySelectorAll('[data-customizing-tab]').forEach((button) => button.addEventListener('click', async () => {
			state.customizingTab = button.dataset.customizingTab
			if (state.customizingTab === 'checklists') state.checklistAdmin = await api(checklistManageUrl())
			if (state.customizingTab === 'workflows') state.workflowAdmin = await api(workflowUrl())
			if (state.customizingTab === 'scheduling') state.scheduling = await api(schedulingUrl())
			if (state.customizingTab === 'documents') [state.documentTemplates, state.documentTemplateOptions] = await Promise.all([api(documentTemplateUrl()), api(documentTemplateOptionsUrl())])
			if (state.customizingTab === 'deregistration') state.deregistrationTemplates = await api(deregistrationTemplateUrl())
			render()
		}))
		bindCaseSearch()
		document.getElementById('new-case')?.addEventListener('click', () => showNewCase())
		document.getElementById('delete-case')?.addEventListener('click', async () => {
			if (!await confirmAction(`Fall ${state.currentCase.caseNumber} wirklich löschen?`, { title: 'Fall löschen', confirmLabel: 'Endgültig löschen', danger: true })) return
			await api(`${urls.cases}/${state.currentCase.id}`, { method: 'DELETE' })
			state.cases = await api(urls.cases); state.currentCase = null; state.currentCaseId = 0; state.view = 'cases'; render()
		})
		document.getElementById('export-case')?.addEventListener('click', async () => {
			try { await download(`${apiBase}/cases/${state.currentCase.id}/export.json`, `Bestatter-Fallexport-${state.currentCase.caseNumber || state.currentCase.id}.json`) }
			catch (error) { notifyError(error.message) }
		})
		document.getElementById('toggle-retention-hold')?.addEventListener('click', showRetentionHoldDialog)
		document.getElementById('show-completed-tasks')?.addEventListener('change', (event) => { state.showCompletedTasks = event.target.checked; render() })
		document.getElementById('show-completed-schedules')?.addEventListener('change', (event) => { state.showCompletedSchedules = event.target.checked; render() })
		root.querySelectorAll('[data-task-filter]').forEach((select) => select.addEventListener('change', () => { state.taskFilters[select.dataset.taskFilter] = select.value; render() }))
		document.getElementById('task-sort')?.addEventListener('change', (event) => { state.taskSort = event.target.value; render() })
		root.querySelectorAll('[data-record-filter]').forEach((select) => select.addEventListener('change', () => {
			const filters = select.dataset.recordFilterType === 'schedule' ? state.scheduleFilters : state.documentFilters
			filters[select.dataset.recordFilter] = select.value
			render()
		}))
		document.querySelector('[data-document-query]')?.addEventListener('input', (event) => {
			state.documentFilters.query = event.target.value
			clearTimeout(event.target._renderTimer)
			event.target._renderTimer = setTimeout(() => { render(); const input = document.querySelector('[data-document-query]'); input?.focus(); input?.setSelectionRange(input.value.length, input.value.length) }, 180)
		})
		root.querySelectorAll('[data-record-sort]').forEach((select) => select.addEventListener('change', () => {
			if (select.dataset.recordSort === 'schedule') state.scheduleSort = select.value
			if (select.dataset.recordSort === 'document') state.documentSort = select.value
			render()
		}))
		document.getElementById('sync-groupware')?.addEventListener('click', (event) => syncGroupware(event.currentTarget.dataset.recordType))
		document.getElementById('new-record')?.addEventListener('click', (event) => showRecordForm(event.currentTarget.dataset.recordType, Number(event.currentTarget.dataset.caseId || 0)))
		root.querySelectorAll('.edit-record,[data-record-id]').forEach((button) => button.addEventListener('click', (event) => { event.stopPropagation(); showEditRecord(button.dataset.recordType, Number(button.dataset.recordId)) }))
		root.querySelectorAll('[data-task-complete]').forEach((input) => input.addEventListener('change', async () => {
			const item = (state.records.task || []).find((record) => record.id === Number(input.dataset.taskComplete)); if (!item) return
			const previousStatus = item.status
			const row = input.closest('[data-record-row]')
			input.disabled = true
			item.status = input.checked ? 'ERLEDIGT' : 'OFFEN'
			try {
				await saveRecord(item, 'task')
				if (item.status === 'ERLEDIGT' && !state.showCompletedTasks && state.taskFilters.status !== 'ERLEDIGT') {
					row?.remove()
					if (!root.querySelector('.bp-record-row')) root.querySelector('.bp-record-panel')?.insertAdjacentHTML('beforeend', '<p class="bp-empty">Keine passenden Einträge vorhanden.</p>')
				} else if (row) {
					const badges = row.querySelector('.bp-record-badges')
					if (badges) badges.innerHTML = recordBadges(item, 'task')
					row.querySelector('.bp-record-content small').innerHTML = recordMeta(item, 'task')
					input.disabled = false
				}
			} catch (error) {
				item.status = previousStatus
				input.checked = previousStatus === 'ERLEDIGT'
				input.disabled = false
				notifyError(error.message)
			}
		}))
		root.querySelectorAll('[data-checklist-panel]').forEach((details) => details.addEventListener('toggle', () => { state.checklistPanelOpen[details.dataset.checklistPanel] = details.open; const label = details.querySelector(':scope > summary em'); if (label) label.textContent = details.open ? 'Einklappen' : 'Einblenden' }))
		root.querySelectorAll('[data-checklist-details]').forEach((details) => details.addEventListener('toggle', () => { state.checklistOpen[details.dataset.checklistDetails] = details.open }))
		root.querySelectorAll('.apply-checklist').forEach((button) => button.addEventListener('click', async () => {
			const selected = [...root.querySelectorAll(`.checklist-item[data-checklist="${button.dataset.checklist}"]:checked`)].map((input) => input.value)
			if (!selected.length) return
			await api(`${apiBase}/cases/${state.currentCase.id}/checklists/${encodeURIComponent(button.dataset.checklist)}/apply`, { method: 'POST', body: new URLSearchParams({ selected: JSON.stringify(selected) }) })
			await loadRecordType('task', state.currentCase.id); render()
		}))
		document.getElementById('assign-case-contact')?.addEventListener('click', () => showCaseContactForm())
		root.querySelectorAll('.edit-case-contact').forEach((button) => button.addEventListener('click', () => showCaseContactForm((state.records.case_contact || []).find((entry) => entry.id === Number(button.dataset.id)))))
		document.getElementById('new-deregistration')?.addEventListener('click', () => showDeregistrationForm())
		root.querySelectorAll('.edit-deregistration').forEach((button) => button.addEventListener('click', () => showDeregistrationForm((state.records.deregistration || []).find((entry) => entry.id === Number(button.dataset.id)))))
		root.querySelectorAll('.preview-deregistration').forEach((button) => button.addEventListener('click', async () => { const item = (state.records.deregistration || []).find((entry) => entry.id === Number(button.dataset.id)); if(!item)return; const preview=await api(`${deregistrationUrl(state.currentCase.id)}/preview`,{method:'POST',body:new URLSearchParams({deregistration:JSON.stringify(item.data||{})})}); if(preview.previewMode==='LETTER')showLetterPreview(preview);else showTextPreview(item.title,preview.subject,preview.body,preview.missingFields) }))
		root.querySelectorAll('.transition-deregistration').forEach((button) => button.addEventListener('click', async () => { const item=(state.records.deregistration||[]).find((entry)=>entry.id===Number(button.dataset.id)); if(!item)return; try { await showDeregistrationTransitionForm(item,button.dataset.status) } catch(error){ notifyError(error.message) } }))
		root.querySelectorAll('.create-document').forEach((button)=>button.addEventListener('click',()=>showDocumentDialog(button.dataset.templateKey)))
		root.querySelectorAll('.open-nextcloud-file').forEach((button)=>button.addEventListener('click',()=>window.open(OC.generateUrl(`/f/${button.dataset.fileId}`),'_blank','noopener')))
		root.querySelectorAll('.bp-document-thumb img').forEach((image) => { const unavailable = () => { image.hidden = true; image.closest('.bp-document-thumb')?.classList.add('preview-unavailable') }; if (image.complete && image.naturalWidth === 0) unavailable(); else image.addEventListener('error', unavailable, { once: true }) })
		root.querySelectorAll('.preview-nextcloud-file').forEach((button)=>button.addEventListener('click',()=>showFilePreview(button.dataset.fileId, button.getAttribute('aria-label') || 'Dokumentvorschau')))
		document.getElementById('upload-case-documents')?.addEventListener('click', () => document.getElementById('case-document-files')?.click())
		document.getElementById('case-document-files')?.addEventListener('change', async (event) => {
			const files = [...event.target.files]
			if (!files.length) return
			const button = document.getElementById('upload-case-documents'); const status = document.getElementById('case-upload-state')
			button.disabled = true; status.textContent = `${files.length} Datei(en) werden hochgeladen …`; status.dataset.state = 'saving'
			try {
				for (const file of files) await uploadCaseFile(file, document.getElementById('case-upload-folder').value, document.getElementById('case-upload-type').value)
				await Promise.all([loadCaseFiles(state.currentCase.id), loadRecordType('document', state.currentCase.id)])
				render()
			} catch (error) { status.textContent = error.message; status.dataset.state = 'error'; button.disabled = false }
		})
		root.querySelectorAll('[data-administration-tab]').forEach((button) => button.addEventListener('click', async () => { state.administrationTab = button.dataset.administrationTab; if (state.administrationTab === 'onboarding') await loadOnboarding(); if (state.administrationTab === 'system') await loadSystemCheck(); if (state.administrationTab === 'cockpit') await loadOperationsCockpit(); if (state.administrationTab === 'backup') await loadBackups(); if (state.administrationTab === 'reports') await loadReportingSummary(); if (state.administrationTab === 'paperless') await loadPaperlessConfiguration(); render() }))
		root.querySelectorAll('.bp-add-contact').forEach((button) => button.addEventListener('click', () => showContactForm(button.dataset.contactCategory, button.dataset.contactTarget)))
		bindMasterForm()
		bindOrderForm()
		bindCommercial()
		bindServiceSelection()
		bindArticleAdministration(); bindIncomingInvoices()
		bindAssistantSettings()
		bindSystemCheck()
		bindBackupRestore()
		bindOnboarding()
		bindOperationsCockpit()
		bindPaperlessInbox(); bindPaperlessSettings()
		bindReporting()
		bindCustomizing()
		bindWorkflowAdmin()
		bindConfigurationAdministration()
		bindAssistant()
		root.querySelectorAll('[data-calendar-date]').forEach((button) => button.addEventListener('click', () => { state.dashboardDate = button.dataset.calendarDate; render() }))
	}
	function bindMasterForm() {
		const form = document.getElementById('case-form'); if (!form) return
		const stateLabel = document.getElementById('case-save-state'); let timer = null
		const save = async () => {
			if (state.createInFlight) return
			const formData = Object.fromEntries(new FormData(form))
			const data = { ...(state.currentCase.masterData || {}), ...formData }
			if (!data.first_name || !data.last_name) { stateLabel.textContent = 'Vor- und Nachname sind erforderlich.'; stateLabel.dataset.state = 'error'; return }
			stateLabel.textContent = 'Speichert …'; stateLabel.dataset.state = 'saving'
			state.createInFlight = state.newCase
			try {
				const result = await api(state.newCase ? urls.cases : `${urls.cases}/${state.currentCase.id}/master-data`, { method: state.newCase ? 'POST' : 'PUT', feedback: false, body: new URLSearchParams({ masterData: JSON.stringify(data), firstName: data.first_name, lastName: data.last_name, creationToken: state.caseCreationToken || '' }) })
				if (state.newCase) { state.newCase = false; state.caseCreationToken = null; state.currentCase = await api(`${urls.cases}/${result.id}`); state.cases = await api(urls.cases) } else state.currentCase = result
				state.caseCompleteness = await api(`${apiBase}/cases/${state.currentCase.id}/completeness`, { feedback: false })
				const warnings = Array.isArray(state.currentCase.validationWarnings) ? state.currentCase.validationWarnings : []; stateLabel.textContent = warnings.length ? `Gespeichert – Hinweis: ${warnings.join(' ')}` : 'Gespeichert'; stateLabel.dataset.state = warnings.length ? 'warning' : 'saved'
			} catch (error) { stateLabel.textContent = error.message; stateLabel.dataset.state = 'error' } finally { state.createInFlight = false }
		}
		form.querySelectorAll('input,select,textarea').forEach((input) => ['input', 'change', 'blur'].forEach((name) => input.addEventListener(name, () => { clearTimeout(timer); timer = setTimeout(save, name === 'blur' ? 150 : 550) })))
		;['guardianship_status', 'death_time_mode', 'civil_status'].forEach((name) => form.elements[name]?.addEventListener('change', () => {
			state.currentCase.masterData = { ...(state.currentCase.masterData || {}), ...Object.fromEntries(new FormData(form)) }
			clearTimeout(timer)
			timer = setTimeout(async () => { await save(); render() }, 50)
		}))
	}
	function bindOrderForm() {
		const form = document.getElementById('order-form')
		if (!form) return
		const status = document.getElementById('order-save-state')
		let timer = null
		const save = async (signed = false) => {
			const data = Object.fromEntries(new FormData(form))
			delete data.order_mode_choice // reine UI-Auswahl; fachlich gilt order_mode
			data.order_mode = form.elements.order_mode.value
			data.invoice_same_as_client = form.elements.invoice_same_as_client?.checked ? 'JA' : 'NEIN'
			if (data.invoice_same_as_client === 'JA') {
				for (const field of ['salutation', 'name', 'first_name', 'relation', 'street', 'postal_city', 'country', 'phone', 'mobile', 'email']) data[`invoice_${field}`] = data[`order_client_${field}`] || ''
			}
			if (signed) {
				data.order_signature_name = data.order_client_name || currentUser()
				data.order_signature_date = new Date().toISOString()
			}
			for (const name of ['order_client_email', 'invoice_email']) {
				const input = form.elements[name]
				if (input && input.value && !input.checkValidity()) {
					status.textContent = `Bitte im Feld ${input.closest('label')?.querySelector('span')?.textContent || 'E-Mail'} eine gültige Adresse eingeben.`
					status.dataset.state = 'error'
					return false
				}
			}
			status.textContent = 'Speichert ...'
			status.dataset.state = 'saving'
			try {
				const masterData = { ...(state.currentCase.masterData || {}), ...data }
				state.currentCase = await api(`${urls.cases}/${state.currentCase.id}/master-data`, { method: 'PUT', feedback: false, body: new URLSearchParams({ masterData: JSON.stringify(masterData), firstName: state.currentCase.firstName, lastName: state.currentCase.lastName }) })
				status.textContent = signed ? 'Digital best\u00e4tigt und gespeichert.' : 'Gespeichert'
				status.dataset.state = 'saved'
				return true
			} catch (error) {
				status.textContent = error.message
				status.dataset.state = 'error'
				return false
			}
		}
		form._saveOrder = save
		form.querySelectorAll('[name="order_mode_choice"]').forEach((modeInput) => modeInput.addEventListener('change', async (event) => {
			const previousMode = form.elements.order_mode.value
			const nextMode = event.target.value
			const frozenQuote = (state.commercial.documents || []).find((item) => item.documentType === 'QUOTE')
			if (nextMode === 'A' && previousMode === 'KVA' && frozenQuote) {
				form.querySelector(`[name="order_mode_choice"][value="${previousMode}"]`).checked = true
				notifyWarning('Der festgeschriebene KVA darf nur über „Einmalig in Auftrag übernehmen“ in einen Auftrag umgewandelt werden.')
				return
			}
			const formData = Object.fromEntries(new FormData(form))
			delete formData.order_mode_choice // nicht als Stammdatum persistieren
			const data = { ...(state.currentCase.masterData || {}), ...formData, order_mode: nextMode }
			if (nextMode === 'A' && previousMode === 'KVA') {
				data.kva_number = data.order_number
				data.kva_date = data.kva_date || new Date().toISOString().slice(0, 10)
				data.order_number = `A-${state.currentCase.caseNumber}`
				data.order_date = new Date().toISOString().slice(0, 10)
			} else if (!data.order_number || data.order_number.startsWith(`${previousMode}-`)) {
				data.order_number = `${nextMode}-${state.currentCase.caseNumber}`
			}
			state.currentCase.masterData = data
			form.elements.order_mode.value = data.order_mode
			await save()
			render()
		}))
		form.elements.invoice_same_as_client?.addEventListener('change', (event) => {
			form.querySelector('.bp-invoice-fields').classList.toggle('bp-hidden', event.target.checked)
			clearTimeout(timer); timer = setTimeout(save, 150)
		})
		form.elements.payment_method?.addEventListener('change', (event) => {
			form.querySelector('#sepa-direct-debit-fields')?.classList.toggle('bp-hidden', event.target.value !== 'SEPA_DIRECT_DEBIT')
			clearTimeout(timer); timer = setTimeout(save, 150)
		})
		form.querySelectorAll('input:not([name="order_mode_choice"]),select:not([name="order_status"]),textarea').forEach((input) => ['input', 'change', 'blur'].forEach((eventName) => input.addEventListener(eventName, () => { clearTimeout(timer); timer = setTimeout(save, eventName === 'blur' ? 150 : 550) })))
		form.elements.order_status?.addEventListener('change', async (event) => {
			const selected = event.target.value, mode = form.elements.order_mode.value
			const finalizationType = mode === 'KVA' && selected === 'KVA versendet' ? 'QUOTE' : mode === 'A' && selected === 'beauftragt' ? 'ORDER' : ''
			if (finalizationType) {
				event.target.value = state.caseServices?.contractProtection?.effectiveOrderStatus || state.currentCase.masterData?.order_status || 'Entwurf'
				await finalizeCommercialDocument(finalizationType); return
			}
			clearTimeout(timer); timer = setTimeout(save, 50)
		})
		document.getElementById('sign-order').onclick = async () => { if (await confirmAction('Den Auftrag digital bestätigen?', { title: 'Auftrag bestätigen', confirmLabel: 'Bestätigen' }) && await save(true)) render() }
		document.getElementById('preview-order-docs').onclick = async (event) => {
			const button = event.currentTarget; button.disabled = true
			try {
				if (!await save()) return
				await saveCaseServices(); await loadRecordType('document', state.currentCase.id)
				await showOrderDocumentPreview()
			} catch (error) { notifyError(error.message) } finally { if (button.isConnected) button.disabled = false }
		}
	}
	function bindCommercial() {
		document.getElementById('back-to-side-orders-finances')?.addEventListener('click', () => { state.activeSideOrderId = 0; state.caseTab = 'side-orders'; state.sideOrderView = 'list'; render() })
		document.getElementById('freeze-quote')?.addEventListener('click', () => finalizeCommercialDocument('QUOTE'))
		document.getElementById('freeze-order')?.addEventListener('click', () => finalizeCommercialDocument('ORDER'))
		root.querySelectorAll('.convert-quote').forEach((button) => button.addEventListener('click', async () => {
			if (!await confirmAction('Diesen festgeschriebenen KVA jetzt einmalig und unverändert in den verbindlichen Auftrag übernehmen? Diese Aktion kann nicht wiederholt werden.', { title: 'KVA in Auftrag übernehmen', confirmLabel: 'Einmalig übernehmen' })) return
			try {
				button.disabled = true
				await api(`${commercialUrl(state.currentCase.id)}/quotes/${button.dataset.quoteId}/order`, { method: 'POST', body: new URLSearchParams({ data: JSON.stringify({ number: `A-${state.currentCase.caseNumber}`, commissioningType: state.currentCase.masterData?.commissioning_type || 'SCHRIFTLICH' }) }) })
				state.currentCase = await api(`${urls.cases}/${state.currentCase.id}`)
				await loadCommercial(); render()
			} catch (error) { notifyError(error.message) }
		}))
		root.querySelectorAll('.save-lifecycle').forEach((button) => button.addEventListener('click', async () => {
			const id = Number(button.dataset.serviceId)
			try {
				await api(`${commercialUrl(state.currentCase.id)}/services/${id}`, { method: 'PUT', body: new URLSearchParams({ data: JSON.stringify({ performedQuantity: root.querySelector(`[data-performed="${id}"]`).value, status: root.querySelector(`[data-service-status="${id}"]`).value, billability: root.querySelector(`[data-billability="${id}"]`).value, reason: root.querySelector(`[data-service-reason="${id}"]`).value }) }) })
				await loadCommercial(); render()
			} catch (error) { notifyError(error.message) }
		}))
		document.getElementById('run-billing-check')?.addEventListener('click', async () => { const sideOrderQuery = Number(state.activeSideOrderId || 0) > 0 ? `?sideOrderId=${Number(state.activeSideOrderId)}` : ''; state.commercial.billingCheck = await api(`${commercialUrl(state.currentCase.id)}/billing-check${sideOrderQuery}`, { method: 'POST' }); render() })
		root.querySelectorAll('.create-invoice').forEach((invoiceButton) => invoiceButton.addEventListener('click', async () => {
			showInvoiceDraftForm(invoiceButton.dataset.invoiceType || 'PARTIAL')
		})); root.querySelectorAll('.transition-invoice').forEach((button) => button.addEventListener('click', async () => {
			let reason=''; if(button.dataset.status==='STORNIERT'){reason=await promptAction('Bitte geben Sie eine nachvollziehbare Begründung für die Stornierung an.', '', { title: 'Rechnung stornieren', label: 'Begründung', multiline: true })||'';if(!reason)return} if(button.dataset.status==='VERSENDET'&&!await confirmAction('Nur einen bereits außerhalb der Anwendung erfolgten Versand dokumentieren? Die App versendet keine E-Mail.', { title: 'Versand dokumentieren', confirmLabel: 'Versand dokumentieren' }))return
			try { await api(`${apiBase}/commercial/invoices/${button.dataset.invoiceId}/transition`, { method: 'POST', body: new URLSearchParams({ status: button.dataset.status, reason }) }); await loadCommercial(); render() } catch (error) { notifyError(error.message) }
		}))
		root.querySelectorAll('.generate-invoice-document').forEach((button) => button.addEventListener('click', async () => {
			try {
				const invoiceId=button.dataset.invoiceId
				const result=await api(`${apiBase}/commercial/invoices/${invoiceId}/document`,{method:'POST',feedback:false,loadingLabel:'Rechnungs-Prüfdokument und Zahlungs-QR werden erzeugt …',body:new URLSearchParams({createPdf:'true'})});await Promise.all([loadCaseFiles(state.currentCase.id),loadRecordType('document',state.currentCase.id)])
				if(result.file?.pdfWarning)notifyWarning(result.file.pdfWarning);else {
					const qrState=result.file?.paymentQr?.rendering
					const qrNote=qrState==='SERVER_RENDERED_PNG'?' Der EPC-SEPA-Zahlcode wurde serverseitig erzeugt und in DOCX und PDF eingebettet.':qrState==='SUPPRESSED_FOR_DIRECT_DEBIT'?' Bei SEPA-Lastschrift wird bestimmungsgemäß kein Überweisungs-QR ausgegeben.':qrState==='DISABLED_BY_CONFIGURATION'?' Der Zahlungs-QR ist in den Rechnungseinstellungen oder im Niederlassungsprofil deaktiviert.':' Der Status der Zahlungs-QR-Ausgabe konnte nicht ermittelt werden.'
					notifySuccess((result.file?.eInvoice?'Prüf-PDF und strukturell geprüfte E-Rechnungs-XML wurden im Fallordner abgelegt.':'Das Rechnungs-Prüf-PDF wurde im Fallordner abgelegt.')+qrNote)
				}
			} catch (error) { notifyError(error.message) }
		}))
	}
	function showNewCase() {
		state.newCase = true; state.currentCaseId = 0; state.caseCreationToken = createToken(); state.view = 'case-detail'; state.caseTab = 'master'; state.masterTab = 'Personendaten'
		state.currentCase = { caseNumber: 'Neuer Fall', firstName: '', lastName: '', masterData: { first_name: '', last_name: '', guardianship_status: 'NEIN', death_time_mode: 'EXAKT', last_residence_country: 'Deutschland', guardian_country: 'Deutschland', order_client_country: 'Deutschland', invoice_country: 'Deutschland', responsible_employee: currentUser(), status: 'NEU' } }
		render()
	}
	async function openDeepLinkedTask() {
		const params = new URLSearchParams(window.location.search)
		const caseId = Number(params.get('caseId') || 0)
		const taskId = Number(params.get('taskId') || 0)
		const taskUid = params.get('taskUid') || ''
		if (!caseId && !taskId && !taskUid) return false
		try {
			if (caseId) {
				state.currentCase = await api(`${urls.cases}/${caseId}`)
				state.currentCaseId = caseId
				await Promise.all([api(`${apiBase}/cases/${caseId}/completeness`).then((result) => { state.caseCompleteness = result }), loadSideOrders(caseId)])
				state.assistantSidebar.caseId = caseId
				globalThis.localStorage?.setItem('bestatter-assistant-case-id', String(caseId))
				state.view = 'case-detail'; state.caseTab = taskId || taskUid ? 'task' : 'overview'
				if (taskId || taskUid) await Promise.all([loadRecordType('task', caseId), api(checklistUrl()).then((items) => { state.checklists = items })])
			} else {
				state.view = 'task'
			}
			render()
			const item = (state.records.task || []).find((record) => (taskId > 0 && Number(record.id) === taskId) || (taskUid !== '' && record.data?.nextcloud?.uid === taskUid))
			if (item) showRecordForm('task', item.caseId || caseId, item)
			else if (taskId || taskUid) notifyWarning('Die verlinkte Aufgabe wurde in diesem Fall nicht gefunden. Bitte die Aufgaben aus Nextcloud aktualisieren oder den Eintrag erneut speichern.')
			return true
		} catch (error) {
			notifyError(`Der Aufgabenlink konnte nicht vollständig geöffnet werden: ${error.message}`)
			return false
		}
	}
	Promise.all([api(urls.dashboard), api(`${apiBase}/dashboard/personal-day`), api(urls.team), api(`${apiBase}/cases/search?status=ALL&branch=ALL&responsible=ALL&limit=25&offset=0`), api(urls.customizing), api(checklistUrl()), api(recordUrl('task')), api(recordUrl('schedule')), api(recordUrl('contact')), api(articleUrl()), api(branchUrl()), api(documentTemplateUrl()), api(documentTemplateOptionsUrl()), api(deregistrationTemplateUrl()), api(invoiceSettingsUrl()), api(schedulePresetsUrl()), api(schedulingUrl()), api(`${apiBase}/assistant/configuration`), api(`${apiBase}/operations/onboarding`, { feedback: false }).catch(() => ({ ready: true, completed: false }))])
		.then(async ([dashboard, personalDay, team, caseResult, customizingData, checklists, tasks, schedules, contacts, articleData, branches, documentTemplates, documentTemplateOptions, deregistrationTemplates, invoiceSettings, schedulePresets, scheduling, assistantConfiguration, onboarding]) => {
			state.dashboard = dashboard; state.personalDay = personalDay; state.team = team; state.cases = caseResult.items || []; state.caseSearch = { ...state.caseSearch, ...caseResult }; state.customizing = customizingData.lists || []; state.checklists = checklists; state.records.task = tasks; state.records.schedule = schedules; state.records.contact = contacts; state.articles = articleData.articles || []; state.articleGroupRules = articleData.groupRules || []; state.positionTypes = articleData.positionTypes || []; state.quantityUnits = articleData.quantityUnits || []; state.allowedVatRates = articleData.allowedVatRates || state.allowedVatRates; state.countryProfiles = articleData.countryProfiles || []; state.branches = branches; state.documentTemplates = documentTemplates; state.documentTemplateOptions = documentTemplateOptions; state.deregistrationTemplates = deregistrationTemplates; state.invoiceSettings = invoiceSettings; state.schedulePresets = schedulePresets; state.scheduling = scheduling; state.assistantConfiguration = assistantConfiguration; state.onboarding = onboarding
			initializeAssistantCapture()
			if (!await openDeepLinkedTask()) { await restoreWorkspace({ state, urls, apiBase, api, loadRecordType, loadCaseFiles, loadAllCaseFiles, loadCommercial, loadSystemCheck, loadOperationsCockpit, loadReportingSummary, loadPaperlessInbox, loadPaperlessConfiguration, checklistManageUrl, workflowUrl, schedulingUrl, deregistrationUrl, notifyWarning }); render() }
			refreshGroupwareSilently().catch(() => {})
		})
		.catch((error) => { root.innerHTML = `<p class="bp-error">${esc(error.message)}</p>` })
	window.addEventListener('focus', () => { refreshGroupwareSilently().catch(() => {}) })
	window.setInterval(() => { refreshGroupwareSilently().catch(() => {}) }, 60000)
}())
