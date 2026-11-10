import { useState } from 'react'
import { Pencil, Plus, UserX } from 'lucide-react'
import { useClients, useGet, useMeta, useSend } from '@/hooks/useApi'
import type { Paginated, User } from '@/lib/types'
import { Avatar, Badge, Button, Card, Empty, Field, Input, Loading, Modal, PageHeader, Pagination, Select, TableWrap, Tabs, Toolbar } from '@/components/ui'
import { ClientPicker } from '@/components/ClientPicker'
import { fromNow, ROLE_LABEL } from '@/lib/utils'

export default function UsersPage() {
  const { data: meta } = useMeta()
  const [type, setType] = useState<'interno' | 'externo'>('externo')
  const [f, setF] = useState({ search: '', client_id: '', page: 1 })
  const { data, isLoading } = useGet<Paginated<User>>('/users', { ...f, type })
  const [edit, setEdit] = useState<User | 'new' | null>(null)
  const deactivate = useSend<{ id: number }>('delete', (v) => `/users/${v.id}`, { invalidate: ['/users'] })

  return (
    <>
      <PageHeader title="Usuarios y accesos" subtitle="Acceso externo (usuarios del cliente según contrato) y acceso interno (Socio, Supervisor, Analistas)." actions={<Button icon={<Plus className="h-4 w-4" />} onClick={() => setEdit('new')}>Nuevo usuario</Button>} />
      <Tabs value={type} onChange={(v) => { setType(v); setF({ ...f, page: 1 }) }} tabs={[{ value: 'externo', label: 'Acceso externo (clientes)' }, { value: 'interno', label: 'Acceso interno (SANZ & AC)' }]} />
      <Card bodyClass="p-0">
        <Toolbar>
          <Input placeholder="Nombre o correo..." value={f.search} onChange={(e) => setF({ ...f, search: e.target.value, page: 1 })} className="w-64" />
          {type === 'externo' && <ClientPicker value={f.client_id} onChange={(v) => setF({ ...f, client_id: v, page: 1 })} />}
        </Toolbar>
        {isLoading ? <Loading /> : !data?.data.length ? <Empty title="Sin usuarios" /> : (
          <TableWrap>
            <table className="table-base">
              <thead><tr><th>Usuario</th><th>{type === 'externo' ? 'Cliente' : 'Rol'}</th><th>{type === 'externo' ? 'Permisos' : 'Clientes asignados'}</th><th>Último acceso</th><th>Estado</th><th /></tr></thead>
              <tbody className="divide-y divide-slate-100">
                {data.data.map((u) => (
                  <tr key={u.id}>
                    <td><div className="flex items-center gap-3"><Avatar name={u.name} /><div><p className="font-medium">{u.name}</p><p className="text-xs text-slate-500">{u.email} {u.position && `· ${u.position}`}</p></div></div></td>
                    <td>{type === 'externo' ? <span className="text-sm">{u.client?.business_name}</span> : <Badge tone="brand">{ROLE_LABEL[u.role ?? '']}</Badge>}</td>
                    <td className="max-w-[280px]">
                      {type === 'externo' ? (
                        <div className="flex flex-wrap gap-1">{(u.permissions ?? []).map((p) => <Badge key={p} tone="gold">{meta?.client_permissions[p]?.split(' (')[0] ?? p}</Badge>)}</div>
                      ) : (
                        <span className="text-xs text-slate-500">{u.role === 'analista' ? (u.assigned_clients ?? []).map((c) => c.business_name).join(', ') || '—' : 'Todos'}</span>
                      )}
                    </td>
                    <td className="text-xs">{fromNow(u.last_login_at)}</td>
                    <td>{u.is_active ? <Badge tone="green">Activo</Badge> : <Badge tone="slate">Inactivo</Badge>}</td>
                    <td className="whitespace-nowrap text-right">
                      <Button size="sm" variant="ghost" icon={<Pencil className="h-4 w-4" />} onClick={() => setEdit(u)} aria-label="Editar" />
                      {u.is_active && <Button size="sm" variant="ghost" icon={<UserX className="h-4 w-4 text-rose-500" />} onClick={() => deactivate.mutate({ id: u.id })} aria-label="Desactivar" />}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </TableWrap>
        )}
        <Pagination page={data} onPage={(p) => setF({ ...f, page: p })} />
      </Card>
      {edit && <UserModal user={edit === 'new' ? null : edit} defaultType={type} onClose={() => setEdit(null)} />}
    </>
  )
}

function UserModal({ user, defaultType, onClose }: { user: User | null; defaultType: 'interno' | 'externo'; onClose: () => void }) {
  const { data: meta } = useMeta()
  const { data: clients } = useClients()
  const [v, setV] = useState({
    name: user?.name ?? '', email: user?.email ?? '', type: user?.type ?? defaultType, role: user?.role && user.role !== 'cliente' ? user.role : 'analista',
    client_id: user?.client_id ? String(user.client_id) : '', position: user?.position ?? '', phone: user?.phone ?? '', hourly_cost: user?.hourly_cost ?? '',
    is_active: user?.is_active ?? true, password: '',
  })
  const [perms, setPerms] = useState<string[]>(user?.permissions ?? Object.keys(meta?.client_permissions ?? { 'documentos.subir': 1, 'consultas.usar': 1, 'informes.ver': 1 }))
  const opts = { invalidate: ['/users'], onSuccess: onClose }
  const create = useSend('post', '/users', opts)
  const update = useSend('put', `/users/${user?.id}`, opts)
  const set = (k: keyof typeof v) => (e: { target: { value: string } }) => setV({ ...v, [k]: e.target.value })
  const payload = { ...v, permissions: v.type === 'externo' ? perms : undefined }

  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      title={user ? 'Editar usuario' : 'Nuevo usuario'}
      footer={<><Button variant="outline" onClick={onClose}>Cancelar</Button><Button loading={create.isPending || update.isPending} onClick={() => (user ? update.mutate(payload) : create.mutate(payload))}>Guardar</Button></>}
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Tipo de acceso"><Select value={v.type} onChange={set('type')} disabled={!!user} options={{ externo: 'Externo (cliente)', interno: 'Interno (SANZ & AC)' }} /></Field>
        {v.type === 'interno'
          ? <Field label="Rol"><Select value={v.role} onChange={set('role')} options={{ socio: 'Socio', supervisor: 'Supervisor', analista: 'Analista' }} /></Field>
          : <Field label="Cliente"><Select value={v.client_id} onChange={set('client_id')} placeholder="Seleccione" options={(clients ?? []).map((c) => [String(c.id), c.business_name] as [string, string])} /></Field>}
        <Field label="Nombre completo"><Input value={v.name} onChange={set('name')} /></Field>
        <Field label="Correo (login)"><Input type="email" value={v.email} onChange={set('email')} /></Field>
        <Field label="Cargo"><Input value={v.position} onChange={set('position')} /></Field>
        <Field label="Teléfono"><Input value={v.phone} onChange={set('phone')} /></Field>
        {v.type === 'interno' && <Field label="Costo hora (S/)" hint="Para valorizar R2"><Input type="number" step="0.01" value={v.hourly_cost} onChange={set('hourly_cost')} /></Field>}
        <Field label={user ? 'Nueva clave (opcional)' : 'Clave'} hint="Mín. 8 caracteres con letras y números"><Input type="password" autoComplete="new-password" value={v.password} onChange={set('password')} /></Field>
        <Field label="Estado"><Select value={v.is_active ? '1' : '0'} onChange={(e) => setV({ ...v, is_active: e.target.value === '1' })} options={{ '1': 'Activo', '0': 'Inactivo' }} /></Field>
        {v.type === 'externo' && (
          <Field label="Permisos acordados (coordinación previa)" className="sm:col-span-2">
            <div className="space-y-2 rounded-lg border border-slate-200 p-3">
              {Object.entries(meta?.client_permissions ?? {}).map(([k, l]) => (
                <label key={k} className="flex items-center gap-2 text-sm">
                  <input type="checkbox" checked={perms.includes(k)} onChange={(e) => setPerms(e.target.checked ? [...perms, k] : perms.filter((p) => p !== k))} /> {l}
                </label>
              ))}
            </div>
          </Field>
        )}
      </div>
    </Modal>
  )
}
