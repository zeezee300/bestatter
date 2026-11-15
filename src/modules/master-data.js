import { handbookMoreLink } from './handbook.js'
import { allowedKeys, availableInBranch, groupByType, isAssignable, resourceDisplayName, resourceTypeIcon, resourceTypeInfo, resourceTypeList } from './scheduling-resources.js'

/**
 * 0.92.0 – Einstellungen → Stammdaten: Niederlassungen, Ressourcen, Leistungen, Wertelisten und Marker an einer Stelle.
 *
 * Stammdaten beschreiben, was der Betrieb hat; Customizing regelt, wie die App arbeitet; Administration betrifft Technik
 * und Betrieb. Die Reiter Niederlassungen, Leistungen, Wertelisten und Marker sind unverändert umgezogen (vorher
 * Administration bzw. Customizing); alte Sprungziele leitet legacyLocation() auf den neuen Ort um.
 * „Ressourcen“ ist neu: Liste nach Ressourcentyp mit Symbol, Nummer/Kennung, Niederlassung und „Letzte Änderung“,
 * gepflegt im Dialog „Ressource bearbeiten“ einschließlich „Verwendbar bei Terminarten“ (dieselben Daten wie die Matrix
 * im Customizing → Terminplanung).
 */
export const MASTER_DATA_TABS = [['branches', 'Niederlassungen'], ['resources', 'Ressourcen'], ['services', 'Leistungen'], ['value-lists', 'Wertelisten'], ['labels', 'Marker']]

const LEGACY_TABS = {
	customizing: { 'value-lists': 'value-lists', labels: 'labels' },
	administration: { branches: 'branches', services: 'services' },
}

/** 0.92.0: Alte Orte (Customizing → Wertelisten/Marker, Administration → Niederlassungen/Leistungen) → Stammdaten. */
export function legacyLocation(view, tab) {
	const target = LEGACY_TABS[view]?.[tab]
	return target ? { view: 'master-data', tab: target } : null
}

/** Leitet einen gemerkten oder verlinkten alten Ort im Zustand um; liefert true, wenn umgeleitet wurde. */
export function redirectLegacyLocation(state) {
	const tab = state.view === 'customizing' ? state.customizingTab : (state.view === 'administration' ? state.administrationTab : '')
	const target = legacyLocation(state.view, tab)
	if (!target) return false
	state.view = target.view
	state.masterDataTab = target.tab
	if (state.customizingTab === 'value-lists' || state.customizingTab === 'labels') state.customizingTab = 'processes'
	if (state.administrationTab === 'branches' || state.administrationTab === 'services') state.administrationTab = 'onboarding'
	return true
}

/** „09.11.2026 10:42“ */
export function formatChangedAt(value) {
	const date = new Date(value || '')
	if (!value || Number.isNaN(date.getTime())) return ''
	const two = (number) => String(number).padStart(2, '0')
	return `${two(date.getDate())}.${two(date.getMonth() + 1)}.${date.getFullYear()} ${two(date.getHours())}:${two(date.getMinutes())}`
}

/** 0.92.0: Filter der Ressourcenliste (Typ, Niederlassung, Suche, inaktive ausblenden). */
export function filterResources(resources, filters = {}) {
	const query = String(filters.query || '').trim().toLocaleLowerCase('de')
	return (resources || []).filter((resource) => {
		if (filters.type && resource.resourceType !== filters.type) return false
		if (filters.branch && !availableInBranch(resource, filters.branch)) return false
		if (filters.hideInactive && resource.active === false) return false
		if (query && ![resource.name, resource.identifier, resource.description, resource.key].some((value) => String(value || '').toLocaleLowerCase('de').includes(query))) return false
		return true
	})
}

/**
 * 0.92.0: Neue Matrix-Zuordnung, wenn für eine Ressource im Dialog „Verwendbar bei Terminarten“ geändert wurde.
 * Liefert nur die Terminarten, deren Zuordnung sich ändert ({TYP: {allowed, defaults}}).
 */
