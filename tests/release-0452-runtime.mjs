import { createRecordsModule } from '../src/modules/records.js'
import { createCasesModule } from '../src/modules/cases.js'

const assert = (condition, message) => { if (!condition) throw new Error(message) }
const esc = (value) => String(value ?? '').replace(/[&<>"']/g, '')
const recordContext = { state: { records: {}, cases: [] }, root: {}, urls: {}, esc }
const records = createRecordsModule(recordContext)

assert(records.formatRecordDate('2026-09-05T14:30:00') === '05.09.2026, 14:30 Uhr', 'ISO-Datum wird nicht deutsch formatiert.')
const badges = records.recordBadges({ status: 'IN_BEARBEITUNG', data: { priority: 'HOCH' } }, 'task')
assert(badges.includes('Status: In Bearbeitung') && badges.includes('Priorität: Hoch'), 'Status oder Priorität fehlen oder sind nicht beschrieben.')

const state = {
	dashboardDate: '2026-09-05',
	dashboard: { openCases: 1 },
	records: {
		task: [{ id: 1, caseId: 1, caseNumber: '2026-0001', title: 'Aufgabe prüfen', date: '2026-09-05T14:30:00', status: 'IN_BEARBEITUNG', data: { description: 'Test', priority: 'HOCH' } }],
		schedule: [{ id: 2, caseId: 1, caseNumber: '2026-0001', title: 'Trauerfeier', date: '2026-09-05T15:00:00', status: 'BESTAETIGT', data: {} }],
	},
	personalDay: { counts: {}, tasksToday: [], overdueTasks: [], schedulesToday: [], nextTasks: [] },
}
const cases = createCasesModule({
	state, sections: [], labels: {}, listMap: {}, fixedOptions: {}, esc,
	customizing: () => ({}), recordCaseNumber: (item) => item.caseNumber || '', title: () => '', render: () => {}, notifyError: () => {},
	formatRecordDate: records.formatRecordDate, recordBadges: records.recordBadges,
})
const html = cases.dashboardView()
assert(html.includes('05.09.2026, 14:30 Uhr'), 'Dashboard formatiert die Aufgabenfälligkeit nicht.')
assert(html.includes('05.09.2026, 15:00 Uhr'), 'Kalenderagenda formatiert den Termin nicht.')
assert(html.includes('Status: In Bearbeitung') && html.includes('Priorität: Hoch') && html.includes('Status: Bestätigt'), 'Dashboard zeigt Status/Priorität nicht vollständig beschrieben.')
assert(html.includes('bp-dashboard-record-meta') && html.includes('bp-agenda-meta'), 'Metadaten und Kennzeichen stehen nicht in einer gemeinsamen, umbrechenden Zeile.')
assert(!html.includes('2026-09-05T'), 'Dashboard enthält weiterhin ein technisches ISO-Datum mit T.')
console.log('0.45.2 Dashboard-Laufzeitpfad für Datum, Status und Priorität geprüft.')
