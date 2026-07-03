import { v4wp } from '@kucrut/vite-for-wp';
import { wp_scripts } from '@kucrut/vite-for-wp/plugins';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

export default defineConfig({
	plugins: [
		v4wp({
			input: 'assets/admin.tsx',
			outDir: 'build',
		}),
		wp_scripts(),
		react({
			jsxRuntime: 'classic',
		}),
	],
});
