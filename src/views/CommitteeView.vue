<script setup lang="ts">
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ErrorNote from '../components/ErrorNote.vue'
import PageHeader from '../components/PageHeader.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { get, toApiError, type ApiError } from '../api'
import { formatDateTime } from '../format'
import { can } from '../store'

const props = defineProps<{ id: string }>()
const committee = ref<any>(null)
const meetings = ref<any[]>([])
const error = ref<ApiError | null>(null)

onMounted(async () => {
	try {
		[committee.value, meetings.value] = await Promise.all([
			get('/committees/' + props.id),
			get('/meetings', { committeeId: props.id }),
		])
	} catch (e) {
		error.value = toApiError(e)
	}
})
</script>

<template>
	<div>
		<ErrorNote :error="error" />
		<NcLoadingIcon v-if="!committee && !error" :size="44" />
		<template v-if="committee">
			<PageHeader :title="committee.name" :subtitle="committee.typeLabel + (committee.active ? '' : ' (inaktivt)')">
				<NcButton v-if="can('manageMeetings') && committee.active" variant="primary" :to="{ name: 'meeting-new', query: { committeeId: committee.id } }">
					Opret møde
				</NcButton>
			</PageHeader>
			<p v-if="committee.description">
				{{ committee.description }}
			</p>
			<h3>Møder</h3>
			<p v-if="!meetings.length">
				Udvalget har ingen møder.
			</p>
			<ul :class="$style.list">
				<li v-for="m in meetings" :key="m.id">
					<router-link :to="{ name: 'meeting', params: { id: m.id } }">
						{{ m.title }}
					</router-link>
					– {{ formatDateTime(m.startsAt) }}
					<StatusBadge :status="m.status" :label="m.statusLabel" />
				</li>
			</ul>
		</template>
	</div>
</template>

<style module>
.list li {
	margin: 8px 0;
}
</style>
