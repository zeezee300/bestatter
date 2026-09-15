import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import path from 'node:path'

const root=path.resolve(process.argv[2]||'.')
const read=(file)=>readFile(path.join(root,file),'utf8')
const [customizing,service,reporting,routes,application,records,main,css]=await Promise.all([
	read('src/modules/customizing.js'),read('lib/Service/CustomizingService.php'),read('lib/Service/ReportingService.php'),read('appinfo/routes.php'),read('lib/AppInfo/Application.php'),read('src/modules/records.js'),read('src/main.js'),read('css/style.css'),
])
assert.match(customizing,/bp-value-list-workspace/)
assert.match(customizing,/valueRow\(\{id:0,value:'',label:''\}/)
assert.match(customizing,/Neu · ungespeichert/)
assert.match(service,/assertUniqueValue/)
assert.match(service,/lastInsertId\('bestatter_choice_items'\)/)
assert.match(reporting,/class ReportingService/)
for(const report of ['cases','services','invoices','branches','workload'])assert.match(reporting,new RegExp(`'${report}'`))
assert.match(routes,/reportingApi#csv/)
assert.match(application,/registerSearchProvider\(CaseSearchProvider::class\)/)
assert.match(records,/Technische oder schreibgeschützte Datei/)
assert.match(main,/scrollPositions = new Map/)
assert.match(main,/state\.view === 'customizing'/)
assert.match(css,/bp-value-table tr\.is-dirty/)
assert.match(css,/bp-report-grid/)
assert.match(css,/bp-case-table td::before/)
console.log('0.40.0 Wertelisten, Reporting, Suche, Dokumentdarstellung und mobile Fallliste statisch geprüft.')
