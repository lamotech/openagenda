import { createRouter, createWebHashHistory } from 'vue-router'

export default createRouter({
	history: createWebHashHistory(),
	routes: [
		{ path: '/', name: 'dashboard', component: () => import('./views/DashboardView.vue') },
		{ path: '/committees', name: 'committees', component: () => import('./views/CommitteesView.vue') },
		{ path: '/committees/:id', name: 'committee', component: () => import('./views/CommitteeView.vue'), props: true },
		{ path: '/meetings', name: 'meetings', component: () => import('./views/MeetingsView.vue') },
		{ path: '/meetings/new', name: 'meeting-new', component: () => import('./views/MeetingFormView.vue') },
		{ path: '/meetings/:id/edit', name: 'meeting-edit', component: () => import('./views/MeetingFormView.vue'), props: true },
		{ path: '/meetings/:id', name: 'meeting', component: () => import('./views/MeetingView.vue'), props: true },
		{ path: '/opencase', name: 'opencase', component: () => import('./views/OpenCaseSearchView.vue') },
		{ path: '/items/new', name: 'item-new', component: () => import('./views/ItemFormView.vue') },
		{ path: '/items/:id/edit', name: 'item-edit', component: () => import('./views/ItemFormView.vue'), props: true },
		{ path: '/items/:id', name: 'item', component: () => import('./views/ItemView.vue'), props: true },
		{ path: '/settings', name: 'settings', component: () => import('./views/SettingsView.vue') },
		{ path: '/:pathMatch(.*)*', redirect: '/' },
	],
})
