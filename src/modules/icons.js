// "trash" from Tabler Icons, licensed under the MIT License:
// https://github.com/tabler/tabler-icons/blob/main/LICENSE
export const trashIcon = '<svg class="bp-trash-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16"></path><path d="M10 11v6"></path><path d="M14 11v6"></path><path d="M5 7l1 14h12l1-14"></path><path d="M9 7V4h6v3"></path></svg>'

// "printer" and "mail" from Tabler Icons, licensed under the MIT License.
// Source: https://tabler.io/icons
export const printIcon = '<svg class="bp-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 9V2h12v7"></path><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><path d="M6 14h12v8H6z"></path></svg>'
export const mailIcon = '<svg class="bp-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><path d="m3 7 9 6 9-6"></path></svg>'

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
