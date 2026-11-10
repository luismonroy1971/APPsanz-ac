import { useState, type ReactNode } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import {
  BarChart3, BookOpen, Briefcase, Building2, ClipboardList, Clock, FileCheck2, FileStack, FolderLock, GraduationCap,
  LayoutDashboard, LogOut, Megaphone, Menu, MessagesSquare, NotebookPen, Receipt, ScrollText, UserCircle, Users, X,
} from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import { Avatar, Badge } from '@/components/ui'
import { cn, ROLE_LABEL } from '@/lib/utils'
import { useGet } from '@/hooks/useApi'
import { ErrorBoundary } from '@/components/ErrorBoundary'

interface NavItem {
  to: string
  label: string
  icon: ReactNode
  tag?: string
  show?: boolean
}

export function Logo({ light = false, compact = false }: { light?: boolean; compact?: boolean }) {
  return (
    <div className="flex items-center gap-2.5">
      <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-gold-500 text-sm font-black text-brand-950 shadow">S&amp;</div>
      {!compact && (
        <div className="leading-tight">
          <p className={cn('text-[15px] font-bold tracking-tight', light ? 'text-white' : 'text-brand-800')}>
            APPsanz<span className="text-gold-500">&amp;</span>ac
          </p>
          <p className={cn('text-[10px] uppercase tracking-[0.18em]', light ? 'text-brand-200' : 'text-slate-400')}>SANZ &amp; AC</p>
        </div>
      )}
    </div>
  )
}

