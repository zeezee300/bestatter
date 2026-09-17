import { burialGuidance } from './burial-guidance.js'

export function createCasesModule(ctx) {
	const { state, sections, labels, listMap, fixedOptions } = ctx
	const customizing = (...args) => ctx.customizing(...args)
	const esc = (...args) => ctx.esc(...args)
	const recordCaseNumber = (...args) => ctx.recordCaseNumber(...args)
	const formatRecordDate = (value) => ctx.formatRecordDate
		? ctx.formatRecordDate(value)
		: String(value || 'ohne Datum').replace('T', ' ')
	const recordBadges = (item, type) => ctx.recordBadges
		? ctx.recordBadges(item, type)
		: `<span class="bp-status">${esc(item.status || 'OFFEN')}</span>`
	const title = (...args) => ctx.title(...args)
	const render = (...args) => ctx.render(...args)
	const notifyError = (...args) => ctx.notifyError(...args)
	let searchRequest = 0
	const familyFields = ['spouse_first_name', 'spouse_last_name', 'spouse_date_of_birth', 'spouse_birth_place', 'spouse_residence', 'spouse_date_of_death', 'spouse_death_place', 'marriage_date', 'marriage_place', 'partnership_date', 'divorce_date']

	function familyState(data) {
		const status = String(data.civil_status || '').trim().toLocaleLowerCase('de-DE')
		const hasDetails = familyFields.some((key) => String(data[key] || '').trim() !== '')
		const showDetails = ['verheiratet', 'verwitwet', 'geschieden', 'lebenspartnerschaft'].includes(status) || hasDetails
		const messages = []
		if (status === 'ledig' && hasDetails) messages.push('Der Familienstand ist „ledig“, es sind aber Ehe-/Partnerschaftsangaben vorhanden. Bitte Angaben prüfen oder entfernen.')
		if (status === 'verwitwet' && !String(data.spouse_date_of_death || '').trim()) messages.push('Hinweis: Bei „verwitwet“ ist noch kein Todesdatum des Ehepartners / der Ehepartnerin eingetragen.')
		return { showDetails, messages }
	}

	function currentUser() {
		return (OC.getCurrentUser && OC.getCurrentUser().uid) || ''
	}

	function assigneeOptions(value) {
		const members = state.team.members?.length
			? state.team.members
			: [{ uid: currentUser(), displayName: currentUser() }]
		return members.map((member) => `<option value="${esc(member.uid)}" ${member.uid === value ? 'selected' : ''}>${esc(member.displayName)} (${esc(member.uid)})</option>`).join('')
	}

	function listOptions(key, value) {
		const list = state.customizing.find((entry) => entry.key === key)
		return (list?.items || []).map((item) => `<option value="${esc(item.value)}" ${String(item.value) === String(value) ? 'selected' : ''}>${esc(item.label)}</option>`).join('')
	}

	function contactsByCategory(category) {
		return (state.records.contact || []).filter((contact) => {
			const categories = contact.data?.categories || contact.data?.category || ''
			return String(categories).split(/[,;]/).map((item) => item.trim()).includes(category)
		})
	}

	function fieldType(key) {
		if (key === 'pickup_time') return 'datetime-local'
		if (['body_height_cm', 'body_weight_kg'].includes(key)) return 'number'
		if (key === 'newborn_lifetime_hours') return 'number'
		if (['time_of_death'].includes(key)) return 'time'
		if (['death_time_from', 'death_time_to'].includes(key)) return 'datetime-local'
		if (/email$/.test(key)) return 'email'
		if (/date$|^date_|_date$|closed_at/.test(key)) return 'date'
		return 'text'
	}

	function field(key, data) {
		let value = data[key] ?? ''
		if (key === 'funeral_type') {
			const variants = state.customizing.find((list) => list.key === 'BURIAL_VARIANT')?.tree || []
			if (variants.length) {
				const code = String(data.burial_variant_code || '')
				const path = []
				const locate = (items) => { for (const item of items) { if (item.value === code) { path.unshift(item); return true } if (locate(item.children || [])) { path.unshift(item); return true } } return false }
				if (code) locate(variants)
				let level = variants
				const selects = []
				for (let depth = 0; level.length; depth++) {
					const chosen = path[depth]?.value || ''
					selects.push(`<label><span>${depth ? `Unterauswahl ${depth}` : 'Bestattungsvariante'}</span><select class="bp-field" data-burial-level="${depth}"><option value="">${depth ? 'Noch offen' : 'Bitte auswählen'}</option>${level.map((item) => `<option value="${esc(item.value)}" ${item.value === chosen ? 'selected' : ''}>${esc(item.label)}</option>`).join('')}</select></label>`)
					level = path[depth]?.children || []
				}
				const selected = path[path.length - 1]
				const hint = selected?.metadata?.classificationPending ? '<small>Fachliche Katalogzuordnung noch offen; es werden keine Leistungen abgeleitet.</small>' : selected?.children?.length ? '<small>Unterauswahl noch offen; der Zwischenstand darf gespeichert werden.</small>' : ''
				return `<div class="bp-span-2" data-field="funeral_type"><input type="hidden" name="burial_variant_code" value="${esc(code)}"><div class="bp-form-grid">${selects.join('')}</div>${!code && value ? `<small>Bisherige Angabe: ${esc(value)}. Sie bleibt erhalten, bis eine Variante gewählt wird.</small>` : ''}${hint}</div>`
			}
		}
		if (key === 'with_funeral_ceremony') return `<label data-field="${key}"><span>${esc(labels[key])}</span><select class="bp-field" name="${key}">${[['','Noch offen'],['1','Mit Trauerfeier'],['0','Ohne Trauerfeier']].map(([option,label])=>`<option value="${option}" ${String(value)===option?'selected':''}>${label}</option>`).join('')}</select></label>`
		const surchargeDimensions = {surcharge_pickup_rule_key:'PICKUP', surcharge_height_rule_key:'HEIGHT_CM', surcharge_weight_rule_key:'WEIGHT_KG', surcharge_other_rule_key:'OTHER'}
		if (surchargeDimensions[key]) {
			const tiers = (state.surchargeRules || []).filter((item) => item.dimension === surchargeDimensions[key] && (item.active || item.ruleKey === value))
			const locked = Boolean(state.caseServices?.contractProtection?.active)
			return `<label data-field="${key}"><span>${esc(labels[key])}</span><select class="bp-field" name="${key}" ${locked ? 'disabled' : ''}><option value="">Keine Staffel / noch offen</option>${tiers.map((item) => `<option value="${esc(item.ruleKey)}" ${item.ruleKey === value ? 'selected' : ''}>${esc(item.label)}${item.active ? '' : ' (inzwischen inaktiv)'}</option>`).join('')}</select><small>${locked ? 'Vertrag festgeschrieben: Zuschläge nur als begründeten Positionsnachtrag erfassen.' : 'Nur manuelle Vormerkung; kein automatischer Preis oder Vertragsnachtrag.'}</small></label>`
		}
		if (key === 'guardianship_status' && value === '') value = 'NEIN'
		if (key === 'death_time_mode' && value === '') value = 'EXAKT'
		if (key.endsWith('_country') && value === '') value = 'Deutschland'
		if (key === 'responsible_employee' && value === '' && state.newCase) value = currentUser()
		if (key === 'branch' && value === '') value = state.branches.find((entry) => entry.active && (entry.memberUids || []).includes(currentUser()))?.key || state.branches.find((entry) => entry.active)?.key || ''

		if (key === 'branch') {
			const options = state.branches.filter((entry) => entry.active).map((entry) => `<option value="${esc(entry.key)}" ${entry.key === value ? 'selected' : ''}>${esc(entry.name)}</option>`).join('')
			return `<label data-field="${key}"><span>${esc(labels[key])}</span><select class="bp-field" name="${key}"><option value=""></option>${options}</select></label>`
		}
		if (fixedOptions[key]) {
			return `<label data-field="${key}"><span>${esc(labels[key] || key)}</span><select class="bp-field" name="${key}">${fixedOptions[key].map(([option, label]) => `<option value="${option}" ${option === value ? 'selected' : ''}>${label}</option>`).join('')}</select></label>`
		}
		if (listMap[key]) {
			return `<label data-field="${key}"><span>${esc(labels[key] || key)}</span><select class="bp-field" name="${key}"><option value=""></option>${listOptions(listMap[key], value)}</select></label>`
		}
		if (key === 'responsible_employee') {
			const known = (state.team.members || []).some((member) => member.uid === value)
			const legacy = value && !known ? `<option value="${esc(value)}" selected>${esc(value)} (Altdaten – bitte zuordnen)</option>` : ''
			const locked = !state.newCase && !state.team.isBestatterAdmin
			return `<label data-field="${key}"><span>${esc(labels[key])}</span><select class="bp-field" name="${key}" ${locked ? 'disabled' : ''}><option value="" ${!value ? 'selected' : ''}>Nicht zugeordnet</option>${legacy}${assigneeOptions(value)}</select>${locked ? `<input type="hidden" name="${key}" value="${esc(value)}">` : ''}</label>`
		}
		if (['birth_registry_office', 'registry_office', 'guardian_contact', 'cemetery_contact'].includes(key)) {
			const category = ['birth_registry_office', 'registry_office'].includes(key) ? 'Standesämter' : key === 'cemetery_contact' ? 'Friedhöfe' : 'Betreuer'
			const options = contactsByCategory(category).map((item) => `<option value="${esc(item.title)}" ${item.title === value ? 'selected' : ''}>${esc(item.title)}</option>`).join('')
			return `<label data-field="${key}"><span>${esc(labels[key])}</span><div class="bp-contact-field"><select class="bp-field" name="${key}"><option value=""></option>${options}</select><button type="button" class="bp-secondary bp-add-contact" data-contact-category="${category}" data-contact-target="${key}">Neu</button></div></label>`
		}
		return `<label data-field="${key}"><span>${esc(labels[key] || key)}</span><input class="bp-field" name="${key}" type="${fieldType(key)}" ${key === 'newborn_lifetime_hours' ? 'min="0" step="1"' : ['body_height_cm','body_weight_kg'].includes(key) ? 'min="1" step="1"' : ''} value="${esc(value)}"></label>`
	}

	function masterDataForm(data) {
		const groups = sections[state.masterTab] || sections.Personendaten
		const exact = String(data.death_time_mode || 'EXAKT').toUpperCase()
		const hasGuardian = String(data.guardianship_status || 'NEIN').toUpperCase() === 'JA'
		const family = familyState(data)
		const content = groups.map(([title, keys]) => {
			if (title === 'Betreuerkontakt' && !hasGuardian) return ''
			if (['Ehepartner/in', 'Ehe / Lebenspartnerschaft'].includes(title) && !family.showDetails) return ''
			const help = title === 'Todeszeitpunkt' ? '<p class="bp-section-help">Entweder exaktes Sterbedatum und Sterbezeit erfassen oder einen Zeitraum von/bis angeben.</p>' : ''
			const fields = keys.filter((key) => !['found_at', 'place_found'].includes(key)).filter((key) => {
				if (state.masterTab !== 'Sterbedaten') return true
				if (['date_of_death', 'time_of_death'].includes(key)) return exact === 'EXAKT'
				if (['death_time_from', 'death_time_to'].includes(key)) return exact === 'ZEITRAUM'
				return true
			}).map((key) => field(key, data)).join('')
			return `<section class="bp-field-section"><h3>${esc(title)}</h3>${help}<div class="bp-form-grid">${fields}</div></section>`
		}).join('')
		const guidance = family.messages.length ? `<div class="bp-family-guidance" role="status">${family.messages.map((message) => `<p>${esc(message)}</p>`).join('')}</div>` : ''
		return `<div class="bp-subtabs bp-master-subtabs">${Object.keys(sections).map((name) => `<button class="${state.masterTab === name ? 'active' : ''}" data-master-tab="${esc(name)}">${esc(name)}</button>`).join('')}</div><form id="case-form" class="bp-master-form bp-case-form">${guidance}${content}<div class="bp-save-state" id="case-save-state">Änderungen werden automatisch gespeichert.</div></form>`
	}

	function caseList() {
		const search = state.caseSearch || {}; const rows = search.items?.length || state.query || search.view !== 'ALL' || search.branch !== 'ALL' ? (search.items || []) : state.cases
		const page = Math.floor((search.offset || 0) / (search.limit || 25)) + 1; const pages = Math.max(1, Math.ceil((search.total || 0) / (search.limit || 25)))
		const views = [['ALL','Alle Fälle'],['MY_OPEN','Meine offenen Fälle'],['OPEN','Alle offenen Fälle'],['SIDE_OPEN','Offene Nebenaufträge'],['CLOSED','Abgeschlossene Fälle']]
		const branches = (state.branches || []).filter((entry) => entry.active).map((entry) => `<option value="${esc(entry.key)}" ${search.branch===entry.key?'selected':''}>${esc(entry.name)}</option>`).join('')
		const sideOrderFilters = [['ALL','Alle Nebenaufträge'],['HAS','Vorhanden'],['OPEN','Offen'],['BILLING_OPEN','Abrechnung offen'],['DONE','Erledigt'],['NONE','Keine']]
		const statusLabels = { ENTWURF:'Entwurf', BEAUFTRAGT:'Beauftragt', ABGESCHLOSSEN:'Abgeschlossen', STORNIERT:'Storniert' }
		const tableRows = rows.map((item) => {
			const summary = item.sideOrderSummary || { total:0, open:0, items:[] }; const expanded = Boolean(state.expandedCaseIds?.[item.id])
			const disclosure = summary.total ? `<button type="button" class="bp-disclosure" data-expand-case="${item.id}" aria-expanded="${expanded}" aria-controls="case-side-orders-${item.id}" title="Nebenaufträge ${expanded?'einklappen':'aufklappen'}">${expanded?'▾':'▸'}</button>` : '<span class="bp-disclosure-spacer"></span>'
			const sideOrderCountLabel = summary.total === 1 ? '1 Nebenauftrag' : `${summary.total} Nebenaufträge`
			const main = `<tr data-case-id="${item.id}" tabindex="0"><td data-label="Fallnummer"><span class="bp-case-number-cell">${disclosure}${esc(item.caseNumber)}</span></td><td data-label="Verstorbene Person">${esc(item.lastName)}, ${esc(item.firstName)}</td><td data-label="Sterbedatum">${item.dateOfDeath ? esc(new Date(`${item.dateOfDeath}T12:00:00`).toLocaleDateString('de-DE')) : '–'}</td><td data-label="Bestattungsart">${esc(item.funeralType || '–')}</td><td data-label="Status"><span class="bp-status">${esc(item.status)}</span></td><td data-label="Nebenaufträge">${summary.total ? `<span class="bp-side-order-count">${sideOrderCountLabel}${summary.open?` · ${summary.open} offen`:''}</span>` : '–'}</td></tr>`
			const children = expanded ? (summary.items || []).map((order,index) => `<tr class="bp-side-order-child ${order.matched?'is-match':''}" ${index===0?`id="case-side-orders-${item.id}"`:''} data-parent-case-id="${item.id}"><td colspan="6"><button type="button" data-open-side-order="${order.id}" data-side-order-case="${item.id}"><span><b>${esc(order.sideOrderNumber)}</b><small>${esc(order.customerName || 'Auftraggeber nicht vollständig')}</small></span><span class="bp-status">${esc(statusLabels[order.status] || order.status)}</span>${order.nextAction?`<small>${esc(order.nextAction)}</small>`:''}</button></td></tr>`).join('') : ''
			return main + children
		}).join('')
		return `<div class="bp-page-head"><div><p class="bp-eyebrow">Fallführung</p><h2>Fälle</h2></div><button class="bp-primary" id="new-case">Neuen Sterbefall anlegen</button></div><div class="bp-saved-filters" aria-label="Gespeicherte Fallansichten">${views.map(([key,label])=>`<button type="button" class="bp-secondary ${search.view===key?'active':''}" data-case-view="${key}">${label}</button>`).join('')}</div><div class="bp-toolbar"><input id="case-search" value="${esc(state.query)}" placeholder="Fall-, Nebenauftragsnummer, Name oder Status …" aria-label="Fälle und Nebenaufträge durchsuchen"><select id="case-branch-filter" aria-label="Niederlassung filtern"><option value="ALL">Alle Niederlassungen</option>${branches}</select><select id="case-side-order-filter" aria-label="Nebenaufträge filtern">${sideOrderFilters.map(([value,label])=>`<option value="${value}" ${String(search.sideOrders||'ALL')===value?'selected':''}>${label}</option>`).join('')}</select><span>${search.total ?? rows.length} Fälle</span></div><section class="bp-panel bp-table bp-case-table"><table><thead><tr><th>Fallnummer</th><th>Verstorbene Person</th><th>Sterbedatum</th><th>Bestattungsart</th><th>Status</th><th>Nebenaufträge</th></tr></thead><tbody>${tableRows || '<tr><td colspan="6" class="bp-empty">Keine passenden Fälle gefunden.</td></tr>'}</tbody></table><div class="bp-pagination"><button class="bp-secondary" data-case-page="previous" ${page<=1?'disabled':''}>Zurück</button><b>Seite ${page} von ${pages}</b><button class="bp-secondary" data-case-page="next" ${page>=pages?'disabled':''}>Weiter</button></div></section>`
	}

	async function loadCaseSearch(reset = false) {
		if (reset) state.caseSearch.offset = 0
		const request = ++searchRequest; const view = state.caseSearch.view || 'ALL'
		const sideOrders = view === 'SIDE_OPEN' ? 'OPEN' : (state.caseSearch.sideOrders || 'ALL')
		const params = new URLSearchParams({ query: state.query || '', status: view === 'CLOSED' ? 'ABGESCHLOSSEN' : (['OPEN','MY_OPEN','SIDE_OPEN'].includes(view) ? 'OPEN' : 'ALL'), branch: state.caseSearch.branch || 'ALL', responsible: view === 'MY_OPEN' ? currentUser() : 'ALL', sideOrders, limit: String(state.caseSearch.limit || 25), offset: String(state.caseSearch.offset || 0) })
		const result = await ctx.api(`${ctx.apiBase}/cases/search?${params}`, { loadingLabel: 'Fälle werden gesucht …' })
		if (request !== searchRequest) return false
		state.caseSearch = { ...state.caseSearch, ...result }
		const merged = new Map(state.cases.map((item) => [Number(item.id), item])); result.items.forEach((item) => merged.set(Number(item.id), item)); state.cases = [...merged.values()]
		return true
	}

	function bindCaseSearch() {
		const search = document.getElementById('case-search')
		if (search) search.addEventListener('input', (event) => { state.query = event.target.value; clearTimeout(search._searchTimer); search._searchTimer = setTimeout(async()=>{ try { if(await loadCaseSearch(true)){render();const refreshed=document.getElementById('case-search');refreshed?.focus();refreshed?.setSelectionRange(refreshed.value.length,refreshed.value.length)} } catch(error){notifyError(error.message)} },280) })
		document.querySelectorAll('[data-case-view]').forEach((button)=>button.addEventListener('click',async()=>{state.caseSearch.view=button.dataset.caseView;if(button.dataset.caseView==='SIDE_OPEN')state.caseSearch.sideOrders='OPEN';try{await loadCaseSearch(true);render()}catch(error){notifyError(error.message)}}))
		document.getElementById('case-branch-filter')?.addEventListener('change',async(event)=>{state.caseSearch.branch=event.target.value;try{await loadCaseSearch(true);render()}catch(error){notifyError(error.message)}})
		document.getElementById('case-side-order-filter')?.addEventListener('change',async(event)=>{state.caseSearch.sideOrders=event.target.value;if(state.caseSearch.view==='SIDE_OPEN'&&event.target.value!=='OPEN')state.caseSearch.view='ALL';try{await loadCaseSearch(true);render()}catch(error){notifyError(error.message)}})
		document.querySelectorAll('[data-case-page]').forEach((button)=>button.addEventListener('click',async()=>{const step=button.dataset.casePage==='next'?1:-1;state.caseSearch.offset=Math.max(0,(state.caseSearch.offset||0)+step*(state.caseSearch.limit||25));try{await loadCaseSearch(false);render()}catch(error){notifyError(error.message)}}))
	}

	function overview() {
		const openTasks = (state.records.task || []).filter((item) => item.status !== 'ERLEDIGT').length
		const externalAppointments = (state.records.schedule || []).filter((item) => item.data?.scheduleKind === 'EXTERNAL_APPOINTMENT').length
		return `<section class="bp-hero"><div><p class="bp-eyebrow">Mein Arbeitstag</p><h2>Arbeitsübersicht</h2><p>Fälle, Aufgaben, interne Tätigkeiten und externe Fixtermine aus der Bestatter-Fallakte und Nextcloud.</p></div></section><section class="bp-metrics"><article><strong>${state.dashboard.openCases || state.cases.length}</strong><span>Aktive Fälle</span></article><article><strong>${openTasks}</strong><span>Offene Aufgaben</span></article><article><strong>${(state.records.schedule || []).length}</strong><span>Termine (${externalAppointments} extern)</span></article></section>`
	}

	function dashboardView() {
		const today = new Date().toISOString().slice(0, 10)
		const tasks = state.records.task || []
		const events = state.records.schedule || []
		const openTasks = tasks.filter((item) => item.status !== 'ERLEDIGT')
		const day = state.dashboardDate || today
		const month = new Date(`${day}T12:00:00`)
		const first = new Date(month.getFullYear(), month.getMonth(), 1)
		const offset = (first.getDay() + 6) % 7
		const days = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate()
		const cells = Array.from({ length: offset }, () => '<i></i>')
		for (let date = 1; date <= days; date++) {
			const key = new Date(month.getFullYear(), month.getMonth(), date, 12).toISOString().slice(0, 10)
			const count = openTasks.filter((item) => String(item.date || '').slice(0, 10) === key).length + events.filter((item) => String(item.date || '').slice(0, 10) === key).length
			cells.push(`<button class="bp-cal-day ${key === day ? 'selected' : ''} ${key === today ? 'today' : ''}" data-calendar-date="${key}">${date}${count ? `<b>${count}</b>` : ''}</button>`)
		}
		const selected = [...openTasks.map((item) => ({ ...item, kind: 'Aufgabe' })), ...events.map((item) => ({ ...item, kind: 'Termin' }))].filter((item) => String(item.date || '').slice(0, 10) === day)
		const personal = state.personalDay || { counts: {}, tasksToday: [], overdueTasks: [], schedulesToday: [], nextTasks: [] }
		const workload = (personal.nextTasks?.length ? personal.nextTasks : openTasks).slice().sort((a, b) => String(a.date || '9999').localeCompare(String(b.date || '9999'))).slice(0, 20)
		const rows = workload.length ? workload.map((item) => `<button type="button" class="bp-work-item" data-dashboard-record-type="task" data-dashboard-record-id="${Number(item.id)}" aria-label="Aufgabe ${esc(item.title)} bearbeiten"><span></span><div><b>${recordCaseNumber(item) ? `<span class="bp-case-number">Fall ${esc(recordCaseNumber(item))}</span>` : ''}${esc(item.title)}</b><small>${esc(item.data?.description || 'Keine Beschreibung hinterlegt.')}</small><div class="bp-dashboard-record-meta"><em>F\u00e4llig: ${esc(formatRecordDate(item.date))}</em><em>Zust\u00e4ndig: ${esc(item.assigneeName || item.data?.assigneeName || item.assigneeUid || item.data?.assigneeUid || 'nicht festgelegt')}</em><span class="bp-record-badges">${recordBadges(item, 'task')}</span></div></div></button>`).join('') : '<p class="bp-empty">Keine offenen Aufgaben.</p>'
		return `<section class="bp-hero"><div><p class="bp-eyebrow">Mein Arbeitstag</p><h2>Arbeits\u00fcbersicht</h2><p>Eigene Aufgaben, interne Tätigkeiten und externe Fixtermine aus Nextcloud Tasks und Kalender.</p></div></section>
		<section class="bp-metrics"><article><strong>${personal.counts?.openCases ?? state.dashboard.openCases ?? 0}</strong><span>Meine offenen F\u00e4lle</span></article><article><strong>${personal.counts?.tasksToday ?? 0}</strong><span>Meine Aufgaben heute</span></article><article><strong>${personal.counts?.overdueTasks ?? 0}</strong><span>\u00dcberf\u00e4llige Aufgaben</span></article><article><strong>${personal.counts?.schedulesToday ?? 0}</strong><span>Termine heute</span></article></section>
		<section class="bp-mobile-quick" aria-label="Mobile Tages\u00fcbersicht"><h3>Heute schnell im Blick</h3>${[...(personal.overdueTasks || []), ...(personal.tasksToday || []), ...(personal.schedulesToday || [])].slice(0, 12).map((item) => { const type = item.type || 'task'; return `<button type="button" data-dashboard-record-type="${esc(type)}" data-dashboard-record-id="${Number(item.id)}"><b>${esc(item.title)}</b><span class="bp-mobile-record-meta"><small>${esc(item.caseNumber || 'Ohne Fall')} · ${esc(formatRecordDate(item.date))}</small><span class="bp-record-badges">${recordBadges(item, type)}</span></span></button>` }).join('') || '<p class="bp-empty">Heute sind keine pers\u00f6nlichen Aktivit\u00e4ten vorhanden.</p>'}</section>
		<div class="bp-dashboard-grid"><section class="bp-panel"><div class="bp-panel-head"><div><p class="bp-eyebrow">Aufgaben</p><h3>Arbeitsvorrat</h3></div><button class="bp-secondary" data-view="task">Alle Aufgaben</button></div><div class="bp-work-list">${rows}</div></section>
		<section class="bp-panel"><div class="bp-panel-head"><div><p class="bp-eyebrow">Termine und Fristen</p><h3>Kalender</h3></div><button class="bp-secondary" data-view="schedule">Alle Termine</button></div><div class="bp-calendar"><b>${month.toLocaleDateString('de-DE', { month: 'long', year: 'numeric' })}</b><div class="bp-calendar-grid"><span>Mo</span><span>Di</span><span>Mi</span><span>Do</span><span>Fr</span><span>Sa</span><span>So</span>${cells.join('')}</div><div class="bp-day-agenda"><h3>${selected.length} Eintr\u00e4ge</h3>${selected.length ? selected.map((item) => { const type = item.kind === 'Termin' ? 'schedule' : 'task'; return `<button type="button" class="bp-agenda-item" data-dashboard-record-type="${type}" data-dashboard-record-id="${Number(item.id)}"><b>${recordCaseNumber(item) ? `Fall ${esc(recordCaseNumber(item))} · ` : ''}${esc(item.kind)}: ${esc(item.title)}</b><span class="bp-agenda-meta"><small>${esc(formatRecordDate(item.date))}</small><span class="bp-record-badges">${recordBadges(item, type)}</span></span></button>` }).join('') : '<p class="bp-muted">F\u00fcr diesen Tag sind keine Aufgaben oder Termine vorhanden.</p>'}</div></div></section></div>`
	}

	function caseOverview() {
		const item = state.currentCase
		const data = item.masterData || {}
		const completeness = state.caseCompleteness
		const phaseCards = completeness?.phases?.map((phase) => `<article class="bp-completeness-phase ${phase.ready ? 'ready' : ''}"><div><b>${esc(phase.label)}</b><span>${phase.complete} von ${phase.required} vollständig</span></div><progress max="100" value="${phase.percentage}">${phase.percentage} %</progress>${!phase.ready ? `<ul>${phase.checks.filter((check) => !check.complete).map((check) => `<li>${esc(check.label)}</li>`).join('')}</ul>` : '<small>Alle Voraussetzungen erfüllt.</small>'}</article>`).join('') || ''
		const notices = burialGuidance(state)
		const guidance = notices.length ? `<aside class="bp-compliance-note"><b>Bestattungsvariante – offene Entscheidungen</b><ul>${notices.map((notice)=>`<li>${esc(notice.text)}${notice.ruleId?` <button type="button" class="bp-secondary" data-burial-defer="${Number(notice.ruleId)}">Später entscheiden</button>`:''}</li>`).join('')}</ul></aside>` : ''
		return `${guidance}<section class="bp-panel bp-completeness" aria-labelledby="case-completeness-title"><div class="bp-panel-head"><div><p class="bp-eyebrow">Prozessqualität</p><h3 id="case-completeness-title">Vollständigkeit ${completeness?.percentage ?? 0} %</h3></div><span class="bp-status ${completeness?.ready ? 'ready' : ''}">${completeness?.ready ? 'Vollständig' : 'Offene Angaben'}</span></div><div class="bp-completeness-grid">${phaseCards || '<p class="bp-muted">Prüfung wird geladen.</p>'}</div></section><section class="bp-case-summary"><article><p class="bp-eyebrow">Auftrag / KVA</p><h3>${esc(data.order_status || 'Noch nicht angelegt')}</h3><button class="bp-primary" data-case-tab="order">Auftrag öffnen</button></article><article><p class="bp-eyebrow">Aufgaben</p><h3>${(state.records.task || []).filter((record) => record.caseId === item.id && record.status !== 'ERLEDIGT').length} offen</h3><button class="bp-secondary" data-case-tab="task">Aufgaben öffnen</button></article><article><p class="bp-eyebrow">Fallnotiz</p><h3>${esc(data.notes || 'Keine Fallnotiz hinterlegt.')}</h3><button class="bp-secondary" data-case-tab="master">Stammdaten bearbeiten</button></article></section>${sideOrdersPanel()}`
	}

	function sideOrdersPanel() {
		const orders = state.sideOrders || []
		const statusLabels = { ENTWURF: 'Entwurf', BEAUFTRAGT: 'Beauftragt', ABGESCHLOSSEN: 'Abgeschlossen', STORNIERT: 'Storniert' }
		const editFields = (order = {}) => `<div class="bp-form-grid"><label><span>Vorname</span><input name="firstName" value="${esc(order.firstName || '')}" autocomplete="given-name"></label><label><span>Nachname</span><input name="lastName" value="${esc(order.lastName || '')}" required autocomplete="family-name"></label><label><span>Straße</span><input name="street" value="${esc(order.street || '')}" autocomplete="street-address"></label><label><span>PLZ / Ort</span><input name="postalCity" value="${esc(order.postalCity || '')}"></label><label><span>Land</span><input name="country" value="${esc(order.country || 'Deutschland')}" autocomplete="country-name"></label><label><span>Beziehung zum/zur Verstorbenen</span><input name="relation" value="${esc(order.relation || 'Sonstige')}"></label><label><span>Telefon</span><input name="phone" value="${esc(order.phone || '')}" type="tel"></label><label><span>E-Mail</span><input name="email" value="${esc(order.email || '')}" type="email"></label><label><span>Benötigt bis</span><input name="requiredBy" value="${esc(String(order.requiredBy || '').slice(0, 16))}" type="datetime-local"></label></div>`
		const active = orders.find((order) => Number(order.id) === Number(state.activeSideOrderId || 0)) || null
		if (!active && state.activeSideOrderId) { state.activeSideOrderId = 0; state.sideOrderView = 'list' }
		const rows = orders.map((order) => `<tr class="${Number(order.id)===Number(state.activeSideOrderId)?'is-selected':''} ${order.status==='STORNIERT'?'is-cancelled':''}"><td data-label="Auftragsnummer"><button type="button" class="bp-side-order-select" data-select-side-order="${Number(order.id)}" aria-pressed="${Number(order.id)===Number(state.activeSideOrderId)}">${esc(order.sideOrderNumber)}</button></td><td data-label="Auftraggeber">${esc(`${order.firstName} ${order.lastName}`.trim() || 'nicht vollständig')}</td><td data-label="Status"><span class="bp-status">${esc(statusLabels[order.status] || order.status)}</span></td><td data-label="Benötigt bis">${order.requiredBy?esc(formatRecordDate(order.requiredBy)):'–'}</td></tr>`).join('')
		let detail = ''
		if (active) {
			const id = Number(active.id), draft = active.status === 'ENTWURF', commissioned = active.status === 'BEAUFTRAGT'
			const subnav = `<div class="bp-subtabs bp-side-order-tabs" aria-label="Bereiche des Nebenauftrags"><button type="button" class="${state.sideOrderView==='header'?'active':''}" data-side-order-view="header">Kopfdaten</button><button type="button" class="${state.sideOrderView==='positions'?'active':''}" data-side-order-view="positions" ${active.status==='STORNIERT'?'disabled':''}>Positionen</button><button type="button" class="${state.sideOrderView==='finances'?'active':''}" data-side-order-view="finances" ${commissioned||active.status==='ABGESCHLOSSEN'?'':'disabled'}>Finanzen / Abrechnung</button></div>`
			const contextHead = `<div class="bp-side-order-context"><div><p class="bp-eyebrow">Nebenauftrag ${esc(active.sideOrderNumber)}</p><h3>${esc(`${active.firstName} ${active.lastName}`.trim())}</h3><small>${esc(active.relation || 'Beziehung nicht angegeben')} · ${esc(statusLabels[active.status] || active.status)}</small></div><button type="button" class="bp-secondary" data-side-order-list>Zur Nebenauftragsübersicht</button></div>`
			if (state.sideOrderView === 'positions') detail = `${contextHead}${subnav}${ctx.serviceSelectionPanel()}`
			else if (state.sideOrderView === 'finances') detail = `${contextHead}${subnav}${ctx.financesPanel()}`
			else {
				const commission = draft ? `<button type="button" class="bp-primary" data-side-order-status="BEAUFTRAGT" data-side-order-id="${id}">Beauftragen</button>` : ''
				const close = commissioned ? `<button type="button" class="bp-primary" data-side-order-status="ABGESCHLOSSEN" data-side-order-id="${id}">Abschließen</button>` : ''
				const cancel = ['ENTWURF','BEAUFTRAGT'].includes(active.status) ? `<button type="button" class="bp-danger" data-cancel-side-order="${id}">Stornieren</button>` : ''
				const headerBody = draft ? `<form class="side-order-edit-form" data-side-order-id="${id}">${editFields(active)}<div class="bp-row-actions"><button class="bp-primary">Kopfdaten speichern</button><span class="bp-card-state" aria-live="polite"></span></div></form>` : `<dl class="bp-side-order-data"><div><dt>Anschrift</dt><dd>${esc([active.street,active.postalCity,active.country].filter(Boolean).join(', ')||'nicht vollständig')}</dd></div><div><dt>Telefon</dt><dd>${esc(active.phone||'–')}</dd></div><div><dt>E-Mail</dt><dd>${esc(active.email||'–')}</dd></div><div><dt>Benötigt bis</dt><dd>${active.requiredBy?esc(formatRecordDate(active.requiredBy)):'–'}</dd></div></dl>`
				detail = `${contextHead}${subnav}<section class="bp-field-section bp-side-order-detail">${headerBody}<div class="bp-row-actions">${commission}${close}${cancel}</div></section>`
			}
		}
		const list = `<div class="bp-table-scroll bp-side-order-overview"><table><thead><tr><th>Auftragsnummer</th><th>Auftraggeber</th><th>Status</th><th>Benötigt bis</th></tr></thead><tbody>${rows||'<tr><td colspan="4" class="bp-empty">Noch keine Nebenaufträge erfasst.</td></tr>'}</tbody></table></div>`
		const createDisabled = ['ABGESCHLOSSEN','STORNIERT'].includes(String(state.currentCase?.status||''))
		return `<section class="bp-panel bp-side-orders" id="side-orders"><div class="bp-panel-head"><div><p class="bp-eyebrow">Weitere Auftraggeber</p><h3>Nebenaufträge</h3><p class="bp-muted">Zusätzliche Bestellungen Dritter werden getrennt vom Hauptauftrag geführt, bleiben aber diesem Sterbefall zugeordnet.</p></div><span class="bp-status">${orders.length} vorhanden · ${orders.filter((order)=>['ENTWURF','BEAUFTRAGT'].includes(order.status)).length} offen</span></div>${list}${detail?`<div class="bp-side-order-workspace">${detail}</div>`:''}<details class="bp-side-order-create" ${createDisabled?'hidden':''}><summary>Nebenauftrag anlegen</summary><form id="side-order-form">${editFields()}<div class="bp-row-actions"><button type="button" class="bp-secondary" data-close-side-order-create>Abbrechen</button><button type="submit" class="bp-primary">Nebenauftrag anlegen</button><span class="bp-card-state" id="side-order-save-state" aria-live="polite"></span></div></form></details>${createDisabled?'<p class="bp-warning">In abgeschlossenen oder stornierten Fällen können keine neuen Nebenaufträge angelegt werden.</p>':''}</section>`
	}

	function bindSideOrders() {
		const load = () => ctx.loadSideOrders(state.currentCase.id)
		ctx.root.querySelector('#side-order-form')?.addEventListener('submit', async (event) => {
			event.preventDefault()
			const form = event.currentTarget; const status = form.querySelector('#side-order-save-state'); status.textContent = 'Speichert …'
			try { const created = await ctx.api(ctx.sideOrdersUrl(state.currentCase.id), { method: 'POST', feedback: false, body: new URLSearchParams({ data: JSON.stringify(Object.fromEntries(new FormData(form))) }) }); await load(); state.activeSideOrderId=Number(created.id);state.sideOrderView='header';ctx.notifySuccess('Nebenauftrag wurde angelegt.'); render() } catch (error) { status.textContent = error.message; status.dataset.state = 'error' }
		})
		ctx.root.querySelectorAll('.side-order-edit-form').forEach((form) => form.addEventListener('submit', async (event) => {
			event.preventDefault()
			const status = form.querySelector('.bp-card-state'); status.textContent = 'Speichert …'; status.dataset.state = 'saving'
			try { await ctx.api(`${ctx.apiBase}/side-orders/${form.dataset.sideOrderId}`, { method: 'PUT', feedback: false, body: new URLSearchParams({ data: JSON.stringify(Object.fromEntries(new FormData(form))) }) }); await load(); ctx.notifySuccess('Nebenauftrag wurde aktualisiert.'); render() } catch (error) { status.textContent = error.message; status.dataset.state = 'error' }
		}))
		ctx.root.querySelectorAll('[data-side-order-status]').forEach((button) => button.addEventListener('click', async () => {
			const target = button.dataset.sideOrderStatus
			const prompt = target === 'BEAUFTRAGT' ? 'Nebenauftrag verbindlich beauftragen? Auftraggeber und Positionen werden danach geschützt; Änderungen sind nur noch als begründeter Nachtrag möglich.' : 'Nebenauftrag abschließen? Dies ist erst nach Freigabe der Schlussrechnung möglich.'
			if (!await ctx.confirmAction(prompt, { title: target === 'BEAUFTRAGT' ? 'Nebenauftrag beauftragen' : 'Nebenauftrag abschließen', confirmLabel: target === 'BEAUFTRAGT' ? 'Beauftragen' : 'Abschließen' })) return
			try { await ctx.api(`${ctx.apiBase}/side-orders/${button.dataset.sideOrderId}/transition`, { method: 'POST', body: new URLSearchParams({ status: target }) }); await load(); render() } catch (error) { notifyError(error.message) }
		}))
		ctx.root.querySelectorAll('[data-cancel-side-order]').forEach((button) => button.addEventListener('click', async () => {
			if (!await ctx.confirmAction('Nebenauftrag wirklich stornieren?', { title: 'Nebenauftrag stornieren', confirmLabel: 'Stornieren', danger: true })) return
			try { await ctx.api(`${ctx.apiBase}/side-orders/${button.dataset.cancelSideOrder}/cancel`, { method: 'POST' }); await load(); render() } catch (error) { notifyError(error.message) }
		}))
		ctx.root.querySelectorAll('[data-select-side-order]').forEach((button)=>button.addEventListener('click',()=>{state.activeSideOrderId=Number(button.dataset.selectSideOrder);state.sideOrderView='header';render()}))
		ctx.root.querySelectorAll('[data-side-order-view]').forEach((button)=>button.addEventListener('click',async()=>{state.sideOrderView=button.dataset.sideOrderView;if(['positions','finances'].includes(state.sideOrderView))await ctx.loadCommercial();render()}))
		ctx.root.querySelectorAll('[data-side-order-list]').forEach((button)=>button.addEventListener('click',()=>{state.activeSideOrderId=0;state.sideOrderView='list';render()}))
		ctx.root.querySelector('[data-close-side-order-create]')?.addEventListener('click',()=>{const details=ctx.root.querySelector('.bp-side-order-create');if(details)details.open=false})
	}

	function checklistPanel() {
		const caseKey = String(state.currentCase.id)
		const caseTasks = (state.records.task || []).filter((item) => item.caseId === state.currentCase.id)
		const existing = new Set(caseTasks.map((item) => item.title))
		const open = Boolean(state.checklistPanelOpen[caseKey])
		return `<section class="bp-panel bp-checklist-shell"><details class="bp-checklist-panel" data-checklist-panel="${caseKey}" ${open ? 'open' : ''}>
			<summary><span><b>Checklisten</b><small>${state.checklists.length} Vorlagen · ${caseTasks.length} Aufgaben im Fall</small></span><em>${open ? 'Einklappen' : 'Einblenden'}</em></summary>
			<div class="bp-checklist-intro">Aufgaben sind vorausgewählt. Bereits angelegte Aufgaben sind gekennzeichnet und können nicht doppelt übernommen werden.</div>
			<div class="bp-checklist-grid">${state.checklists.map((template) => {
				const completed = template.items.filter((item) => existing.has(item.title)).length
				const templateKey = `${caseKey}:${template.key}`
				return `<details class="bp-checklist-card" data-checklist-details="${esc(templateKey)}" ${state.checklistOpen[templateKey] ? 'open' : ''}><summary><span><b>${esc(template.name)}</b><small>${template.items.length} Aufgaben · ${completed} bereits angelegt</small></span></summary><p>${esc(template.description || '')}</p><div class="bp-checklist-items">${template.items.map((item) => { const done = existing.has(item.title); return `<label class="bp-checklist-item ${done ? 'done' : ''}"><input type="checkbox" class="checklist-item" data-checklist="${esc(template.key)}" value="${esc(item.id)}" ${done ? 'disabled' : 'checked'}><span>${esc(item.title)}${done ? ' <em>✓ bereits angelegt</em>' : ''}</span></label>` }).join('')}</div><button class="bp-primary apply-checklist" data-checklist="${esc(template.key)}">Auswahl anwenden</button></details>`
			}).join('')}</div>
		</details></section>`
	}


	return { currentUser, assigneeOptions, listOptions, contactsByCategory, fieldType, field, masterDataForm, caseList, overview, dashboardView, caseOverview, sideOrdersPanel, checklistPanel, loadCaseSearch, bindCaseSearch, bindSideOrders }
}
