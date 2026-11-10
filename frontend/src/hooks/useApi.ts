import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { api, errorMessage } from '@/lib/api'
import type { ClientLite, Meta, Staff } from '@/lib/types'

function clean(params?: Record<string, unknown>) {
  if (!params) return undefined
  return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v !== undefined && v !== null && v !== false))
}

/** GET genérico con caché por URL + parámetros. */
export function useGet<T>(url: string | null, params?: Record<string, unknown>, options?: { refetchInterval?: number; enabled?: boolean }) {
  const p = clean(params)
  return useQuery<T>({
    queryKey: [url, p],
    queryFn: async () => (await api.get(url!, { params: p })).data,
    enabled: !!url && (options?.enabled ?? true),
    placeholderData: keepPreviousData,
    refetchInterval: options?.refetchInterval,
  })
}

type Method = 'post' | 'put' | 'patch' | 'delete'

/** Mutación genérica con toasts y refresco de consultas relacionadas. */
export function useSend<TVars = unknown, TRes = { message?: string }>(
  method: Method,
  url: string | ((vars: TVars) => string),
  opts?: { invalidate?: string[]; silent?: boolean; onSuccess?: (data: TRes, vars: TVars) => void },
) {
  const qc = useQueryClient()
  return useMutation<TRes, unknown, TVars>({
    mutationFn: async (vars) => {
      const u = typeof url === 'function' ? url(vars) : url
      const payload = typeof vars === 'object' && vars !== null && 'id' in (vars as object) && typeof url === 'function'
        ? Object.fromEntries(Object.entries(vars as object).filter(([k]) => k !== 'id'))
        : vars
      const body = vars instanceof FormData ? vars : payload
      const res = method === 'delete' ? await api.delete(u) : await api[method](u, body)
      return res.data
    },
    onSuccess: (data, vars) => {
      const msg = (data as { message?: string })?.message
      if (msg && !opts?.silent) toast.success(msg)
      ;(opts?.invalidate ?? []).forEach((key) => qc.invalidateQueries({ predicate: (q) => String(q.queryKey[0] ?? '').startsWith(key) }))
      opts?.onSuccess?.(data, vars)
    },
    onError: (err) => {
      toast.error(errorMessage(err))
    },
  })
}

export function useMeta() {
  return useQuery<Meta>({
    queryKey: ['meta'],
    queryFn: async () => (await api.get('/meta')).data.data,
    staleTime: Infinity,
  })
}

export function useClients(enabled = true) {
  return useQuery<ClientLite[]>({
    queryKey: ['/clients', 'all'],
    queryFn: async () => (await api.get('/clients', { params: { all: 1 } })).data.data,
    staleTime: 5 * 60_000,
    enabled,
  })
}

export function useStaff() {
  return useQuery<Staff[]>({
    queryKey: ['/staff'],
    queryFn: async () => (await api.get('/staff')).data.data,
    staleTime: 5 * 60_000,
  })
}