export default function AppLayout() {
  const { user, logout, hasRole, can, isInternal } = useAuth()
  const [open, setOpen] = useState(false)
  const location = useLocation()
  const { data: dash } = useGet<{ data: { kpis?: { unanswered_chats: number; pending_documents: number } } }>(isInternal ? '/dashboard' : null, undefined, { refetchInterval: 60_000 })
  const kpis = dash?.data?.kpis

  const sections: { title?: string; items: NavItem[] }[] = isInternal
    ? [
        { items: [{ to: '/', label: 'Panel de control', icon: <LayoutDashboard className="h-4 w-4" /> }] },
        {
          title: 'Operación · S1 / S2',
          items: [
            { to: '/documentos', label: 'Bandeja de documentos', icon: <FileStack className="h-4 w-4" />, tag: kpis?.pending_documents ? String(kpis.pending_documents) : undefined },
            { to: '/consultas', label: 'Consultas de clientes', icon: <MessagesSquare className="h-4 w-4" />, tag: kpis?.unanswered_chats ? String(kpis.unanswered_chats) : undefined },
            { to: '/comprobantes', label: 'Comprobantes', icon: <Receipt className="h-4 w-4" /> },
            { to: '/asientos', label: 'Asientos contables', icon: <NotebookPen className="h-4 w-4" /> },
          ],
        },
        {
          title: 'Proceso · S3',
          items: [
            { to: '/procesos', label: 'Procesos y tareas', icon: <ClipboardList className="h-4 w-4" /> },
            { to: '/libros', label: 'Libros y EEFF', icon: <BookOpen className="h-4 w-4" /> },
            { to: '/informes', label: 'Informes R5 – R8', icon: <FileCheck2 className="h-4 w-4" /> },
            { to: '/horas', label: 'Horas hombre', icon: <Clock className="h-4 w-4" /> },
          ],
        },
        {
          title: 'Gestión · BD.3',
          items: [
            { to: '/reportes', label: 'Reportes R1 – R12', icon: <BarChart3 className="h-4 w-4" /> },
            { to: '/papeles', label: 'Papeles de trabajo', icon: <ScrollText className="h-4 w-4" /> },
            { to: '/comunicaciones', label: 'Comunicaciones internas', icon: <Megaphone className="h-4 w-4" /> },
            { to: '/capacitacion', label: 'Capacitación', icon: <GraduationCap className="h-4 w-4" /> },
            { to: '/documentos-internos', label: 'Documentos internos', icon: <FolderLock className="h-4 w-4" /> },
          ],
        },
        {
          title: 'Administración',
          items: [
            { to: '/clientes', label: 'Clientes', icon: <Building2 className="h-4 w-4" />, show: hasRole('socio', 'supervisor') },
            { to: '/usuarios', label: 'Usuarios y accesos', icon: <Users className="h-4 w-4" />, show: hasRole('socio', 'supervisor') },
          ],
        },
      ]
    : [
        {
          items: [
            { to: '/', label: 'Inicio', icon: <LayoutDashboard className="h-4 w-4" /> },
            { to: '/documentos', label: 'Mis documentos', icon: <FileStack className="h-4 w-4" />, show: can('documentos.subir') },
            { to: '/consultas', label: 'Consultas', icon: <MessagesSquare className="h-4 w-4" />, show: can('consultas.usar') },
            { to: '/mis-informes', label: 'Libros e informes', icon: <FileCheck2 className="h-4 w-4" />, show: can('informes.ver') },
            { to: '/reportes', label: 'Reportes', icon: <BarChart3 className="h-4 w-4" /> },
          ],
        },
      ]

  const nav = (
    <nav className="flex-1 space-y-5 overflow-y-auto px-3 py-4">
      {sections.map((s, i) => {
        const items = s.items.filter((it) => it.show !== false)
        if (!items.length) return null
        return (
          <div key={i}>
            {s.title && <p className="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-300/80">{s.title}</p>}
            <ul className="space-y-0.5">
              {items.map((it) => (
                <li key={it.to}>
                  <NavLink
                    to={it.to}
                    end={it.to === '/'}
                    onClick={() => setOpen(false)}
                    className={({ isActive }) =>
                      cn(
                        'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                        isActive ? 'bg-white/10 text-white shadow-inner' : 'text-brand-100/80 hover:bg-white/5 hover:text-white',
                      )
                    }
                  >
                    {it.icon}
                    <span className="flex-1 truncate">{it.label}</span>
                    {it.tag && <span className="rounded-full bg-gold-500 px-1.5 text-[10px] font-bold text-brand-950">{it.tag}</span>}
                  </NavLink>
                </li>
              ))}
            </ul>
          </div>
        )
      })}
    </nav>
  )

  const sidebar = (
    <div className="flex h-full flex-col bg-gradient-to-b from-brand-800 to-brand-950">
      <div className="flex h-16 items-center justify-between px-5">
        <Logo light />
        <button className="text-brand-200 lg:hidden" onClick={() => setOpen(false)} aria-label="Cerrar menú">
          <X className="h-5 w-5" />
        </button>
      </div>
      <div className="mx-4 rounded-lg bg-white/5 px-3 py-2 text-xs text-brand-100">
        <p className="font-semibold text-white">{isInternal ? 'Acceso interno' : 'Acceso externo'}</p>
        <p className="truncate">{isInternal ? 'Analista · Supervisor · Socio' : user?.client?.business_name}</p>
      </div>
      {nav}
      <div className="border-t border-white/10 p-3">
        <NavLink to="/perfil" onClick={() => setOpen(false)} className="flex items-center gap-3 rounded-lg px-2 py-2 hover:bg-white/5">
          <Avatar name={user?.name} className="bg-gold-500 text-brand-950" />
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-medium text-white">{user?.name}</p>
            <p className="truncate text-xs text-brand-200">{ROLE_LABEL[user?.role ?? ''] ?? ''}</p>
          </div>
          <UserCircle className="h-4 w-4 text-brand-300" />
        </NavLink>
      </div>
    </div>
  )

  return (
    <div className="min-h-screen">
      <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 lg:block">{sidebar}</aside>
      {open && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div className="absolute inset-0 bg-slate-900/50" onClick={() => setOpen(false)} />
          <aside className="absolute inset-y-0 left-0 w-72">{sidebar}</aside>
        </div>
      )}

      <div className="lg:pl-64">
        <header className="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6">
          <button className="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 lg:hidden" onClick={() => setOpen(true)} aria-label="Abrir menú">
            <Menu className="h-5 w-5" />
          </button>
          <div className="lg:hidden">
            <Logo compact />
          </div>
          <div className="hidden min-w-0 items-center gap-2 text-sm text-slate-500 sm:flex">
            <Briefcase className="h-4 w-4" />
            <span className="truncate">{isInternal ? 'SANZ & AC · Portal del equipo' : `${user?.client?.business_name} · RUC ${user?.client?.ruc}`}</span>
          </div>
          <div className="ml-auto flex items-center gap-3">
            <Badge tone={isInternal ? 'brand' : 'gold'}>{ROLE_LABEL[user?.role ?? ''] ?? user?.type}</Badge>
            <button
              onClick={() => void logout()}
              className="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100"
              title="Cerrar sesión"
            >
              <LogOut className="h-4 w-4" />
              <span className="hidden sm:inline">Salir</span>
            </button>
          </div>
        </header>
        <main key={location.pathname} className="mx-auto max-w-[1400px] px-4 py-6 sm:px-6 lg:px-8">
          <ErrorBoundary>
            <Outlet />
          </ErrorBoundary>
        </main>
      </div>
    </div>
  )
}
