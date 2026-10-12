import { navIcons } from './icons.js'
import { handbookLink } from './handbook.js'

/**
 * 0.82.0 (OP-066) – Hauptnavigation als einklappbare Seitenleiste links.
 *
 * Drei Zustände:
 * - breit (Symbol und Text) und schmal (nur Symbole mit Tooltip) ab 1024 px Fensterbreite,
 * - darunter eine Schublade, die sich über den Inhalt legt („Menü“ oben links, Esc oder Klick daneben schließt).
 * Ohne eigene Wahl entscheidet die Fensterbreite (ab 1440 px breit, darunter schmal; reines CSS über .bp-nav-auto).
 * Die Wahl über „Navigation einklappen/ausklappen“ merkt sich der Browser (localStorage, nur Bequemlichkeit).
 * Die Einträge behalten ihr data-view-Attribut; Customizing und Administration stehen für Bestatter-Administratoren
 * im Abschnitt „Einstellungen“ (bisher Zahnradmenü).
 */
export const NAV_STORAGE_KEY = 'bestatter-navigation'
export const NAV_WIDE_QUERY = '(min-width: 1440px)'
export const NAV_DRAWER_QUERY = '(max-width: 1023.98px)'
const WORK_VIEWS = ['dashboard', 'capture', 'cases', 'task', 'schedule', 'document', 'inbox']
const SETTINGS_VIEWS = ['administration', 'customizing']

export function readNavPreference() {
	try {
		const value = globalThis.localStorage?.getItem(NAV_STORAGE_KEY)
		return value === 'expanded' || value === 'collapsed' ? value : 'auto'
	} catch { return 'auto' }
}

function storeNavPreference(value) {
	try { globalThis.localStorage?.setItem(NAV_STORAGE_KEY, value) } catch { /* privater Modus: Wahl gilt nur bis zum Neuladen */ }
}

const matches = (query) => Boolean(globalThis.matchMedia?.(query)?.matches)

/** Tatsächlich sichtbarer Zustand ab 1024 px: „expanded“ oder „collapsed“. */
export function effectiveNavMode(preference) {
	if (preference === 'expanded' || preference === 'collapsed') return preference
	return matches(NAV_WIDE_QUERY) ? 'expanded' : 'collapsed'
}

export function activeNavView(view) {
	return view === 'case-detail' ? 'cases' : view
}

