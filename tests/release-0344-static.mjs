import fs from 'node:fs'
import path from 'node:path'

const root = process.argv[2] || '.'
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const assert = (condition, message) => { if (!condition) throw new Error(message) }
const documents = read('lib/Service/DocumentService.php')
const middleware = read('lib/Middleware/BestatterAccessMiddleware.php')

assert(!documents.includes('ILockingProvider'), 'Fehlerhafte manuelle Ordnersperre ist noch eingebunden')
assert(!documents.includes('$targetFolder->lock('), 'Zielordner wird weiterhin vor newFile gesperrt')
assert(documents.includes('$targetFolder->newFile($docxName)'), 'DOCX wird nicht über die Nextcloud-Datei-API angelegt')
for (const marker of ['BESTATTER_OPERATION_ERROR', 'Http::STATUS_INTERNAL_SERVER_ERROR', '$exception instanceof \\RuntimeException']) assert(middleware.includes(marker), `Datenschutzsichere Betriebsfehlerbehandlung fehlt: ${marker}`)
assert(!middleware.includes("'exception' => $exception"), 'Laufzeitausnahme würde einschließlich Falldaten-Argumenten protokolliert')
assert(/<version>0\.(?:34\.[4-9]|3[5-9]\.\d+|[4-9]\d\.\d+)<\/version>/.test(read('appinfo/info.xml')), 'Releaseversion ab 0.34.4 fehlt')

console.log('0.34.4 Nextcloud-managed document file locking passed')
