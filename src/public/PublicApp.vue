<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { generateUrl } from '@nextcloud/router'

/**
 * Public portal. Talks ONLY to the separate public API (/api/public/v1),
 * which serves stored, allow-listed publication content.
 */
const apiBase = generateUrl('/apps/openagenda/api/public/v1')
const meetings = ref<any[] | null>(null)
const meeting = ref<any>(null)
const loading = ref(false)
const error = ref('')

const dateTime = new Intl.DateTimeFormat('da-DK', { dateStyle: 'full', timeStyle: 'short', timeZone: 'Europe/Copenhagen' })
const fmt = (ts?: number | null) => (ts ? dateTime.format(new Date(ts * 1000)) : '')

async function fetchJson(path: string) {
	const response = await fetch(apiBase + path, { headers: { Accept: 'application/json' }, credentials: 'omit' })
	if (!response.ok) {
		throw new Error(response.status === 404 ? 'Mødet er ikke offentliggjort.' : 'Kunne ikke hente data.')
	}
	return response.json()
}

async function route() {
	error.value = ''
	loading.value = true
	const match = window.location.hash.match(/^#\/meetings\/([0-9a-f-]{36})$/)
	try {
		if (match) {
			meeting.value = await fetchJson('/meetings/' + match[1])
		} else {
			meeting.value = null
			meetings.value = await fetchJson('/meetings')
		}
	} catch (e) {
		error.value = (e as Error).message
		meeting.value = null
	} finally {
		loading.value = false
	}
}

onMounted(() => {
	window.addEventListener('hashchange', route)
	route()
})
onBeforeUnmount(() => window.removeEventListener('hashchange', route))
</script>

<template>
	<main :class="$style.portal">
		<p :class="$style.demo">
			Demo – alle data er fiktive.
		</p>
		<p v-if="loading">
			Henter …
		</p>
		<p v-if="error" :class="$style.error">
			{{ error }} <a href="#/">Tilbage til oversigten</a>
		</p>

		<template v-if="!meeting && meetings && !loading">
			<h1>Dagsordener og beslutninger</h1>
			<p v-if="!meetings.length">
				Der er ingen offentliggjorte møder.
			</p>
			<ul :class="$style.list">
				<li v-for="m in meetings" :key="m.meeting.uuid">
					<a :href="'#/meetings/' + m.meeting.uuid">
						<strong>{{ m.committee.name }}</strong> – {{ m.meeting.title }}
					</a>
					<br>{{ fmt(m.meeting.startsAt) }} · {{ m.hasDecisions ? 'Dagsorden og beslutninger' : 'Dagsorden' }} · {{ m.itemCount }} punkter
				</li>
			</ul>
		</template>

		<article v-if="meeting && !loading">
			<a href="#/">← Alle møder</a>
			<p :class="$style.muted">
				{{ meeting.municipality }}
			</p>
			<h1>{{ meeting.committee.name }} – {{ meeting.meeting.title }}</h1>
			<p>
				{{ fmt(meeting.meeting.startsAt) }}<template v-if="meeting.meeting.location">
					· {{ meeting.meeting.location }}
				</template>
			</p>
			<p :class="$style.muted">
				{{ meeting.hasDecisions ? 'Beslutningsprotokol' : 'Dagsorden' }} · dagsordensversion {{ meeting.agendaVersion }}<template v-if="meeting.protocolVersion">
					· protokolversion {{ meeting.protocolVersion }}
				</template>
				· offentliggjort {{ fmt(meeting.publishedAt) }}
			</p>
			<section v-for="item in meeting.items" :key="item.number" :class="$style.item">
				<h2>{{ item.number }}. {{ item.title }}</h2>
				<p v-if="item.closed" :class="$style.muted">
					Punktet er behandlet for lukkede døre.
				</p>
				<template v-else>
					<p v-if="item.caseNumber" :class="$style.muted">
						Sagsnr. {{ item.caseNumber }}
					</p>
					<template v-if="item.summary">
						<h3>Resumé</h3>
						<p :class="$style.pre">
							{{ item.summary }}
						</p>
					</template>
					<template v-if="item.presentation">
						<h3>Sagsfremstilling</h3>
						<p :class="$style.pre">
							{{ item.presentation }}
						</p>
					</template>
					<template v-if="item.legalBasis">
						<h3>Lovgrundlag</h3>
						<p :class="$style.pre">
							{{ item.legalBasis }}
						</p>
					</template>
					<template v-if="item.financialImpact">
						<h3>Økonomiske konsekvenser</h3>
						<p :class="$style.pre">
							{{ item.financialImpact }}
						</p>
					</template>
					<template v-if="item.recommendation">
						<h3>Indstilling</h3>
						<p :class="$style.pre">
							{{ item.recommendation }}
						</p>
					</template>
					<div v-for="(p, i) in item.previousTreatments" :key="i" :class="$style.previous">
						<h3>Tidligere behandling: {{ p.committeeName }}, {{ fmt(p.meetingStartsAt) }}</h3>
						<p><strong>{{ p.decisionLabel }}</strong><span v-if="p.decisionText"> – {{ p.decisionText }}</span></p>
					</div>
					<div v-if="item.decision" :class="$style.decision">
						<h3>Beslutning</h3>
						<p><strong>{{ item.decision.label }}</strong></p>
						<p v-if="item.decision.text" :class="$style.pre">
							{{ item.decision.text }}
						</p>
					</div>
					<template v-if="item.attachments.length">
						<h3>Offentlige bilag</h3>
						<ul>
							<li v-for="(a, i) in item.attachments" :key="i">
								{{ a.title }} (version {{ a.versionNumber }})
							</li>
						</ul>
						<p :class="$style.muted">
							Download af bilag er ikke tilgængelig i demoen.
						</p>
					</template>
				</template>
			</section>
		</article>
	</main>
</template>

<style module>
.portal {
	max-width: 860px;
	margin: 24px auto;
	padding: 0 16px 48px;
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
}

.portal h1 {
	font-size: 1.8em;
	font-weight: bold;
	margin: 12px 0;
}

.portal h2 {
	font-size: 1.3em;
	font-weight: bold;
	margin-top: 0;
}

.portal h3 {
	font-weight: bold;
	margin: 10px 0 2px;
}

.list li {
	margin: 12px 0;
}

.list a {
	text-decoration: underline;
}

.item {
	border-top: 1px solid var(--color-border);
	padding: 16px 0;
}

.decision {
	background: var(--color-success-hover, #eef5ee);
	border-left: 4px solid var(--color-success);
	padding: 6px 12px;
	margin-top: 8px;
}

.previous {
	background: var(--color-background-hover);
	padding: 6px 12px;
	margin-top: 8px;
}

.pre {
	white-space: pre-line;
}

.muted {
	color: var(--color-text-maxcontrast);
}

.demo {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 16px;
	background: var(--color-warning, #fff3cd);
}

.error {
	color: var(--color-error-text, var(--color-error));
}
</style>
