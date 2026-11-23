<script setup lang="ts">
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import type { ApiError } from '../api'

defineProps<{ error: ApiError | null }>()
</script>

<template>
	<NcNoteCard v-if="error" type="error">
		<p>{{ error.message }}</p>
		<ul v-if="Object.keys(error.fields).length">
			<li v-for="(message, field) in error.fields" :key="field">
				{{ message }}
			</li>
		</ul>
	</NcNoteCard>
</template>
