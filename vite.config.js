import { defineConfig } from 'vite'
import { resolve } from 'node:path'

export default defineConfig(({ mode }) => ({
	build: {
		emptyOutDir: false,
		lib: {
			entry: resolve(import.meta.dirname, 'src/main.js'),
			name: 'BestatterApp',
			formats: ['iife'],
			fileName: () => 'main.js',
		},
		minify: mode === 'production' ? 'esbuild' : false,
		outDir: resolve(import.meta.dirname, 'js'),
		target: 'es2022',
		sourcemap: mode !== 'production',
	},
}))
