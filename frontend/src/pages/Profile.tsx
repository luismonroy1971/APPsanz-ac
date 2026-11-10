import { useState } from 'react'
import { KeyRound } from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import { useSend } from '@/hooks/useApi'
import { Badge, Button, Card, Field, Input, KeyValue, PageHeader } from '@/components/ui'
import { fmtDateTime, ROLE_LABEL } from '@/lib/utils'

export default function Profile() {
  const { user } = useAuth()
  const [v, setV] = useState({ current_password: '', password: '', password_confirmation: '' })
  const save = useSend('put', '/auth/password', { onSuccess: () => setV({ current_password: '', password: '', password_confirmation: '' }) })

  return (
    <>
      <PageHeader title="Mi cuenta" subtitle="Datos de acceso y seguridad." />
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Card title="Datos del usuario">
          <KeyValue
            items={[
              ['Nombre', user?.name],
              ['Correo (login)', user?.email],
              ['Tipo de acceso', user?.type === 'interno' ? 'Interno' : 'Externo'],
              ['Rol', <Badge tone="brand">{ROLE_LABEL[user?.role ?? '']}</Badge>],
              ['Empresa', user?.client?.business_name ?? 'SANZ & AC'],
              ['Último acceso', fmtDateTime(user?.last_login_at)],
            ]}
          />
        </Card>
        <Card title="Cambiar clave">
          <form
            className="space-y-4"
            onSubmit={(e) => {
              e.preventDefault()
              save.mutate(v)
            }}
          >
            <Field label="Clave actual"><Input type="password" autoComplete="current-password" value={v.current_password} onChange={(e) => setV({ ...v, current_password: e.target.value })} /></Field>
            <Field label="Nueva clave" hint="Mínimo 8 caracteres con letras y números"><Input type="password" autoComplete="new-password" value={v.password} onChange={(e) => setV({ ...v, password: e.target.value })} /></Field>
            <Field label="Confirmar nueva clave"><Input type="password" autoComplete="new-password" value={v.password_confirmation} onChange={(e) => setV({ ...v, password_confirmation: e.target.value })} /></Field>
            <Button type="submit" icon={<KeyRound className="h-4 w-4" />} loading={save.isPending}>Actualizar clave</Button>
          </form>
        </Card>
      </div>
    </>
  )
}
