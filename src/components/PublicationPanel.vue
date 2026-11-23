<script setup lang="ts">
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ErrorNote from './ErrorNote.vue'
import StatusBadge from './StatusBadge.vue'
import { get, post, publicPortalUrl, toApiError, type ApiError } from '../api'
import { formatDateTime } from '../format'

const props = defineProps<{ meeting: any, permissions: any }>()
const publications = ref<any[]>([])
const busy = ref(false)
const error = ref<ApiError | null>(null)

async function load() {
	try {
		publications.value = await get('/meetings/' + props.meeting.id + '/publications')
	} catch (e) {
		error.value = toApiError(e)
	}
}

async function run(action: () => Promise<any>) {
	busy.value = true
	error.value = null
	try {
		await action()
		await load()
	} catch (e) {
		error.value = toApiError(e)
	} finally {
		busy.value = false
	}
}

const publish = () => run(() => post('/meetings/' + props.meeting.id + '/publications'))
const withdraw = (id: string) => run(() => post('/publications/' + id + '/withdraw'))
const publicUrl = publicPortalUrl() + '#/meetings/' + props.meeting.id

onMounted(load)
</script>

<template>
	<div>
		<NcNoteCard type="info">
			Offentliggørelsen bygges udelukkende af den seneste udsendte dagsordensversion og den seneste godkendte protokol.
			Lukkede punkter vises kun som "Lukket punkt", og kun bilag, som OpenCase markerer som offentlige, nævnes.
		</NcNoteCard>
		<div :class="$style.actions">
			<NcButton v-if="permissions.publishPublicly"
				variant="primary"
				:disabled="busy"
				@click="publish">
				{{ publications.some(p => p.status === 'published') ? 'Offentliggør ny version' : 'Offentliggør dagsorden og beslutninger' }}
			</NcButton>
			<NcButton :href="publicUrl" target="_blank">
				Se offentlig visning
			</NcButton>
		</div>
		<p v-if="!permissions.publishPublicly && !publications.length" :class="$style.muted">
			Dagsordenen skal udsendes, før mødet kan offentliggøres.
		</p>
		<ErrorNote :error="error" />
		<ul>
			<li v-for="p in publications" :key="p.id" :class="$style.row">
				<StatusBadge :status="p.status" :label="p.statusLabel" />
				{{ formatDateTime(p.publishedAt) }} – dagsorden v{{ p.agendaVersion }}<template v-if="p.protocolVersion">
					, protokol v{{ p.protocolVersion }} (med beslutninger)
				</template><template v-else>
					, uden beslutninger
				</template>
				<NcButton v-if="permissions.publishPublicly && p.status === 'published'"
					variant="tertiary"
					:disabled="busy"
					@click="withdraw(p.id)">
					Træk tilbage
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<style module>
.actions {
	display: flex;
	gap: 8px;
	margin: 8px 0;
}

.row {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 6px 0;
}

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
