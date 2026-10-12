/**
 * 0.79.3 – Neuer, noch nicht gespeicherter Fall: Es gibt noch keine Fallnummer (ID), daher sind nur die Stammdaten
 * bedienbar. Vorher waren alle Reiter aktiv; Übersicht, Nebenaufträge, Abmeldungen und Dokumente riefen die Schnittstelle
 * mit „undefined“ auf und reagierten nicht, Aufgaben/Termine/Fall-Verlauf zeigten Einträge aller Fälle.
 */
export const UNSAVED_CASE_HINT = 'Verfügbar, sobald der Fall gespeichert ist – dazu Vor- und Nachname der verstorbenen Person eintragen.'
export const UNSAVED_CASE_TABS = ['master']
export function isUnsavedCase(state) {
	return !Number(state.currentCase?.id || 0)
}
