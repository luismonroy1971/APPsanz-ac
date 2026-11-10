import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { api, tokenStore } from '@/lib/api'
import type { Role, User } from '@/lib/types'

interface AuthState {
  user: User | null
  loading: boolean
  login: (email: string, password: string, portal: 'externo' | 'interno') => Promise<User>
  logout: () => Promise<void>
  refresh: () => Promise<void>
  isInternal: boolean
  hasRole: (...roles: Role[]) => boolean
  can: (permission: string) => boolean
}

const AuthContext = createContext<AuthState | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)
  const qc = useQueryClient()

  const refresh = useCallback(async () => {
    if (!tokenStore.get()) {
      setUser(null)
      setLoading(false)
      return
    }
    try {
      const { data } = await api.get('/auth/me')
      setUser(data.data ?? data)
    } catch {
      tokenStore.set(null)
      setUser(null)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void refresh()
    const onExpired = () => {
      setUser(null)
      qc.clear()
    }
    window.addEventListener('auth:expired', onExpired)
    return () => window.removeEventListener('auth:expired', onExpired)
  }, [refresh, qc])

  const login = useCallback(async (email: string, password: string, portal: 'externo' | 'interno') => {
    const { data } = await api.post('/auth/login', { email, password, portal })
    tokenStore.set(data.token)
    setUser(data.user)
    return data.user as User
  }, [])

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout')
    } catch {
      /* ignorar */
    }
    tokenStore.set(null)
    setUser(null)
    qc.clear()
  }, [qc])

  const value = useMemo<AuthState>(
    () => ({
      user,
      loading,
      login,
      logout,
      refresh,
      isInternal: user?.type === 'interno',
      hasRole: (...roles) => !!user?.role && roles.includes(user.role),
      can: (permission) => user?.type === 'interno' || !!user?.permissions?.includes(permission),
    }),
    [user, loading, login, logout, refresh],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth debe usarse dentro de AuthProvider')
  return ctx
}
