import QRCode from 'qrcode'

const output = process.argv[2]
if (!output) throw new Error('Ausgabepfad fehlt.')
await QRCode.toFile(output, 'BCD\n002\n1\nSCT\nBFSWDE33XXX\nMusterbestattungen Bremen GmbH\nDE02120300000000202051\nEUR1563.36\n\nRE-2026-000042\nFall 2026-0042', {
	errorCorrectionLevel: 'M',
	margin: 1,
	width: 420,
})
console.log(output)
