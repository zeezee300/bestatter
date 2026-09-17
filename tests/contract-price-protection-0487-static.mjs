import assert from 'node:assert/strict'
import fs from 'node:fs'
const read = (path) => fs.readFileSync(path, 'utf8')

assert(read('appinfo/info.xml').includes('<version>0.65.0</version>'))
const articles = read('lib/Service/ArticleService.php')
for (const marker of ['contractProtection', 'amendmentsAllowed', 'CONTRACT_AMENDMENT', 'Nach Festschreibung des KVA oder Auftrags', "if ($current['sourcePackageId'] !== null) $line['unitPriceCents'] = $current['unitPriceCents']"]) assert(articles.includes(marker), `Preisbindung fehlt: ${marker}`)
const controller = read('lib/Controller/CatalogApiController.php')
assert(controller.includes("string $amendmentReason = ''") && controller.includes('$amendmentReason, $sideOrderId));'))
const ui = read('src/modules/commercial.js')
for (const marker of ['Vertragspreise geschützt', 'begin-contract-amendment', 'end-contract-amendment', 'amendmentReason: state.contractAmendmentReason', 'Vertragsnachtrag erfassen']) assert(ui.includes(marker), `Nachtragsoberfläche fehlt: ${marker}`)
for (const path of ['docs/VERTRAGSPREISE-UND-NACHTRAEGE.md', 'docs/ENTWICKLUNG.md', 'docs/UPGRADE.md']) assert(fs.existsSync(path), `Dokumentation fehlt: ${path}`)
console.log('0.48.7 Katalogpreis-Snapshot, Vertragsnachtrag und Rechnungssperre statisch geprüft.')
