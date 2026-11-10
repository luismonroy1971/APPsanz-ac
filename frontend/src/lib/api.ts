import axios, { AxiosError } from 'axios'

const TOKEN_KEY = 'appsanzac.token'

export const tokenStore = {
  get: () => {
    try {
      return localStorage.getItem(TOKEN_KEY)
    } catch {
      return null
    }
  },
  set: (t: string | null) => {
    try {
      if (t) localStorage.setItem(TOKEN_KEY, t)
      else localStorage.removeItem(TOKEN_KEY)
    } catch {
      /* almacenamiento no disponible */
    }
  },
}

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? '/api/v1',
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = tokenStore.get()
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(
  (r) => r,
  (error: AxiosError) => {
    if (error.response?.status === 401 && !error.config?.url?.includes('auth/login')) {
      tokenStore.set(null)
      window.dispatchEvent(new Event('auth:expired'))
    }
    return Promise.reject(error)
  },
)

/** Mensaje legible a partir de un error de la API (incluye errores de validación de Laravel). */
export function errorMessage(err: unknown): string {
  const e = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>
  const data = e?.response?.data
  if (data?.errors) {
    const first = Object.values(data.errors).flat()
    if (first.length) return first.join(' ')
  }
  if (data instanceof Blob) return 'No se pudo descargar el archivo.'
  return data?.message || e?.message || 'Ocurrió un error inesperado.'
}

/** Descarga autenticada de archivos (PDF, Excel, TXT PLE...). */
export async function download(url: string, params?: Record<string, unknown>, fallbackName = 'archivo') {
  const res = await api.get(url, { params, responseType: 'blob' })
  const disposition = String(res.headers['content-disposition'] ?? '')
  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)
  const name = match ? decodeURIComponent(match[1]) : fallbackName
  const href = URL.createObjectURL(res.data as Blob)
  const a = document.createElement('a')
  a.href = href
  a.download = name
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(href), 1000)
}

/** Convierte un objeto a FormData (soporta archivos y arreglos). */
export function toFormData(data: Record<string, unknown>): FormData {
  const fd = new FormData()
  Object.entries(data).forEach(([k, v]) => {
    if (v === undefined || v === null || v === '') return
    if (Array.isArray(v)) v.forEach((item) => fd.append(`${k}[]`, item instanceof Blob ? item : String(item)))
    else if (v instanceof Blob) fd.append(k, v)
    else if (typeof v === 'boolean') fd.append(k, v ? '1' : '0')
    else fd.append(k, String(v))
  })
  return fd
}
