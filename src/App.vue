<script setup lang="ts">
import { onMounted } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { mdiAccountGroup, mdiCalendarMonth, mdiCog, mdiEarth, mdiFolderSearch, mdiViewDashboard } from '@mdi/js'
import { publicPortalUrl } from './api'
import { can, loadMe, session } from './store'

onMounted(loadMe)
</script>

<template>
	<NcContent app-name="openagenda">
		<NcAppNavigation aria-label="OpenAgenda navigation">
			<template #list>
				<NcAppNavigationItem name="Overblik" :to="{ name: 'dashboard' }">
					<template #icon>
						<NcIconSvgWrapper :path="mdiViewDashboard" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem name="Møder" :to="{ name: 'meetings' }">
					<template #icon>
						<NcIconSvgWrapper :path="mdiCalendarMonth" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem name="Udvalg" :to="{ name: 'committees' }">
					<template #icon>
						<NcIconSvgWrapper :path="mdiAccountGroup" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem v-if="can('searchOpenCase')" name="Søg i OpenCase" :to="{ name: 'opencase' }">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFolderSearch" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem name="Offentlig visning" :href="publicPortalUrl()">
					<template #icon>
						<NcIconSvgWrapper :path="mdiEarth" />
					</template>
				</NcAppNavigationItem>
			</template>
			<template #footer>
				<ul class="app-navigation-entry__settings">
					<NcAppNavigationItem v-if="can('configure')" name="Indstillinger" :to="{ name: 'settings' }">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCog" />
						</template>
					</NcAppNavigationItem>
				</ul>
				<p v-if="session.me" :class="$style.footer">
					{{ session.me.municipalityName }}<br>
					Roller: {{ session.me.roles.map(r => r.label).join(', ') || 'ingen' }}<br>
					OpenCase: {{ session.me.openCaseSimulated ? 'simuleret (demo)' : session.me.openCaseAdapter }}
				</p>
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<div :class="$style.content">
				<p v-if="session.me?.openCaseSimulated !== false" :class="$style.demo">
					Demo – alle sager, personer og dokumenter er fiktive.
				</p>
				<NcLoadingIcon v-if="session.loading && !session.me" :size="44" />
				<NcEmptyContent v-else-if="session.error" name="Fejl" :description="session.error" />
				<NcEmptyContent v-else-if="session.me && !session.me.capabilities.useApp"
					name="Ingen adgang"
					description="Du har ingen rolle i OpenAgenda. Bed en administrator om at tilføje dig til en af OpenAgenda-grupperne." />
				<router-view v-else-if="session.me" :key="$route.fullPath" />
			</div>
		</NcAppContent>
	</NcContent>
</template>

<style module>
.content {
	padding: 16px 24px 48px 56px;
	max-width: 1100px;
}

.demo {
	display: inline-block;
	margin-bottom: 12px;
	padding: 2px 10px;
	border-radius: var(--border-radius-pill, 16px);
	background: var(--color-warning-hover, #fff3cd);
	color: var(--color-main-text);
	font-size: 0.9em;
}

.footer {
	padding: 8px 12px;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}
</style>
