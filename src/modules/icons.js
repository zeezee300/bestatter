// "trash" from Tabler Icons, licensed under the MIT License:
// https://github.com/tabler/tabler-icons/blob/main/LICENSE
export const trashIcon = '<svg class="bp-trash-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16"></path><path d="M10 11v6"></path><path d="M14 11v6"></path><path d="M5 7l1 14h12l1-14"></path><path d="M9 7V4h6v3"></path></svg>'

export function trashButton(className, label, attributes = '', title = label) {
	return `<button type="button" class="bp-danger bp-icon-button bp-delete-icon-button ${className}" aria-label="${label}" title="${title}" ${attributes}>${trashIcon}</button>`
}
