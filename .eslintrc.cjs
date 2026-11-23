module.exports = {
	// Vue 3 + TypeScript preset (the plain '@nextcloud' preset cannot parse <script setup lang="ts">)
	extends: [
		'@nextcloud/eslint-config/vue3',
	],
	rules: {
		'jsdoc/require-jsdoc': 'off',
		'vue/first-attribute-linebreak': 'off',
		// v0.1: API responses are not modelled as TypeScript types yet (see docs/IMPLEMENTATION_PLAN.md)
		'@typescript-eslint/no-explicit-any': 'off',
	},
}
