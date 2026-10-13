// 0.88.1: Orientierung und Bedienführung in der Fallakte
// - Speicherstatus im Fallkopf (Stammdaten, Auftrag, Leistungen): „Speichert …“, „Gespeichert um …“, Fehler bleiben stehen
// - ausstehende automatische Speicherungen werden vor einem Bereichswechsel bzw. dem Verlassen der Seite sofort ausgeführt
// - Bereichsauswahl statt zwölf Reitern auf schmalen Bildschirmen, Menü „Weitere Aktionen“ im Fallkopf
// - Hinweis auf die bei der Fallanlage entstandenen Aufgaben und – einmal zentral – auf unbesetzte Rollen der Abläufe
import { processStateCounts } from './process-logic.js'

/** Statusfelder der automatisch speichernden Formulare und der Bereich, in dem sie stehen. */
export const SAVE_SOURCES = {
	'case-save-state': { label: 'Stammdaten', tab: 'master' },
	'order-save-state': { label: 'Auftrag', tab: 'order' },
	'service-save-state': { label: 'Leistungen', tab: 'services' },
}

const clock = (at) => new Date(at).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })

/**
 * Anzeige im Fallkopf aus den zuletzt gemeldeten Zuständen der Formulare. Ein Fehler bleibt stehen, bis dasselbe Formular
 * wieder erfolgreich gespeichert hat – auch nach einem Wechsel in einen anderen Bereich.
 */
export function saveIndicatorState(sources = {}, hasAutosave = false) {
	const entries = Object.entries(sources)
	const error = entries.find(([, entry]) => entry.state === 'error')
	if (error) return { state: 'error', source: error[0], text: `${SAVE_SOURCES[error[0]]?.label || 'Eingabe'} nicht gespeichert: ${error[1].text}` }
	if (entries.some(([, entry]) => entry.state === 'saving')) return { state: 'saving', text: 'Speichert …' }
	const warning = entries.find(([, entry]) => entry.state === 'warning')
	if (warning) return { state: 'warning', source: warning[0], text: warning[1].text }
	const saved = entries.filter(([, entry]) => entry.state === 'saved').sort((left, right) => right[1].at - left[1].at)[0]
	if (saved) return { state: 'saved', text: `Gespeichert um ${clock(saved[1].at)}` }
	return hasAutosave ? { state: '', text: 'Änderungen werden automatisch gespeichert.' } : null
}

