// "trash" from Tabler Icons, licensed under the MIT License:
// https://github.com/tabler/tabler-icons/blob/main/LICENSE
export const trashIcon = '<svg class="bp-trash-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16"></path><path d="M10 11v6"></path><path d="M14 11v6"></path><path d="M5 7l1 14h12l1-14"></path><path d="M9 7V4h6v3"></path></svg>'

// "printer" and "mail" from Tabler Icons, licensed under the MIT License.
// Source: https://tabler.io/icons
export const printIcon = '<svg class="bp-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 9V2h12v7"></path><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><path d="M6 14h12v8H6z"></path></svg>'
export const mailIcon = '<svg class="bp-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><path d="m3 7 9 6 9-6"></path></svg>'

// "help-circle" from Tabler Icons, licensed under the MIT License.
export const helpIcon = '<svg class="bp-help-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z"></path><path d="M12 17v.01"></path><path d="M12 13.5a1.5 1.5 0 0 1 1-1.5 2.6 2.6 0 1 0-3-4"></path></svg>'

// "book" from Tabler Icons, licensed under the MIT License.
export const bookIcon = '<svg class="bp-help-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 19a9 9 0 0 1 9 0a9 9 0 0 1 9 0"></path><path d="M3 6a9 9 0 0 1 9 0a9 9 0 0 1 9 0"></path><path d="M3 6v13"></path><path d="M12 6v13"></path><path d="M21 6v13"></path></svg>'

export function trashButton(className, label, attributes = '', title = label) {
	return `<button type="button" class="bp-danger bp-icon-button bp-delete-icon-button ${className}" aria-label="${label}" title="${title}" ${attributes}>${trashIcon}</button>`
}

function documentActionButton(icon, className, label, attributes = '', title = label) {
	return `<button type="button" class="bp-secondary bp-document-action-button ${className}" aria-label="${label}" title="${title}" ${attributes}>${icon}<span>${label}</span></button>`
}

export function printButton(className, label = 'Drucken', attributes = '', title = label) {
	return documentActionButton(printIcon, className, label, attributes, title)
}

export function mailButton(className, label = 'Mit Mailprogramm teilen', attributes = '', title = label) {
	return documentActionButton(mailIcon, className, label, attributes, title)
}

// "settings" from Tabler Icons, licensed under the MIT License.
// Source: https://tabler.io/icons
export const settingsIcon = '<svg class="bp-settings-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z"></path><path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0"></path></svg>'

// 0.82.0 – Symbole der Seitennavigation: "layout-dashboard", "bolt", "folders", "list-check", "calendar", "files",
// "inbox", "adjustments-horizontal", "layout-sidebar-left-collapse/-expand" und "menu-2" aus Tabler Icons
// (MIT License, https://tabler.io/icons). Einheitlich 24×24, Strichstärke über CSS (.bp-nav-icon).
const navSvg = (paths) => `<svg class="bp-nav-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">${paths}</svg>`
export const navIcons = {
	dashboard: navSvg('<path d="M5 4h4a1 1 0 0 1 1 1v6a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1v-6a1 1 0 0 1 1 -1"></path><path d="M5 16h4a1 1 0 0 1 1 1v2a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1v-2a1 1 0 0 1 1 -1"></path><path d="M15 12h4a1 1 0 0 1 1 1v6a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1v-6a1 1 0 0 1 1 -1"></path><path d="M15 4h4a1 1 0 0 1 1 1v2a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1v-2a1 1 0 0 1 1 -1"></path>'),
	capture: navSvg('<path d="M13 3l0 7l6 0l-8 11l0 -7l-6 0l8 -11"></path>'),
	cases: navSvg('<path d="M9 3h3l2 2h5a2 2 0 0 1 2 2v7a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-9a2 2 0 0 1 2 -2"></path><path d="M17 16v2a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-9a2 2 0 0 1 2 -2h2"></path>'),
	task: navSvg('<path d="M3.5 5.5l1.5 1.5l2.5 -2.5"></path><path d="M3.5 11.5l1.5 1.5l2.5 -2.5"></path><path d="M3.5 17.5l1.5 1.5l2.5 -2.5"></path><path d="M11 6l9 0"></path><path d="M11 12l9 0"></path><path d="M11 18l9 0"></path>'),
	schedule: navSvg('<path d="M4 7a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12z"></path><path d="M16 3v4"></path><path d="M8 3v4"></path><path d="M4 11h16"></path><path d="M11 15h1"></path><path d="M12 15v3"></path>'),
	document: navSvg('<path d="M15 3v4a1 1 0 0 0 1 1h4"></path><path d="M18 17h-7a2 2 0 0 1 -2 -2v-10a2 2 0 0 1 2 -2h4l5 5v7a2 2 0 0 1 -2 2z"></path><path d="M16 17v2a2 2 0 0 1 -2 2h-7a2 2 0 0 1 -2 -2v-10a2 2 0 0 1 2 -2h2"></path>'),
	inbox: navSvg('<path d="M4 6a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2z"></path><path d="M4 13h3l3 3h4l3 -3h3"></path>'),
	customizing: navSvg('<path d="M12 6a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"></path><path d="M4 6l8 0"></path><path d="M16 6l4 0"></path><path d="M6 12a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"></path><path d="M4 12l2 0"></path><path d="M10 12l10 0"></path><path d="M15 18a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"></path><path d="M4 18l11 0"></path><path d="M19 18l1 0"></path>'),
	administration: navSvg('<path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z"></path><path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0"></path>'),
	handbook: navSvg('<path d="M3 19a9 9 0 0 1 9 0a9 9 0 0 1 9 0"></path><path d="M3 6a9 9 0 0 1 9 0a9 9 0 0 1 9 0"></path><path d="M3 6l0 13"></path><path d="M12 6l0 13"></path><path d="M21 6l0 13"></path>'),
	help: navSvg('<path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z"></path><path d="M12 17v.01"></path><path d="M12 13.5a1.5 1.5 0 0 1 1-1.5 2.6 2.6 0 1 0-3-4"></path>'),
	collapse: navSvg('<path d="M4 6a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2z"></path><path d="M9 4v16"></path><path d="M15 10l-2 2l2 2"></path>'),
	expand: navSvg('<path d="M4 6a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2z"></path><path d="M9 4v16"></path><path d="M14 10l2 2l-2 2"></path>'),
	menu: navSvg('<path d="M4 6l16 0"></path><path d="M4 12l16 0"></path><path d="M4 18l16 0"></path>'),
}
