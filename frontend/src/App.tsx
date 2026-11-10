import { lazy, Suspense, type ReactNode } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from '@/context/AuthContext'
import AppLayout from '@/components/layout/AppLayout'
import { Loading } from '@/components/ui'
import Login from '@/pages/Login'
import type { Role } from '@/lib/types'

const Dashboard = lazy(() => import('@/pages/Dashboard'))
const Documents = lazy(() => import('@/pages/documents/DocumentsPage'))
const Chat = lazy(() => import('@/pages/chat/ChatPage'))
const ClientReports = lazy(() => import('@/pages/client/ClientReportsPage'))
const Vouchers = lazy(() => import('@/pages/vouchers/VouchersPage'))
const Journal = lazy(() => import('@/pages/journal/JournalPage'))
const Books = lazy(() => import('@/pages/books/BooksPage'))
const FinancialReports = lazy(() => import('@/pages/financial/FinancialReportsPage'))
const Tasks = lazy(() => import('@/pages/tasks/TasksPage'))
const Timesheet = lazy(() => import('@/pages/time/TimesheetPage'))
const Reports = lazy(() => import('@/pages/reports/ReportsPage'))
const WorkingPapers = lazy(() => import('@/pages/bd3/WorkingPapersPage'))
const Communications = lazy(() => import('@/pages/bd3/CommunicationsPage'))
const Trainings = lazy(() => import('@/pages/bd3/TrainingsPage'))
const InternalDocs = lazy(() => import('@/pages/bd3/InternalDocsPage'))
const Clients = lazy(() => import('@/pages/admin/ClientsPage'))
const Users = lazy(() => import('@/pages/admin/UsersPage'))
const Profile = lazy(() => import('@/pages/Profile'))

function Protected({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth()
  if (loading) return <Loading label="Verificando sesión..." />
  if (!user) return <Navigate to="/login" replace />
  return <>{children}</>
}

function Internal({ children, roles }: { children: ReactNode; roles?: Role[] }) {
  const { isInternal, hasRole } = useAuth()
  if (!isInternal || (roles && !hasRole(...roles))) return <Navigate to="/" replace />
  return <>{children}</>
}

export default function App() {
  return (
    <Suspense fallback={<Loading />}>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route
          element={
            <Protected>
              <AppLayout />
            </Protected>
          }
        >
          <Route index element={<Dashboard />} />
          <Route path="documentos" element={<Documents />} />
          <Route path="consultas" element={<Chat />} />
          <Route path="mis-informes" element={<ClientReports />} />
          <Route path="reportes" element={<Reports />} />
          <Route path="perfil" element={<Profile />} />

          <Route path="comprobantes" element={<Internal><Vouchers /></Internal>} />
          <Route path="asientos" element={<Internal><Journal /></Internal>} />
          <Route path="libros" element={<Internal><Books /></Internal>} />
          <Route path="informes" element={<Internal><FinancialReports /></Internal>} />
          <Route path="procesos" element={<Internal><Tasks /></Internal>} />
          <Route path="horas" element={<Internal><Timesheet /></Internal>} />
          <Route path="papeles" element={<Internal><WorkingPapers /></Internal>} />
          <Route path="comunicaciones" element={<Internal><Communications /></Internal>} />
          <Route path="capacitacion" element={<Internal><Trainings /></Internal>} />
          <Route path="documentos-internos" element={<Internal><InternalDocs /></Internal>} />
          <Route path="clientes" element={<Internal roles={['socio', 'supervisor']}><Clients /></Internal>} />
          <Route path="usuarios" element={<Internal roles={['socio', 'supervisor']}><Users /></Internal>} />
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Suspense>
  )
}
