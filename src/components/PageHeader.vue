<script setup lang="ts">
defineProps<{ title: string, subtitle?: string }>()
</script>

<template>
	<header :class="$style.header">
		<div>
			<h2 :class="$style.title">
				{{ title }}
			</h2>
			<p v-if="subtitle" :class="$style.subtitle">
				{{ subtitle }}
			</p>
		</div>
		<div :class="$style.actions">
			<slot />
		</div>
	</header>
</template>

<style module>
.header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 16px;
	flex-wrap: wrap;
	margin-bottom: 16px;
}

.title {
	margin: 0;
	font-size: 1.6em;
	font-weight: bold;
}

.subtitle {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 0;
}

.actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}
</style>
