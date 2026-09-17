import fs from 'node:fs'
import path from 'node:path'
import { readApiControllers } from './helpers/controller-source.mjs'

const root = path.resolve(process.argv[2] || '.')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const service = read('lib/Service/AssistantService.php')
const controller = readApiControllers(root)
const routes = read('appinfo/routes.php')
const frontend = read('src/modules/assistant.js')
const main = read('src/main.js')
const system = read('lib/Service/StabilizationService.php')
const fixture = read('tests/assistant-0322-text-fixture.php')

for (const marker of ['AudioToText::ID', 'getAvailableTaskTypeIds()', 'scheduleTask($task)', 'STATUS_SUCCESSFUL', 'DELETE_AFTER_TRANSCRIPTION', 'requiresConfirmation', 'confirmationToken(', 'verifyConfirmationToken(', "'CREATE_TASK_DRAFT'", "'CREATE_SCHEDULE_DRAFT'"]) {
	if (!service.includes(marker)) throw new Error(`AssistantService marker missing: ${marker}`)
}
for (const mime of ["'audio/mp4'", "'audio/m4a'", "'audio/x-m4a'", "'audio/aac'", "'audio/x-aac'"]) {
	if (!service.includes(mime)) throw new Error(`AssistantService audio MIME type missing: ${mime}`)
}
if (!service.includes('Unterstützt werden WebM, OGG, WAV, MP3, M4A und AAC.')) throw new Error('Supported audio formats are missing from the validation error')
for (const marker of ['assistantConfiguration', 'analyzeAssistantText', 'scheduleTranscription', 'transcriptionStatus', 'previewAssistantIntent', 'executeAssistantIntent']) {
	if (!controller.includes(`function ${marker}`)) throw new Error(`Controller endpoint missing: ${marker}`)
}
for (const url of ['/api/assistant/configuration', '/api/assistant/analyze', '/api/assistant/transcriptions', '/api/assistant/intents/preview', '/api/assistant/intents/execute']) {
	if (!routes.includes(url)) throw new Error(`Route missing: ${url}`)
}
for (const marker of ['Sterbefall schnell erfassen', 'capture-record', 'capture-analyze', 'capture-create-case', 'assistant-execute', 'localStorage', 'MediaRecorder', 'confirmationToken']) {
	if (!frontend.includes(marker)) throw new Error(`Frontend marker missing: ${marker}`)
}
if (!main.includes("['capture', 'Schnellerfassung']") || !main.includes('createAssistantModule')) throw new Error('Assistant module is not wired into main.js')
if (!system.includes("'assistant', 'Schnellerfassung und Assistenz'")) throw new Error('System check does not include assistant status')
for (const marker of ['suggestPersonalDetails', 'suggestResidence', 'suggestCemetery', "'birth_name'", "'cemetery_contact'"]) {
	if (!service.includes(marker)) throw new Error(`Extended capture recognition marker missing: ${marker}`)
}
for (const marker of ['capture-speech-progress', 'setTranscriptionProgress', 'Transkription abgeschlossen']) {
	if (!frontend.includes(marker)) throw new Error(`Transcription progress marker missing: ${marker}`)
}
for (const marker of ["'first_name' => 'Erika'", "'date_of_birth' => '1942-04-15'", "'spouse_first_name' => 'Georg'", "'spouse_last_name' => 'Beispiel'", "'cemetery_contact' => 'Friedhof Beispiel'"]) {
	if (!fixture.includes(marker)) throw new Error(`0.32.2 text fixture marker missing: ${marker}`)
}
console.log('0.32.0 guided capture, speech adapter and confirmed intent contracts passed')