export function assignmentChanges(types, resourceKey, wanted) {
	const result = {}
	for (const type of types || []) {
		const choice = wanted[type.key] || { allowed: false, preset: false }
		const allowed = allowedKeys(type)
		const defaults = (type.defaultResourceKeys || []).map((key) => String(key).toUpperCase())
		const nextAllowed = choice.allowed ? [...new Set([...allowed, resourceKey])] : allowed.filter((key) => key !== resourceKey)
		const nextDefaults = choice.allowed && choice.preset ? [...new Set([...defaults, resourceKey])] : defaults.filter((key) => key !== resourceKey)
		if (allowed.includes(resourceKey) !== Boolean(choice.allowed) || defaults.includes(resourceKey) !== Boolean(choice.allowed && choice.preset)) {
			result[type.key] = { allowed: nextAllowed, defaults: nextDefaults }
		}
	}
	return result
}

export function createMasterDataModule(ctx) {
	const { root, state } = ctx
	const api = (...args) => ctx.api(...args)
	const esc = (...args) => ctx.esc(...args)
	const render = (...args) => ctx.render(...args)
	const notifySuccess = (...args) => ctx.notifySuccess(...args)
	const notifyError = (...args) => ctx.notifyError(...args)
	const schedulingUrl = () => `${ctx.apiBase}/scheduling`

	const activeTab = () => (MASTER_DATA_TABS.some(([key]) => key === state.masterDataTab) ? state.masterDataTab : 'branches')
	const scheduling = () => state.schedulingAdmin || state.scheduling || { types: [], resources: [], resourceTypes: [], branches: [] }
	const branchList = () => {
		const fromCatalog = scheduling().branches
		if (Array.isArray(fromCatalog) && fromCatalog.length) return fromCatalog
		return (state.branches || []).filter((branch) => branch.key).map((branch) => ({ key: branch.key, name: branch.name || branch.key, active: branch.active !== false }))
	}
	const memberName = (uid) => (state.team?.members || []).find((member) => member.uid === uid)?.displayName || uid || ''

	/** Lädt, was der Reiter braucht (beim Wechsel, beim Umleiten von alten Orten und nach dem Wiederherstellen). */
	async function loadMasterDataTab(tab = activeTab()) {
		if (tab === 'branches') {
			state.branches = await api(ctx.branchUrl())
			await ctx.loadBranchLogos?.()
		}
		if (tab === 'services') ctx.applyCatalog?.(await api(ctx.articleUrl()))
		if (tab === 'labels') await ctx.loadLabels?.().catch((error) => notifyError(error.message))
		if (tab === 'resources') {
			const [all, active] = await Promise.all([api(`${schedulingUrl()}?all=1`), api(schedulingUrl())])
			state.schedulingAdmin = all; state.scheduling = active
			if (!state.branches?.length) state.branches = await api(ctx.branchUrl()).catch(() => state.branches || [])
		}
		if (tab === 'value-lists') state.customizing = (await api(ctx.urls.customizing)).lists || state.customizing
	}

	/** Öffnet einen Reiter der Stammdaten (auch aus anderen Bereichen, z. B. „Ressourcen pflegen“ im Customizing). */
	async function openMasterData(tab = 'branches', options = {}) {
		state.view = 'master-data'
		state.masterDataTab = tab
		if (options.valueList) { state.valueListKey = options.valueList; try { globalThis.localStorage?.setItem('bestatter:value-list-key', options.valueList) } catch { /* nur Bequemlichkeit */ } }
		try { await loadMasterDataTab(tab) } catch (error) { notifyError(error.message) }
		render()
	}

	function resourceFilters() {
		if (!state.resourceFilters) state.resourceFilters = { type: '', branch: '', query: '', hideInactive: false }
		return state.resourceFilters
	}

	function resourcesView() {
		const data = scheduling()
		const types = data.resourceTypes
		const branches = branchList()
		const filters = resourceFilters()
		const all = (data.resources || []).filter((resource) => isAssignable(resource) || resource.resourceType === 'STAFF')
		const visible = filterResources(all, filters)
		const branchName = (key) => branches.find((branch) => branch.key === key)?.name || key
		const branchText = (resource) => (resource.branchKeys || []).length ? resource.branchKeys.map(branchName).join(', ') : 'Alle'
		const changed = (resource) => { const when = formatChangedAt(resource.updatedAt); return when ? `${esc(when)}${resource.updatedBy ? ` · ${esc(memberName(resource.updatedBy))}` : ''}` : '<span class="bp-muted">–</span>' }
		const row = (resource) => `<tr data-resource-row="${Number(resource.id)}" class="${resource.active === false ? 'is-inactive' : ''}"><td class="bp-resource-type-cell">${resourceTypeIcon(resource.resourceType, types, esc)}<span>${esc(resourceTypeInfo(resource.resourceType, types).label)}</span></td><td><b>${esc(resource.name)}</b>${resource.description ? `<small class="bp-resource-description">${esc(resource.description.split('\n')[0])}</small>` : ''}</td><td>${resource.identifier ? `<span class="bp-resource-identifier">${esc(resource.identifier)}</span>` : '<span class="bp-muted">–</span>'}</td><td>${esc(branchText(resource))}</td><td>${resource.active === false ? 'inaktiv' : 'aktiv'}</td><td class="bp-resource-changed">${changed(resource)}</td><td><button type="button" class="bp-secondary" data-resource-edit="${Number(resource.id)}">Bearbeiten</button></td></tr>`
		const staff = visible.filter((resource) => resource.resourceType === 'STAFF')
		const groups = groupByType(visible.filter(isAssignable), types)
		const body = groups.map((group) => `<tbody data-resource-group="${esc(group.type)}"><tr class="bp-resource-group-row"><th colspan="7" scope="rowgroup">${resourceTypeIcon(group.type, types, esc)} ${esc(group.label)} <small>${group.items.length}</small></th></tr>${group.items.map(row).join('')}</tbody>`).join('')
			+ (staff.length ? `<tbody data-resource-group="STAFF"><tr class="bp-resource-group-row"><th colspan="7" scope="rowgroup">Früherer Typ „Mitarbeiter“ <small>wird keiner Terminart zugeordnet</small></th></tr>${staff.map(row).join('')}</tbody>` : '')
		const typeOptions = resourceTypeList(types).map((type) => `<option value="${esc(type.key)}" ${filters.type === type.key ? 'selected' : ''}>${esc(type.plural || type.label)}</option>`).join('')
		const branchOptions = branches.map((branch) => `<option value="${esc(branch.key)}" ${filters.branch === branch.key ? 'selected' : ''}>${esc(branch.name || branch.key)}</option>`).join('')
		const filterBar = `<div class="bp-resource-filters" role="search"><label><span>Ressourcentyp</span><select data-resource-filter="type"><option value="">Alle Typen</option>${typeOptions}</select></label>${branches.length ? `<label><span>Niederlassung</span><select data-resource-filter="branch"><option value="">Alle Niederlassungen</option>${branchOptions}</select></label>` : ''}<label><span>Suche</span><input type="search" data-resource-filter="query" value="${esc(filters.query || '')}" placeholder="Bezeichnung, Kennung, Beschreibung"></label><label class="bp-inline-check"><input type="checkbox" data-resource-filter="hideInactive" ${filters.hideInactive ? 'checked' : ''}> Inaktive ausblenden</label></div>`
		const empty = !all.length ? '<p class="bp-empty">Noch keine Ressourcen angelegt. Mit „+ Ressource“ beginnen, z. B. Kapelle, Abschiedsraum oder Bestattungswagen.</p>' : (!visible.length ? '<p class="bp-empty">Keine Ressource passt zum Filter.</p>' : '')
		const table = visible.length ? `<div class="bp-table-scroll"><table class="bp-resource-table"><thead><tr><th scope="col">Typ</th><th scope="col">Bezeichnung</th><th scope="col">Nummer / Kennung</th><th scope="col">Niederlassung</th><th scope="col">Status</th><th scope="col">Letzte Änderung</th><th scope="col"><span class="hidden-visually">Aktion</span></th></tr></thead>${body}</table></div>` : ''
		return `<section class="bp-panel bp-custom-table bp-resources-admin"><div class="bp-panel-head"><div><h3>Ressourcen</h3><p class="bp-muted">Kapellen, Räume, Fahrzeuge, Ausstattung und eigene Ressourcentypen für die Terminplanung. Welche Ressourcen eine Terminart anbietet, legen Sie im Dialog unter „Verwendbar bei Terminarten“ oder im Customizing → Terminplanung fest. Ist eine Ressource auf Niederlassungen beschränkt, bietet das Terminformular sie nur für Fälle dieser Niederlassungen an.${handbookMoreLink('stammdaten-betrieb-ressourcen', esc)}</p></div><div class="bp-row-actions"><button type="button" class="bp-secondary" data-open-value-list="RESOURCE_TYPE">Ressourcentypen pflegen</button><button type="button" class="bp-primary" id="resource-new">+ Ressource</button></div></div>${filterBar}${empty}${table}<p class="bp-muted bp-resource-count">${visible.length} von ${all.length} Ressource(n)</p></section>`
	}

	function masterDataView() {
		const tab = activeTab()
		state.masterDataTab = tab
		let body = ''
		if (tab === 'branches') body = `<div class="bp-page-head"><div><h3>Niederlassungen</h3></div></div>${ctx.branchesView ? ctx.branchesView() : ''}`
		else if (tab === 'resources') body = resourcesView()
		else if (tab === 'services') body = ctx.catalogAdminView ? ctx.catalogAdminView('') : ''
		else if (tab === 'value-lists') body = ctx.valueListsView ? ctx.valueListsView() : ''
		else if (tab === 'labels') body = ctx.labelsAdminView ? ctx.labelsAdminView() : ''
		return `<div class="bp-page-head"><div><p class="bp-eyebrow">Einstellungen</p><h2>Stammdaten</h2><p class="bp-muted">Was der Betrieb hat: Niederlassungen, Ressourcen, Leistungen, Wertelisten und Marker. Regeln und Abläufe stehen im Customizing, Technik und Betrieb in der Administration.</p></div></div><div class="bp-subtabs bp-master-data-tabs" role="tablist" aria-label="Stammdaten">${MASTER_DATA_TABS.map(([key, label]) => `<button type="button" role="tab" aria-selected="${key === tab}" class="${key === tab ? 'active' : ''}" data-master-data-tab="${key}">${esc(label)}</button>`).join('')}</div>${body}`
	}

	function resourceDialog(resource) {
		const data = scheduling()
		const types = data.resourceTypes
		const branches = branchList().filter((branch) => branch.active !== false || (resource?.branchKeys || []).includes(branch.key))
		const isNew = !resource?.id
		const current = resource || { name: '', identifier: '', description: '', resourceType: resourceTypeList(types)[0]?.key || 'EQUIPMENT', branchKeys: [], conflictRelevant: true, active: true, sortOrder: 0 }
		const typeChoices = [...resourceTypeList(types), ...(current.resourceType === 'STAFF' ? [{ key: 'STAFF', label: 'Mitarbeiter (früherer Typ)' }] : [])]
		const typeOptions = typeChoices.map((type) => `<option value="${esc(type.key)}" ${type.key === current.resourceType ? 'selected' : ''}>${esc(type.label)}</option>`).join('')
		const allBranches = !(current.branchKeys || []).length
		const branchChecks = branches.map((branch) => `<label class="bp-inline-check"><input type="checkbox" name="branchKeys" value="${esc(branch.key)}" ${(current.branchKeys || []).includes(branch.key) ? 'checked' : ''} ${allBranches ? 'disabled' : ''}> ${esc(branch.name || branch.key)}</label>`).join('')
		const scheduleTypes = data.types || []
		const usage = scheduleTypes.map((type) => { const allowed = !isNew && allowedKeys(type).includes(current.key); const preset = !isNew && (type.defaultResourceKeys || []).map((key) => String(key).toUpperCase()).includes(current.key); return `<tr data-resource-usage="${esc(type.key)}"><th scope="row">${esc(type.name)}${type.active === false ? ' <small>(inaktiv)</small>' : ''}</th><td><label class="bp-inline-check"><input type="checkbox" data-usage-allowed ${allowed ? 'checked' : ''}> zulässig</label></td><td><label class="bp-inline-check"><input type="checkbox" data-usage-preset ${preset ? 'checked' : ''} ${allowed ? '' : 'disabled'}> vorbelegt</label></td></tr>` }).join('')
		const changedAt = formatChangedAt(current.updatedAt)
		const staffHint = current.resourceType === 'STAFF' ? '<p class="bp-warning">Ressourcen vom früheren Typ „Mitarbeiter“ werden keiner Terminart zugeordnet. Mitarbeitende wählen Sie im Termin über „Mitarbeitende“; bitte einen anderen Typ wählen oder die Ressource inaktiv lassen.</p>' : ''
		return `<div class="bp-modal" role="dialog" aria-modal="true" aria-labelledby="resource-dialog-title"><form class="bp-wide-modal bp-resource-dialog" id="resource-form" novalidate><div class="bp-panel-head"><div><p class="bp-eyebrow">Stammdaten → Ressourcen</p><h2 id="resource-dialog-title">${isNew ? 'Ressource anlegen' : 'Ressource bearbeiten'}</h2></div><span class="bp-resource-icon bp-resource-icon-large" data-resource-type-preview aria-hidden="true">${resourceTypeIcon(current.resourceType, types, esc)}</span></div>${staffHint}<div class="bp-form-grid"><label><span>Ressourcentyp</span><select name="resourceType" required>${typeOptions}</select><small>Ressourcentypen pflegen Sie unter Stammdaten → Wertelisten → „Ressourcentypen“.</small></label><label><span>Bezeichnung</span><input name="name" value="${esc(current.name)}" required maxlength="180" placeholder="z. B. Bestattungswagen, Abschiedsraum"></label><label><span>Nummer / Kennung</span><input name="identifier" value="${esc(current.identifier || '')}" maxlength="40" placeholder="z. B. HB-RL 333 oder Raum 2"><small data-identifier-hint aria-live="polite"></small></label></div><label><span>Beschreibung</span><textarea name="description" rows="3" maxlength="2000" placeholder="z. B. Ausstattung, Kapazität, Besonderheiten">${esc(current.description || '')}</textarea></label><fieldset class="bp-resource-branches"><legend>Niederlassung</legend><label class="bp-inline-check"><input type="checkbox" name="allBranches" ${allBranches ? 'checked' : ''}> Alle Niederlassungen</label>${branchChecks ? `<div class="bp-resource-branch-list">${branchChecks}</div>` : '<small class="bp-muted">Noch keine Niederlassung angelegt.</small>'}<small>Ist die Ressource auf Niederlassungen beschränkt, bietet das Terminformular sie nur für Fälle dieser Niederlassungen an.</small></fieldset><div class="bp-form-grid"><label class="bp-inline-check"><input type="checkbox" name="conflictRelevant" ${current.conflictRelevant !== false ? 'checked' : ''}> Konflikt prüfen (keine Doppelbelegung)</label><label class="bp-inline-check"><input type="checkbox" name="active" ${current.active !== false ? 'checked' : ''}> Aktiv</label></div><details class="bp-resource-usage" ${isNew ? 'open' : ''}><summary>Verwendbar bei Terminarten</summary><p class="bp-muted">Dieselbe Zuordnung wie in der Matrix im Customizing → Terminplanung: „zulässig“ bietet die Ressource im Terminformular an, „vorbelegt“ setzt das Häkchen bei neuen Terminen schon.</p>${scheduleTypes.length ? `<div class="bp-table-scroll"><table><thead><tr><th scope="col">Terminart</th><th scope="col">Zulässig</th><th scope="col">Vorbelegt</th></tr></thead><tbody>${usage}</tbody></table></div>` : '<p class="bp-muted">Noch keine Terminarten angelegt.</p>'}</details><p class="bp-muted bp-resource-meta">${isNew ? 'Der technische Schlüssel wird beim Speichern aus der Bezeichnung gebildet.' : `Technischer Schlüssel: <code>${esc(current.key)}</code>${changedAt ? ` · Letzte Änderung: ${esc(changedAt)}${current.updatedBy ? ` von ${esc(memberName(current.updatedBy))}` : ''}` : ''}`}</p><p class="bp-save-state" id="resource-form-state" aria-live="polite"></p><div class="bp-modal-actions"><button type="button" class="bp-secondary" data-resource-cancel>Abbrechen</button><button type="submit" class="bp-primary">${isNew ? 'Ressource anlegen' : 'Speichern'}</button></div></form></div>`
	}

	function openResourceDialog(resource = null) {
		root.insertAdjacentHTML('beforeend', resourceDialog(resource))
		const modal = [...root.querySelectorAll('.bp-modal')].pop()
		const form = modal.querySelector('#resource-form')
		const stateText = modal.querySelector('#resource-form-state')
		const data = scheduling()
		const close = () => { modal.remove(); document.removeEventListener('keydown', onKey) }
		const onKey = (event) => { if (event.key === 'Escape') close() }
		document.addEventListener('keydown', onKey)
		modal.querySelector('[data-resource-cancel]').addEventListener('click', close)
		form.elements.resourceType.addEventListener('change', () => { const preview = modal.querySelector('[data-resource-type-preview]'); if (preview) preview.innerHTML = resourceTypeIcon(form.elements.resourceType.value, data.resourceTypes, esc); checkIdentifier() })
		const allBranches = form.elements.allBranches
		allBranches.addEventListener('change', () => { form.querySelectorAll('[name="branchKeys"]').forEach((input) => { input.disabled = allBranches.checked; if (allBranches.checked) input.checked = false }) })
		form.querySelectorAll('[data-resource-usage]').forEach((row) => { const allowed = row.querySelector('[data-usage-allowed]'); const preset = row.querySelector('[data-usage-preset]'); allowed.addEventListener('change', () => { preset.disabled = !allowed.checked; if (!allowed.checked) preset.checked = false }) })
		// Doppelte Kennung im selben Typ: Hinweis, keine Sperre (z. B. „Raum 2“ in zwei Niederlassungen).
		const checkIdentifier = () => {
			const hint = modal.querySelector('[data-identifier-hint]'); const value = form.elements.identifier.value.trim().toLocaleLowerCase('de')
			const twin = value && (data.resources || []).find((entry) => Number(entry.id) !== Number(resource?.id || 0) && entry.resourceType === form.elements.resourceType.value && String(entry.identifier || '').trim().toLocaleLowerCase('de') === value)
			hint.textContent = twin ? `Hinweis: „${twin.name}“ hat dieselbe Kennung.` : ''
		}
		form.elements.identifier.addEventListener('input', checkIdentifier)
		checkIdentifier()
		form.addEventListener('submit', async (event) => {
			event.preventDefault()
			const name = form.elements.name.value.trim()
			if (!name) { stateText.textContent = 'Bitte eine Bezeichnung eingeben.'; stateText.dataset.state = 'error'; form.elements.name.focus(); return }
			const branchKeys = allBranches.checked ? [] : [...form.querySelectorAll('[name="branchKeys"]:checked')].map((input) => input.value)
			if (!allBranches.checked && !branchKeys.length) { stateText.textContent = 'Bitte mindestens eine Niederlassung wählen oder „Alle Niederlassungen“ ankreuzen.'; stateText.dataset.state = 'error'; return }
			const payload = { key: resource?.key || '', name, resourceType: form.elements.resourceType.value, identifier: form.elements.identifier.value.trim(), description: form.elements.description.value.trim(), branchKeys, conflictRelevant: form.elements.conflictRelevant.checked, active: form.elements.active.checked, sortOrder: Number(resource?.sortOrder || 0) }
			const wanted = Object.fromEntries([...form.querySelectorAll('[data-resource-usage]')].map((row) => [row.dataset.resourceUsage, { allowed: row.querySelector('[data-usage-allowed]').checked, preset: row.querySelector('[data-usage-preset]').checked }]))
			const submit = form.querySelector('[type="submit"]'); submit.disabled = true
			stateText.textContent = 'Speichert …'; stateText.dataset.state = 'saving'
			try {
				const saved = await api(`${schedulingUrl()}/resources/${Number(resource?.id || 0)}`, { method: 'PUT', feedback: false, body: new URLSearchParams({ data: JSON.stringify(payload) }) })
				const changes = isAssignable(saved) ? assignmentChanges(data.types || [], saved.key, wanted) : {}
				if (Object.keys(changes).length) await api(`${schedulingUrl()}/assignments`, { method: 'PUT', feedback: false, body: new URLSearchParams({ data: JSON.stringify({ assignments: changes }) }) })
				const [all, active] = await Promise.all([api(`${schedulingUrl()}?all=1`), api(schedulingUrl())])
				state.schedulingAdmin = all; state.scheduling = active
				close(); render()
				notifySuccess(`„${resourceDisplayName(saved)}“ wurde gespeichert.`)
			} catch (error) {
				stateText.textContent = error.message; stateText.dataset.state = 'error'; submit.disabled = false
			}
		})
		form.elements.name.focus()
	}

	function bindMasterData() {
		root.querySelectorAll('[data-master-data-tab]').forEach((button) => button.addEventListener('click', async () => {
			state.masterDataTab = button.dataset.masterDataTab
			try { await loadMasterDataTab(state.masterDataTab) } catch (error) { notifyError(error.message) }
			render()
		}))
		root.querySelectorAll('[data-open-value-list]').forEach((button) => button.addEventListener('click', () => openMasterData('value-lists', { valueList: button.dataset.openValueList })))
		const panel = root.querySelector('.bp-resources-admin')
		if (!panel) return
		panel.querySelector('#resource-new')?.addEventListener('click', () => openResourceDialog(null))
		panel.querySelectorAll('[data-resource-edit]').forEach((button) => button.addEventListener('click', () => {
			const resource = (scheduling().resources || []).find((entry) => Number(entry.id) === Number(button.dataset.resourceEdit))
			if (resource) openResourceDialog(resource)
		}))
		panel.querySelectorAll('[data-resource-filter]').forEach((control) => {
			const key = control.dataset.resourceFilter
			const apply = () => {
				resourceFilters()[key] = control.type === 'checkbox' ? control.checked : control.value
				const focus = key === 'query' ? { start: control.selectionStart, end: control.selectionEnd } : null
				render()
				if (focus) { const input = root.querySelector('[data-resource-filter="query"]'); input?.focus(); input?.setSelectionRange?.(focus.start, focus.end) }
			}
			control.addEventListener(control.type === 'search' ? 'input' : 'change', apply)
		})
	}

	/** Vor der Anzeige: alter Ort → Stammdaten (Reiter laden und danach neu zeichnen). */
	function redirectToMasterData() {
		if (!redirectLegacyLocation(state)) return false
		loadMasterDataTab(state.masterDataTab).then(() => { if (state.view === 'master-data') render() }).catch(() => {})
		return true
	}

	return { masterDataView, bindMasterData, loadMasterDataTab, openMasterData, openResourceDialog, redirectToMasterData }
}
