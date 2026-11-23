<script setup lang="ts">
defineProps<{
	label: string
	options: { value: string, label: string }[]
	placeholder?: string
	error?: string
	required?: boolean
}>()
const model = defineModel<string>({ default: '' })
const id = 'oa-select-' + Math.random().toString(36).slice(2)
</script>

<template>
	<div :class="$style.field">
		<label :for="id" :class="$style.label">{{ label }}<span v-if="required" aria-hidden="true"> *</span></label>
		<select :id="id"
			v-model="model"
			:class="$style.select"
			:required="required"
			:aria-invalid="!!error">
			<option v-if="placeholder" value="" disabled>
				{{ placeholder }}
			</option>
			<option v-for="option in options" :key="option.value" :value="option.value">
				{{ option.label }}
			</option>
		</select>
		<p v-if="error" :class="$style.error">
			{{ error }}
		</p>
	</div>
</template>

<style module>
.field {
	display: flex;
	flex-direction: column;
	margin: 8px 0;
}

.label {
	font-weight: 600;
	margin-bottom: 4px;
}

.select {
	width: 100%;
	max-width: 560px;
	min-height: var(--default-clickable-area, 34px);
}

.error {
	color: var(--color-error-text, var(--color-error));
	font-size: 0.9em;
}
</style>
