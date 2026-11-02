// 0.89.7: „Fall löschen“ und der Umgang mit Akten, die es nicht mehr gibt.
//
// Nach dem Löschen blieben Arbeitsübersicht, Aufgaben und Termine auf dem alten Stand (Einträge des gelöschten Falls
// waren weiter sichtbar und führten ins Leere), geplante Speicherungen des Formulars liefen noch gegen den gelöschten
// Fall, und ein Fehler beim Nachladen ließ die Seite ganz ohne Neuaufbau stehen. Hier wird nach dem Löschen alles
// aufgeräumt, nachgeladen und die Ansicht in jedem Fall neu aufgebaut.
import { caseKindOf, viewForCaseKind } from './case-kinds.js'

/** Fehler „Fall nicht (mehr) vorhanden“ – die API meldet ihn mit 404 oder (Prüfung im Dienst) 400 und Klartext. */
export function isCaseGone(error) {
	const status = Number(error?.status || 0)
	return status === 404 || (status === 400 && /nicht gefunden/i.test(String(error?.message || '')))
}

/** Entfernt alle Einträge eines Falls aus den geladenen Listen (Aufgaben, Termine, Dokumente, Verlauf …). */
export function forgetCase(state, caseId) {
	const id = Number(caseId || 0)
	if (!id) return
	const belongs = (item) => Number(item?.caseId ?? item?.case_id ?? 0) === id
	state.cases = (state.cases || []).filter((item) => Number(item.id) !== id)
	if (Array.isArray(state.caseSearch?.items)) state.caseSearch.items = state.caseSearch.items.filter((item) => Number(item.id) !== id)
	Object.keys(state.records || {}).forEach((key) => { if (Array.isArray(state.records[key])) state.records[key] = state.records[key].filter((item) => !belongs(item)) })
	if (Number(state.currentCase?.id || 0) === id || Number(state.currentCaseId || 0) === id) {
		state.currentCase = null; state.currentCaseId = 0; state.newCase = false; state.createInFlight = false; state.activeSideOrderId = 0
	}
	if (state.assistantSidebar && Number(state.assistantSidebar.caseId || 0) === id) {
		state.assistantSidebar.caseId = 0; state.assistantSidebar.preview = null
		try { globalThis.localStorage?.setItem('bestatter-assistant-case-id', '0') } catch (_) {}
	}
	if (state.expandedCaseIds) delete state.expandedCaseIds[id]
}

export function createCaseDeletion(ctx, { loadCaseSearch, caseGuidance }) {
	const { state } = ctx
	const silent = { feedback: false, loadingLabel: false }

	/** Übersicht, persönlicher Tag, Aufgaben, Termine und Fallliste neu laden; einzelne Fehler halten den Rest nicht auf. */
	async function refreshAfterCaseChange() {
		const results = await Promise.allSettled([
			ctx.api(ctx.urls.dashboard, silent).then((data) => { state.dashboard = data }),
			ctx.api(`${ctx.apiBase}/dashboard/personal-day`, silent).then((data) => { state.personalDay = data }),
			ctx.loadRecordType('task'), ctx.loadRecordType('schedule'),
			ctx.api(ctx.urls.cases, silent).then((data) => { state.cases = data }),
		])
		await loadCaseSearch(true).catch((error) => results.push({ status: 'rejected', reason: error }))
		return results.filter((result) => result.status === 'rejected').length
	}

	/** Einen nicht mehr vorhandenen Fall vergessen und zur passenden Liste wechseln. */
	async function leaveCase(item) {
		caseGuidance.clearPendingSave('case'); caseGuidance.clearPendingSave('order')
		forgetCase(state, item.id)
		state.view = viewForCaseKind(caseKindOf(item)); state.caseSearch.kind = caseKindOf(item); state.caseTab = 'overview'
		try {
			if (await refreshAfterCaseChange()) ctx.notifyWarning('Die Übersicht konnte nicht vollständig aktualisiert werden. Bitte die Seite bei Bedarf neu laden.')
		} finally {
			ctx.render()
		}
	}

	async function caseStillExists(caseId) {
		try { await ctx.api(`${ctx.urls.cases}/${caseId}`, silent); return true } catch (error) { return !isCaseGone(error) }
	}

	let deleting = false
	async function deleteCurrentCase() {
		// 0.75.2: Hinweise statt Sperre – laufende Aufbewahrungsfristen (Archivierung z. B. in DATEV) und Paperless-Kopien
		// werden angezeigt und müssen bestätigt werden. Ein Legal Hold bleibt eine Sperre.
		const item = state.currentCase
		if (!item?.id || deleting) return
		let check
		try { check = await ctx.api(`${ctx.urls.cases}/${item.id}/deletion-check`, { feedback: false, loadingLabel: 'Löschen wird vorbereitet …' }) } catch (error) { ctx.notifyError(error.message); return }
		if (check.legalHold) { ctx.notifyError('Für diesen Fall ist ein Legal Hold gesetzt. Bitte zuerst die Löschsperre aufheben.'); return }
		const notices = (check.notices || []).map((notice) => notice.text)
		const details = [...notices, 'Gelöscht werden alle Falldaten, der Fallordner (ohne Papierkorb, nicht wiederherstellbar) sowie die Termine und Aufgaben dieses Falls in den Kalendern. Das Änderungsprotokoll behält nur einen Nachweis ohne Inhalte.']
		if (!await ctx.confirmAction(`Fall ${item.caseNumber} endgültig löschen?`, { title: notices.length ? 'Fall löschen – bitte Hinweise prüfen' : 'Fall löschen', confirmLabel: notices.length ? 'Hinweise gelesen – endgültig löschen' : 'Endgültig löschen', danger: true, details })) return
		deleting = true
		// Geplante Speicherungen gehören zum Fall, der gleich gelöscht wird – sie dürfen danach nicht mehr laufen.
		caseGuidance.clearPendingSave('case'); caseGuidance.clearPendingSave('order')
		try {
			try {
				const result = await ctx.api(`${ctx.urls.cases}/${item.id}?confirmed=1`, { method: 'DELETE', feedback: `Fall ${item.caseNumber} wurde gelöscht.`, loadingLabel: 'Fall wird gelöscht …' })
				const calendarErrors = result?.calendar?.errors?.length || 0
				if (calendarErrors) ctx.notifyWarning(`${calendarErrors} Kalendereintrag/-einträge konnten nicht entfernt werden – bitte im Kalender löschen.`)
			} catch (error) {
				// Antwort verloren oder Fehler nach dem Löschen (z. B. Zeitüberschreitung): nachsehen, ob der Fall noch da ist.
				if (await caseStillExists(item.id)) { ctx.notifyError(error.message); return }
				ctx.notifyWarning(`Fall ${item.caseNumber} wurde gelöscht. Hinweis des Servers: ${error.message}`)
			}
			await leaveCase(item)
		} finally {
			deleting = false
		}
	}

	/** Öffnen eines Falls, der inzwischen (z. B. von anderer Stelle) gelöscht wurde: Hinweis und Listen bereinigen. */
	async function openFailed(caseId, error) {
		if (!isCaseGone(error)) { ctx.notifyError(error.message); return }
		ctx.notifyWarning('Dieser Fall ist nicht mehr vorhanden – er wurde inzwischen gelöscht. Die Übersicht wird aktualisiert.')
		const known = (state.cases || []).find((entry) => Number(entry.id) === Number(caseId)) || { id: Number(caseId), caseKind: state.caseSearch?.kind }
		await leaveCase(known)
	}

	return { deleteCurrentCase, openFailed, refreshAfterCaseChange }
}