export function createNavigation(ctx) {
	const { state, esc, mainNavigation } = ctx
	const nav = () => {
		if (!state.navigation) state.navigation = { preference: readNavPreference(), drawerOpen: false }
		return state.navigation
	}
	const labelOf = (view) => mainNavigation.find((item) => item[0] === view)?.[1] || view

	function item(view) {
		const label = labelOf(view)
		const active = activeNavView(state.view) === view
		return `<button type="button" class="bp-nav-item${active ? ' active' : ''}" data-view="${view}"${active ? ' aria-current="page"' : ''} title="${esc(label)}">${navIcons[view] || ''}<span class="bp-nav-label">${esc(label)}</span></button>`
	}

	function shellClass() {
		const { preference, drawerOpen } = nav()
		return `bp-app-shell ${preference === 'auto' ? 'bp-nav-auto' : `bp-nav-${preference}`}${drawerOpen ? ' bp-nav-drawer-open' : ''}`
	}

	function toggleState() {
		const expanded = effectiveNavMode(nav().preference) === 'expanded'
		return { expanded, label: expanded ? 'Navigation einklappen' : 'Navigation ausklappen' }
	}

	function navigationView() {
		const work = WORK_VIEWS.filter((view) => view !== 'capture' || state.assistantConfiguration?.guidedCaptureEnabled)
		const settings = state.team?.isBestatterAdmin ? SETTINGS_VIEWS : []
		const toggle = toggleState()
		return `<nav class="bp-app-nav" id="bestatter-navigation" aria-label="Bestatter-Navigation">
			<div class="bp-nav-head"><span class="bp-nav-brand">Bestatter</span><button type="button" class="bp-nav-toggle" id="nav-toggle" aria-controls="bestatter-navigation" aria-expanded="${toggle.expanded}" aria-label="${toggle.label}" title="${toggle.label}">${navIcons.collapse}${navIcons.expand}</button><button type="button" class="bp-nav-close" id="nav-close" aria-label="Menü schließen" title="Menü schließen">×</button></div>
			<div class="bp-nav-section bp-nav-work">${work.map(item).join('')}</div>
			${settings.length ? `<div class="bp-nav-section bp-nav-settings" role="group" aria-labelledby="bp-nav-settings-caption"><p class="bp-nav-caption" id="bp-nav-settings-caption">Einstellungen</p>${settings.map(item).join('')}</div>` : ''}
			<div class="bp-nav-tools">
				<a class="bp-nav-item bp-handbook-link" id="handbook-link" href="${esc(handbookLink(state))}" target="_blank" rel="noopener" title="Handbuch zum aktuellen Arbeitsbereich in einem neuen Fenster öffnen">${navIcons.handbook}<span class="bp-nav-label">Handbuch</span></a>
				${state.help?.available ? `<button type="button" class="bp-nav-item bp-help-topbar" id="help-toggle" aria-controls="bestatter-help" aria-expanded="${state.help.open}" title="Bedienhilfe">${navIcons.help}<span class="bp-nav-label">Bedienhilfe</span></button>` : ''}
				${ctx.appVersion ? `<p class="bp-nav-version">Version ${esc(ctx.appVersion)}</p>` : ''}
			</div>
		</nav><div class="bp-nav-backdrop" id="nav-backdrop" aria-hidden="true"></div>`
	}

	/** Leiste über dem Inhalt, nur bei schmalen Fenstern sichtbar: Menüknopf und aktueller Bereich. */
	function mobileBarView(title) {
		return `<div class="bp-mobile-bar"><button type="button" class="bp-nav-open" id="nav-open" aria-controls="bestatter-navigation" aria-expanded="${nav().drawerOpen}">${navIcons.menu}<span>Menü</span></button><span class="bp-mobile-title">${esc(title)}</span></div>`
	}

	function setDrawer(open, focusTarget = null) {
		nav().drawerOpen = open
		const shell = ctx.root.querySelector('.bp-app-shell')
		shell?.classList.toggle('bp-nav-drawer-open', open)
		ctx.root.querySelector('#nav-open')?.setAttribute('aria-expanded', String(open))
		// Fokus erst nach dem Einblenden setzen (vorher ist die Schublade noch unsichtbar und nicht fokussierbar).
		if (open) setTimeout(() => (ctx.root.querySelector('#bestatter-navigation .bp-nav-item.active') || ctx.root.querySelector('#bestatter-navigation .bp-nav-item'))?.focus(), 60)
		else focusTarget?.focus()
	}

	function syncToggle() {
		const button = ctx.root.querySelector('#nav-toggle')
		if (!button) return
		const toggle = toggleState()
		button.setAttribute('aria-expanded', String(toggle.expanded))
		button.setAttribute('aria-label', toggle.label)
		button.setAttribute('title', toggle.label)
	}

	function bindNavigation() {
		const root = ctx.root
		root.querySelector('#nav-toggle')?.addEventListener('click', () => {
			// Ohne neues Rendern umschalten, damit die Breite weich übergeht und Eingaben im Inhalt erhalten bleiben.
			const next = effectiveNavMode(nav().preference) === 'expanded' ? 'collapsed' : 'expanded'
			nav().preference = next
			storeNavPreference(next)
			const shell = root.querySelector('.bp-app-shell')
			shell?.classList.add('bp-nav-animate')
			shell?.classList.remove('bp-nav-auto', 'bp-nav-expanded', 'bp-nav-collapsed')
			shell?.classList.add(`bp-nav-${next}`)
			syncToggle()
		})
		root.querySelector('#nav-open')?.addEventListener('click', () => setDrawer(true))
		root.querySelector('#nav-close')?.addEventListener('click', () => setDrawer(false, root.querySelector('#nav-open')))
		root.querySelector('#nav-backdrop')?.addEventListener('click', () => setDrawer(false, root.querySelector('#nav-open')))
	}

	let mediaListenersInstalled = false
	function installMediaListeners() {
		if (mediaListenersInstalled) return
		mediaListenersInstalled = true
		// Esc schließt die Schublade, egal wo der Fokus gerade liegt.
		globalThis.document?.addEventListener('keydown', (event) => {
			if (event.key === 'Escape' && nav().drawerOpen) setDrawer(false, ctx.root.querySelector('#nav-open'))
		})
		if (!globalThis.matchMedia) return
		const wide = globalThis.matchMedia(NAV_WIDE_QUERY)
		const drawer = globalThis.matchMedia(NAV_DRAWER_QUERY)
		// Fensterbreite geändert: Beschriftung des Umschalters nachziehen, Schublade beim Vergrößern schließen.
		wide.addEventListener?.('change', () => syncToggle())
		drawer.addEventListener?.('change', () => { if (!drawer.matches && nav().drawerOpen) setDrawer(false) })
	}

	return { navigationView, mobileBarView, bindNavigation, installMediaListeners, shellClass, closeNavigationDrawer: () => { nav().drawerOpen = false } }
}
