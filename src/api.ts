import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Thin client for the internal OpenAgenda REST API (session + CSRF token via @nextcloud/axios).
 * The server is the only authority for permissions; the UI merely hides actions.
 * @param path API path below /api/v1
 */
const base = (path: string) => generateUrl('/apps/openagenda/api/v1' + path)

export interface ApiError {
	message: string
	fields: Record<string, string>
	status: number
}

export function toApiError(error: unknown): ApiError {
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	const response = (error as any)?.response
	return {
		message: response?.data?.error ?? 'Der opstod en netværksfejl. Prøv igen.',
		fields: response?.data?.fields ?? {},
		status: response?.status ?? 0,
	}
}

export async function get<T = any>(path: string, params?: Record<string, unknown>): Promise<T> {
	const { data } = await axios.get(base(path), { params })
	return data
}

export async function post<T = any>(path: string, body?: unknown): Promise<T> {
	const { data } = await axios.post(base(path), body ?? {})
	return data
}

export async function put<T = any>(path: string, body?: unknown): Promise<T> {
	const { data } = await axios.put(base(path), body ?? {})
	return data
}

export async function del<T = any>(path: string): Promise<T> {
	const { data } = await axios.delete(base(path))
	return data
}

export function protocolHtmlUrl(protocolId: string): string {
	return generateUrl('/apps/openagenda/protocols/{id}/html', { id: protocolId })
}

export function publicPortalUrl(): string {
	return generateUrl('/apps/openagenda/public')
}
