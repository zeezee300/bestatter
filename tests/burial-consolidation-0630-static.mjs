import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8')
const migration = read('lib/Migration/Version3800Date20260917000000.php')
const cases = read('lib/Service/CaseService.php')
const customizing = read('lib/Service/CustomizingService.php')
for (const marker of ["'TREE'", "'FOREST_BASIC'", "'FOREST_COMMUNITY'", "'FOREST'", 'postSchemaChange', "getColumn('funeral_type')->setLength(180)"]) assert(migration.includes(marker))
assert.match(cases, /\$data\['funeral_type'\] = \$root\['label'\]/)
assert.match(cases, /\$data\['burial_variant_label'\] = \$chosen\['label'\]/)
assert.match(customizing, /\$row\['list_key'\] !== 'FUNERAL_TYPE'/)
assert(!read('src/modules/customizing.js').includes('Historische Werteliste „Bestattungsart“'))
assert(read('appinfo/info.xml').includes('<version>0.65.0</version>'))
console.log('0.65.0 Migration, Bestattungsart-Ableitung und Altlisten-Ausblendung: OK')