export function createCaseGuidance(ctx) {
	const { root, state } = ctx
	const esc = (...args) => ctx.esc(...args)
	const render = (...args) => ctx.render(...args)

	// ------------------------------------------------------------ Ausstehende Speicherungen

	const pendingSaves = new Map()
	let savesInFlight = 0
	/** Ein Formular meldet eine geplante (verzögerte) Speicherung an; `flush` führt sie sofort aus. */
	function registerPendingSave(key, flush) { pendingSaves.set(key, flush) }
	function clearPendingSave(key) { pendingSaves.delete(key) }
	function flushPendingSaves() {
		const flushes = [...pendingSaves.values()]
		pendingSaves.clear()
		flushes.forEach((flush) => { try { flush() } catch { /* Fehler zeigt das Formular selbst an */ } })
		return flushes.length
	}
	/** Zählt laufende Speicherungen (Warnung beim Verlassen der Seite). */
	async function trackSave(promise) {
		savesInFlight++
		try { return await promise } finally { savesInFlight = Math.max(0, savesInFlight - 1) }
	}
	let listenersInstalled = false
	function installSaveGuards() {
		if (listenersInstalled) return
		listenersInstalled = true
		// Vor jedem Bereichs-, Unterreiter- oder Fallwechsel: geplante Speicherung sofort absenden (die Formularwerte werden dabei
		// noch vor dem Neuzeichnen gelesen).
		root.addEventListener('click', (event) => {
			if (pendingSaves.size && event.target.closest?.('[data-case-tab],[data-view],[data-master-tab],[data-case-id],#case-area-select')) flushPendingSaves()
		}, true)
		globalThis.addEventListener?.('beforeunload', (event) => {
			if (!pendingSaves.size && !savesInFlight) return
			flushPendingSaves()
			event.preventDefault()
			event.returnValue = ''
		})
	}

	// ------------------------------------------------------------ Speicherstatus im Fallkopf

	const saveIndicator = () => `<span class="bp-case-save-indicator" id="case-save-indicator" role="status" aria-live="polite" hidden><span class="bp-case-save-text"></span><button type="button" class="bp-link-button" data-save-indicator-open hidden>Anzeigen</button></span>`

	/**
	 * Schmale Kopfzeile, sobald der Fallkopf aus dem Bild gescrollt ist: Fallnummer, Name, aktiver Bereich und Speicherstatus
	 * bleiben sichtbar (auch in langen Formularen). Sie nimmt keinen Platz im Seitenfluss ein.
	 */
	function caseStickyBar(item, areaLabel) {
		return `<div class="bp-case-sticky" id="case-sticky" aria-hidden="true"><div class="bp-case-stickybar"><span class="bp-case-sticky-title"><b>${esc(item.caseNumber || '')}</b> ${esc(`${item.lastName || ''}, ${item.firstName || ''}`)}<small>${esc(areaLabel || '')}</small></span><span class="bp-case-save-indicator bp-case-save-copy" hidden><span class="bp-case-save-text"></span><button type="button" class="bp-link-button" data-save-indicator-open hidden tabindex="-1">Anzeigen</button></span></div></div>`
	}

	function paintSaveIndicator() {
		const hasAutosave = Object.keys(SAVE_SOURCES).some((id) => document.getElementById(id))
		const view = saveIndicatorState(state.caseSaveStatus?.sources || {}, hasAutosave)
		root.querySelectorAll('.bp-case-save-indicator').forEach((indicator) => {
			indicator.hidden = !view
			if (!view) return
			indicator.dataset.state = view.state
			indicator.querySelector('.bp-case-save-text').textContent = view.text
			const open = indicator.querySelector('[data-save-indicator-open]')
			const tab = view.source ? SAVE_SOURCES[view.source]?.tab : ''
			open.hidden = !(view.state === 'error' && tab && tab !== state.caseTab)
			open.dataset.saveIndicatorOpen = tab || ''
		})
	}

	function bindStickyBar() {
		const sticky = root.querySelector('#case-sticky')
		const title = root.querySelector('.bp-case-title-row')
		const content = root.querySelector('.bp-content')
		if (!sticky || !title || !content || !globalThis.IntersectionObserver) return
		new IntersectionObserver(([entry]) => { sticky.classList.toggle('is-visible', !entry.isIntersecting) }, { root: content, threshold: 0 }).observe(title)
	}

	function bindSaveIndicator() {
		const caseId = Number(state.currentCase?.id || 0)
		if (!state.caseSaveStatus || state.caseSaveStatus.caseId !== caseId) state.caseSaveStatus = { caseId, sources: {} }
		for (const id of Object.keys(SAVE_SOURCES)) {
			const element = document.getElementById(id)
			if (!element) continue
			const record = () => {
				const saveState = element.dataset.state || ''
				if (!saveState) return
				// Ein neuer Fall bekommt erst beim ersten Speichern eine Nummer – die Meldungen gehören dann zu ihm.
				const currentId = Number(state.currentCase?.id || 0)
				if (state.caseSaveStatus.caseId !== currentId) state.caseSaveStatus = { caseId: currentId, sources: {} }
				state.caseSaveStatus.sources[id] = { state: saveState, text: element.textContent.trim(), at: Date.now() }
				paintSaveIndicator()
			}
			new MutationObserver(record).observe(element, { childList: true, characterData: true, subtree: true, attributes: true, attributeFilter: ['data-state'] })
		}
		root.querySelectorAll('[data-save-indicator-open]').forEach((button) => button.addEventListener('click', (event) => {
			root.querySelector(`.bp-case-tabs [data-case-tab="${event.currentTarget.dataset.saveIndicatorOpen}"]`)?.click()
		}))
		paintSaveIndicator()
	}

	// ------------------------------------------------------------ Bereichsauswahl und Aktionsmenü

	function caseAreaSelect(tabs, isLocked) {
		return `<label class="bp-case-area"><span>Bereich</span><select id="case-area-select" aria-label="Bereich der Fallakte wählen">${tabs.map(([key, label]) => `<option value="${esc(key)}" ${state.caseTab === key ? 'selected' : ''} ${isLocked(key) ? 'disabled' : ''}>${esc(label)}</option>`).join('')}</select></label>`
	}

	/** „Falldaten exportieren“, „Legal Hold“ und „Fall löschen“ – seltene Verwaltungsaktionen in einem beschrifteten Menü. */
	function caseActionMenu(item, unsaved) {
		if (unsaved) return ''
		const admin = Boolean(state.team?.isBestatterAdmin)
		const hold = admin ? `<button type="button" class="bp-menu-item" id="toggle-retention-hold" role="menuitem" title="${item.retentionHold ? 'Die Löschsperre nach Angabe eines Grundes aufheben.' : 'Den Fall vor Löschung und Anonymisierung schützen.'}">${item.retentionHold ? 'Legal Hold aufheben' : 'Legal Hold setzen'}</button>` : ''
		const remove = admin ? '<button type="button" class="bp-menu-item bp-menu-danger" id="delete-case" role="menuitem" title="Diesen Fall nach Sicherheitsabfrage endgültig löschen.">Fall löschen</button>' : ''
		return `<details class="bp-action-menu" id="case-action-menu"><summary class="bp-secondary" aria-haspopup="menu">Weitere Aktionen</summary><div class="bp-action-menu-list" role="menu" aria-label="Weitere Aktionen zum Fall"><button type="button" class="bp-menu-item" id="export-case" role="menuitem" title="Alle strukturierten Daten dieses Falls als Exportdatei herunterladen.">Falldaten exportieren</button>${hold}${remove}</div></details>`
	}

	function bindCaseHead() {
		root.querySelector('#case-area-select')?.addEventListener('change', (event) => {
			root.querySelector(`.bp-case-tabs [data-case-tab="${CSS.escape(event.target.value)}"]`)?.click()
		})
		const menu = root.querySelector('#case-action-menu')
		if (!menu) return
		menu.querySelectorAll('.bp-menu-item').forEach((item) => item.addEventListener('click', () => { menu.open = false }))
		menu.addEventListener('keydown', (event) => { if (event.key === 'Escape' && menu.open) { event.preventDefault(); menu.open = false; menu.querySelector('summary')?.focus() } })
		menu.addEventListener('toggle', () => { if (menu.open) menu.querySelector('.bp-menu-item')?.focus() })
	}
	let outsideListener = false
	function installMenuCloser() {
		if (outsideListener) return
		outsideListener = true
		document.addEventListener('click', (event) => {
			root.querySelectorAll('details.bp-action-menu[open]').forEach((menu) => { if (!menu.contains(event.target)) menu.open = false })
		})
	}

	// ------------------------------------------------------------ Aufgaben nach der Fallanlage

	const dueText = (value) => {
		const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/)
		if (!match) return 'ohne Termin'
		return `fällig ${match[3]}.${match[2]}.${match[1]}${match[4] && `${match[4]}:${match[5]}` !== '00:00' ? `, ${match[4]}:${match[5]} Uhr` : ''}`
	}
	const creationSteps = () => {
		const notice = state.creationNotice
		const item = state.currentCase
		if (!notice || !item || Number(notice.caseId) !== Number(item.id)) return []
		const process = state.process
		if (!process || process.error || Number(process.caseId) !== Number(item.id)) return []
		return (process.phases || []).flatMap((phase) => phase.steps || []).filter((view) => ['open', 'overdue'].includes(view.state))
	}

	function roleSettingsButton() {
		return state.team?.isBestatterAdmin ? '<button type="button" class="bp-secondary" data-open-role-settings>Rollen zuordnen</button>' : ''
	}

	/** Kleiner Hinweis im Fallkopf, bis der Hinweis in der Übersicht ausgeblendet wird (verschiebt das Formular nicht). */
	function creationChip() {
		const steps = creationSteps()
		if (!steps.length || state.caseTab === 'overview') return ''
		return `<button type="button" class="bp-creation-chip" data-case-tab="overview" title="Zeigt in der Übersicht, welche Aufgaben mit der Anlage entstanden sind.">${steps.length} Aufgaben entstanden</button>`
	}

	function creationNoticeView() {
		const steps = creationSteps()
		if (!steps.length) return ''
		const unresolved = [...new Set(steps.filter((view) => view.roleUnresolved).map((view) => view.roleLabel).filter(Boolean))]
		const counts = processStateCounts(state.process?.phases || [])
		const rows = steps.map((view) => `<li><b>${esc(view.title)}</b><small>${esc([dueText(view.due), view.assigneeName || '', view.roleUnresolved ? `Rolle ${view.roleLabel} unbesetzt → Fallverantwortung` : (view.roleLabel || '')].filter(Boolean).join(' · '))}</small></li>`).join('')
		const roleText = unresolved.length ? `<p class="bp-role-hint"><b>Rollen ohne Nextcloud-Gruppe mit Mitgliedern:</b> ${esc(unresolved.join(', '))}. Deren Aufgaben liegen bei der Fallverantwortung.${state.team?.isBestatterAdmin ? '' : ' Bitte die Administration bitten, die Rollen Nextcloud-Gruppen zuzuordnen.'} ${roleSettingsButton()}</p>` : ''
		return `<section class="bp-panel bp-creation-notice" aria-labelledby="creation-notice-title"><div class="bp-panel-head"><div><p class="bp-eyebrow">Mit der Anlage entstanden</p><h3 id="creation-notice-title">${steps.length} ${steps.length === 1 ? 'Aufgabe' : 'Aufgaben'} aus dem Ablauf</h3><p class="bp-muted">${counts.later} weitere Schritte entstehen erst, wenn ihr Auslöser eintritt – zum Beispiel nach dem Auftrag oder einem bestätigten Termin.</p></div><div class="bp-head-actions"><button type="button" class="bp-primary" data-case-tab="task">Zu den Aufgaben</button><button type="button" class="bp-secondary" data-dismiss-creation-notice>Ausblenden</button></div></div><ul class="bp-creation-list">${rows}</ul>${roleText}</section>`
	}

	// ------------------------------------------------------------ Unbesetzte Rollen (einmal zentral)

	const ROLE_NOTICE_KEY = 'bestatter:role-notice-dismissed'
	const roleSignature = (roles) => roles.map((role) => `${role.key}:${role.group}`).sort().join('|')
	function readDismissed() { try { return globalThis.localStorage?.getItem(ROLE_NOTICE_KEY) || '' } catch { return '' } }

	async function loadRoleStatus() {
		if (!state.team?.isBestatterAdmin) return null
		try { state.roleStatus = await ctx.api(`${ctx.apiBase}/process/role-status`, { feedback: false }) } catch { state.roleStatus = null }
		return state.roleStatus
	}

	/** Startseite, nur für Administratoren: nennt die Rollen und führt zur Zuordnung; ausgeblendet bis sich die Lage ändert. */
	function roleNoticeView() {
		if (!state.team?.isBestatterAdmin) return ''
		const unresolved = state.roleStatus?.unresolved || []
		if (!unresolved.length || readDismissed() === roleSignature(unresolved)) return ''
		const usersUrl = globalThis.OC?.generateUrl ? globalThis.OC.generateUrl('/settings/users') : '/index.php/settings/users'
		return `<aside class="bp-warning bp-role-notice" role="note"><div><b>Rollen für Abläufe unbesetzt:</b> ${unresolved.map((role) => `${esc(role.label)} (${esc(role.reason)})`).join(', ')}. Aufgaben dieser Rollen gehen an die jeweilige Fallverantwortung. In den Installationsparametern die Gruppen-ID eintragen und in der Nextcloud-Benutzerverwaltung Mitglieder aufnehmen.</div><div class="bp-row-actions">${roleSettingsButton()}<a class="bp-secondary bp-link-as-button" href="${esc(usersUrl)}" target="_blank" rel="noopener">Nextcloud-Benutzerverwaltung</a><button type="button" class="bp-secondary" data-dismiss-role-notice="${esc(roleSignature(unresolved))}">Ausblenden</button></div></aside>`
	}

	/** Nach dem ersten Speichern eines neuen Falls: Ablauf laden, Hinweis vormerken und kurz melden. */
	async function afterCaseCreated() {
		state.creationNotice = { caseId: state.currentCase.id }
		if (ctx.loadProcess) await ctx.loadProcess(state.currentCase.id)
		const count = (state.process?.phases || []).flatMap((phase) => phase.steps || []).filter((view) => ['open', 'overdue'].includes(view.state)).length
		if (count) ctx.notifySuccess(`Fall ${state.currentCase.caseNumber} angelegt – ${count} ${count === 1 ? 'Aufgabe' : 'Aufgaben'} aus dem Ablauf entstanden. Details in der Übersicht.`)
	}

	function bindNotices() {
		root.querySelectorAll('[data-open-role-settings]').forEach((button) => button.addEventListener('click', async () => {
			// Rollenzuordnung steht in Administration → Systemprüfung → Installationsparameter.
			state.view = 'administration'; state.administrationTab = 'system'
			if (ctx.loadSystemCheck) await ctx.loadSystemCheck().catch(() => {})
			render()
			requestAnimationFrame(() => { const roles = root.querySelector('.bp-role-groups'); roles?.scrollIntoView({ block: 'center' }); roles?.querySelector('input')?.focus({ preventScroll: true }) })
		}))
		root.querySelectorAll('[data-dismiss-creation-notice]').forEach((button) => button.addEventListener('click', () => { state.creationNotice = null; render() }))
		root.querySelectorAll('[data-dismiss-role-notice]').forEach((button) => button.addEventListener('click', () => {
			try { globalThis.localStorage?.setItem(ROLE_NOTICE_KEY, button.dataset.dismissRoleNotice) } catch { /* nur für diese Sitzung */ }
			button.closest('.bp-role-notice')?.remove()
		}))
	}

	function bindCaseGuidance() {
		installSaveGuards()
		installMenuCloser()
		bindNotices()
		if (state.view !== 'case-detail') return
		bindSaveIndicator()
		bindStickyBar()
		bindCaseHead()
	}

	return { registerPendingSave, clearPendingSave, flushPendingSaves, trackSave, saveIndicator, caseStickyBar, caseAreaSelect, caseActionMenu, creationChip, creationNoticeView, roleNoticeView, loadRoleStatus, afterCaseCreated, bindCaseGuidance }
}
