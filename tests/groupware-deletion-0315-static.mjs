import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const root = path.resolve(import.meta.dirname, '..')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')

const records = read('lib/Service/RecordService.php')
const groupware = read('lib/Service/GroupwareService.php')
const listener = read('lib/Listener/CalendarObjectChangedListener.php')

assert.match(listener, /CalendarObjectDeletedEvent/)
assert.match(groupware, /if \(\$deleted\)[\s\S]*markRemoteDeleted/)
assert.doesNotMatch(groupware, /markMissingFromCalendar\(/)
assert.match(records, /statusBeforeRemoteDeletion/)
assert.match(records, /\(string\)\$record\['status'\],[\s\S]*json_encode\(\$data/)
assert.doesNotMatch(records, /markRemoteDeleted[\s\S]{0,900}'ERLEDIGT'/)
assert.match(read('appinfo/info.xml'), /<version>0\.(?:31\.(?:[5-9]|[1-9][0-9])|(?:3[2-9]|[4-9]\d)\.\d+)<\/version>/)
assert.match(read('lib/AppInfo/Application.php'), /VERSION = '0\.(?:31\.(?:[5-9]|[1-9][0-9])|(?:3[2-9]|[4-9]\d)\.\d+)'/)

console.log('0.31.5 event-based remote deletion and status preservation checks passed.')
