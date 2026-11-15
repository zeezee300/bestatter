// 0.92.0 – Symbole der Ressourcentypen (Stammdaten → Wertelisten → „Ressourcentypen“).
// Aus Tabler Icons 3.48.0, Outline-Variante, unverändert übernommen (MIT License, https://tabler.io/icons).
// Die Schlüssel stehen gleichlautend in lib/Service/SchedulingRules.php (RESOURCE_ICONS); tests/resources-0920-static.mjs prüft das.
export const RESOURCE_ICONS = {
	'building-church': { name: 'Kapelle / Kirche', svg: '<path d="M3 21l18 0"></path><path d="M10 21v-4a2 2 0 0 1 4 0v4"></path><path d="M10 5l4 0"></path><path d="M12 3l0 5"></path><path d="M6 21v-7m-2 2l8 -8l8 8m-2 -2v7"></path>' },
	'building': { name: 'Gebäude', svg: '<path d="M3 21l18 0"></path><path d="M9 8l1 0"></path><path d="M9 12l1 0"></path><path d="M9 16l1 0"></path><path d="M14 8l1 0"></path><path d="M14 12l1 0"></path><path d="M14 16l1 0"></path><path d="M5 21v-16a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v16"></path>' },
	'home': { name: 'Haus', svg: '<path d="M5 12l-2 0l9 -9l9 9l-2 0"></path><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-7"></path><path d="M9 21v-6a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v6"></path>' },
	'door': { name: 'Raum / Tür', svg: '<path d="M14 12v.01"></path><path d="M3 21h18"></path><path d="M6 21v-16a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v16"></path>' },
	'bed': { name: 'Aufbahrung / Bett', svg: '<path d="M5 9a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"></path><path d="M22 17v-3h-20"></path><path d="M2 8v9"></path><path d="M12 14h10v-2a3 3 0 0 0 -3 -3h-7v5"></path>' },
	'armchair': { name: 'Ausstattung / Sessel', svg: '<path d="M5 11a2 2 0 0 1 2 2v2h10v-2a2 2 0 1 1 4 0v4a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-4a2 2 0 0 1 2 -2"></path><path d="M5 11v-5a3 3 0 0 1 3 -3h8a3 3 0 0 1 3 3v5"></path><path d="M6 19v2"></path><path d="M18 19v2"></path>' },
	'car': { name: 'Pkw / Bestattungswagen', svg: '<path d="M5 17a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"></path><path d="M15 17a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"></path><path d="M5 17h-2v-6l2 -5h9l4 5h1a2 2 0 0 1 2 2v4h-2m-4 0h-6m-6 -6h15m-6 0v-5"></path>' },
	'car-suv': { name: 'Großraumwagen', svg: '<path d="M5 17a2 2 0 1 0 4 0a2 2 0 0 0 -4 0"></path><path d="M16 17a2 2 0 1 0 4 0a2 2 0 0 0 -4 0"></path><path d="M5 9l2 -4h7.438a2 2 0 0 1 1.94 1.515l.622 2.485h3a2 2 0 0 1 2 2v3"></path><path d="M10 9v-4"></path><path d="M2 7v4"></path><path d="M22.001 14.001a4.992 4.992 0 0 0 -4.001 -2.001a4.992 4.992 0 0 0 -4 2h-3a4.998 4.998 0 0 0 -8.003 .003"></path><path d="M5 12v-3h13"></path>' },
	'truck': { name: 'Transporter', svg: '<path d="M5 17a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"></path><path d="M15 17a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"></path><path d="M5 17h-2v-11a1 1 0 0 1 1 -1h9v12m-4 0h6m4 0h2v-6h-8m0 -5h5l3 5"></path>' },
	'bus': { name: 'Bus', svg: '<path d="M4 17a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"></path><path d="M16 17a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"></path><path d="M4 17h-2v-11a1 1 0 0 1 1 -1h14a5 7 0 0 1 5 7v5h-2m-4 0h-8"></path><path d="M16 5l1.5 7l4.5 0"></path><path d="M2 10l15 0"></path><path d="M7 5l0 5"></path><path d="M12 5l0 5"></path>' },
	'snowflake': { name: 'Kühlung', svg: '<path d="M10 4l2 1l2 -1"></path><path d="M12 2v6.5l3 1.72"></path><path d="M17.928 6.268l.134 2.232l1.866 1.232"></path><path d="M20.66 7l-5.629 3.25l.01 3.458"></path><path d="M19.928 14.268l-1.866 1.232l-.134 2.232"></path><path d="M20.66 17l-5.629 -3.25l-2.99 1.738"></path><path d="M14 20l-2 -1l-2 1"></path><path d="M12 22v-6.5l-3 -1.72"></path><path d="M6.072 17.732l-.134 -2.232l-1.866 -1.232"></path><path d="M3.34 17l5.629 -3.25l-.01 -3.458"></path><path d="M4.072 9.732l1.866 -1.232l.134 -2.232"></path><path d="M3.34 7l5.629 3.25l2.99 -1.738"></path>' },
	'fridge': { name: 'Kühlzelle', svg: '<path d="M5 5a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2l0 -14"></path><path d="M5 10h14"></path><path d="M9 13v3"></path><path d="M9 6v1"></path>' },
	'candle': { name: 'Kerze', svg: '<path d="M9 21h6v-10a1 1 0 0 0 -1 -1h-4a1 1 0 0 0 -1 1l0 10"></path><path d="M12 2l1.465 1.638a2 2 0 1 1 -3.015 .099l1.55 -1.737"></path>' },
	'flower': { name: 'Blumen / Dekoration', svg: '<path d="M9 12a3 3 0 1 0 6 0a3 3 0 1 0 -6 0"></path><path d="M12 2a3 3 0 0 1 3 3c0 .562 -.259 1.442 -.776 2.64l-.724 1.36l1.76 -1.893c.499 -.6 .922 -1 1.27 -1.205a2.968 2.968 0 0 1 4.07 1.099a3.011 3.011 0 0 1 -1.09 4.098c-.374 .217 -.99 .396 -1.846 .535l-2.664 .366l2.4 .326c1 .145 1.698 .337 2.11 .576a3.011 3.011 0 0 1 1.09 4.098a2.968 2.968 0 0 1 -4.07 1.098c-.348 -.202 -.771 -.604 -1.27 -1.205l-1.76 -1.893l.724 1.36c.516 1.199 .776 2.079 .776 2.64a3 3 0 0 1 -6 0c0 -.562 .259 -1.442 .776 -2.64l.724 -1.36l-1.76 1.893c-.499 .601 -.922 1 -1.27 1.205a2.968 2.968 0 0 1 -4.07 -1.098a3.011 3.011 0 0 1 1.09 -4.098c.374 -.218 .99 -.396 1.846 -.536l2.664 -.366l-2.4 -.325c-1 -.145 -1.698 -.337 -2.11 -.576a3.011 3.011 0 0 1 -1.09 -4.099a2.968 2.968 0 0 1 4.07 -1.099c.348 .203 .771 .604 1.27 1.205l1.76 1.894c-1 -2.292 -1.5 -3.625 -1.5 -4a3 3 0 0 1 3 -3"></path>' },
	'music': { name: 'Musik', svg: '<path d="M3 17a3 3 0 1 0 6 0a3 3 0 0 0 -6 0"></path><path d="M13 17a3 3 0 1 0 6 0a3 3 0 0 0 -6 0"></path><path d="M9 17v-13h10v13"></path><path d="M9 8h10"></path>' },
	'microphone': { name: 'Mikrofon / Technik', svg: '<path d="M9 5a3 3 0 0 1 3 -3a3 3 0 0 1 3 3v5a3 3 0 0 1 -3 3a3 3 0 0 1 -3 -3l0 -5"></path><path d="M5 10a7 7 0 0 0 14 0"></path><path d="M8 21l8 0"></path><path d="M12 17l0 4"></path>' },
	'box': { name: 'Material / Kiste', svg: '<path d="M12 3l8 4.5l0 9l-8 4.5l-8 -4.5l0 -9l8 -4.5"></path><path d="M12 12l8 -4.5"></path><path d="M12 12l0 9"></path><path d="M12 12l-8 -4.5"></path>' },
	'tool': { name: 'Werkzeug', svg: '<path d="M7 10h3v-3l-3.5 -3.5a6 6 0 0 1 8 8l6 6a2 2 0 0 1 -3 3l-6 -6a6 6 0 0 1 -8 -8l3.5 3.5"></path>' },
}
export const DEFAULT_RESOURCE_ICON = 'box'

/** Symbol eines Ressourcentyps als Inline-SVG; unbekannte Schlüssel fallen auf „box“ zurück. */
export function resourceIconSvg(key) {
	const icon = RESOURCE_ICONS[key] || RESOURCE_ICONS[DEFAULT_RESOURCE_ICON]
	return `<svg class="bp-resource-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false">${icon.svg}</svg>`
}

export const RESOURCE_ICON_KEYS = Object.keys(RESOURCE_ICONS)
