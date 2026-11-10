import { useState, type FormEvent } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import { Building2, CheckCircle2, Lock, Mail, ShieldCheck, Users } from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import { errorMessage } from '@/lib/api'
import { Button, Field, Input } from '@/components/ui'
import { Logo } from '@/components/layout/AppLayout'
import { cn } from '@/lib/utils'

export default function Login() {
  const { user, login } = useAuth()
  const navigate = useNavigate()
  const [portal, setPortal] = useState<'externo' | 'interno'>('externo')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  if (user) return <Navigate to="/" replace />

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    setLoading(true)
    try {
      await login(email, password, portal)
      navigate('/', { replace: true })
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="flex min-h-screen">
      {/* Panel institucional */}
      <div className="relative hidden w-1/2 flex-col justify-between overflow-hidden bg-gradient-to-br from-brand-800 via-brand-900 to-brand-950 p-12 text-white lg:flex">
        <div className="absolute -right-24 -top-24 h-96 w-96 rounded-full bg-gold-500/10 blur-3xl" />
        <div className="absolute -bottom-32 -left-16 h-96 w-96 rounded-full bg-brand-400/10 blur-3xl" />
        <Logo light />
        <div className="relative max-w-md">
          <h1 className="text-4xl font-semibold leading-tight">
            Su contabilidad, <span className="text-gold-400">integrada</span> y al día.
          </h1>
          <p className="mt-4 text-brand-100">
            Envíe sus comprobantes, resuelva consultas con su equipo contable y acceda a sus libros y estados financieros en un solo lugar.
          </p>
          <ul className="mt-8 space-y-3 text-sm text-brand-100">
            {[
              'Procesamiento automático de comprobantes electrónicos SUNAT (XML)',
              'Registro de compras, ventas y asientos contables automáticos',
              'Libros electrónicos PLE, EEFF y reportes de gestión',
              'Chat directo con su analista, supervisor y socio',
            ].map((t) => (
              <li key={t} className="flex items-start gap-2">
                <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-gold-400" /> {t}
              </li>
            ))}
          </ul>
        </div>
        <p className="relative text-xs text-brand-300">© {new Date().getFullYear()} SANZ &amp; AC · Asesores contables</p>
      </div>

      {/* Formulario */}
      <div className="flex w-full items-center justify-center bg-white px-4 py-10 lg:w-1/2">
        <div className="w-full max-w-sm">
          <div className="mb-8 lg:hidden">
            <Logo />
          </div>
          <h2 className="text-2xl font-semibold text-slate-900">Iniciar sesión</h2>
          <p className="mt-1 text-sm text-slate-500">Seleccione su tipo de acceso e ingrese sus credenciales.</p>

          <div className="mt-6 grid grid-cols-2 gap-2 rounded-xl bg-slate-100 p-1">
            {(
              [
                ['externo', 'Acceso externo', 'Clientes', <Building2 key="e" className="h-4 w-4" />],
                ['interno', 'Acceso interno', 'SANZ & AC', <Users key="i" className="h-4 w-4" />],
              ] as const
            ).map(([value, label, sub, icon]) => (
              <button
                key={value}
                type="button"
                onClick={() => setPortal(value)}
                className={cn(
                  'flex flex-col items-center rounded-lg px-3 py-2 text-sm transition',
                  portal === value ? 'bg-white font-semibold text-brand-700 shadow' : 'text-slate-500 hover:text-slate-700',
                )}
              >
                <span className="flex items-center gap-1.5">
                  {icon} {label}
                </span>
                <span className="text-[11px] font-normal text-slate-400">{sub}</span>
              </button>
            ))}
          </div>

          <form onSubmit={submit} className="mt-6 space-y-4">
            <Field label="Login (correo)">
              <div className="relative">
                <Mail className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                <Input type="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} className="pl-9" placeholder="usuario@empresa.pe" />
              </div>
            </Field>
            <Field label="Clave">
              <div className="relative">
                <Lock className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                <Input type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} className="pl-9" placeholder="••••••••" />
              </div>
            </Field>
            {error && <div className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</div>}
            <Button type="submit" className="w-full" loading={loading}>
              Ingresar
            </Button>
          </form>

          <div className="mt-8 flex items-start gap-2 rounded-lg bg-slate-50 p-3 text-xs text-slate-500">
            <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-brand-500" />
            <p>
              {portal === 'externo'
                ? 'Los usuarios de su empresa y sus permisos se definen en el contrato de servicios. Todos los accesos quedan registrados y se reportan semanalmente.'
                : 'Acceso exclusivo para Socios, Supervisores y Analistas autorizados. El supervisor realiza seguimiento semanal de accesos y horas por cliente.'}
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}
