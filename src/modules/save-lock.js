/**
 * 0.92.8: Stammdaten- und Auftragsformular speichern denselben Datensatz (PUT …/master-data) mit
 * optimistischer Sperre (expectedUpdatedAt). Liefen zwei automatische Speichervorgänge gleichzeitig,
 * schickte der zweite noch den alten Änderungsstand mit: Der Server lehnte ihn mit „Der Fall wurde
 * inzwischen von einer anderen Person geändert“ ab und die zweite Eingabe ging verloren.
 *
 * runExclusive() reiht alle Speichervorgänge eines Fensters hintereinander. coalesce() fasst Aufrufe,
 * die während des Wartens eingehen, zu einem Lauf zusammen – dieser liest Formularwerte und
 * Änderungsstand erst beim Start und speichert damit immer den neuesten Stand.
 */
let tail = Promise.resolve()

export function runExclusive(task) {
	const run = tail.then(() => task())
	tail = run.catch(() => {})
	return run
}

export function coalesce(save) {
	let waiting = null
	let lastArgs = []
	return (...args) => {
		lastArgs = args.length ? args : lastArgs
		if (waiting) return waiting
		waiting = runExclusive(() => {
			waiting = null
			const callArgs = lastArgs
			lastArgs = []
			return save(...callArgs)
		})
		return waiting
	}
}
