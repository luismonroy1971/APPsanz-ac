import { Component, type ErrorInfo, type ReactNode } from 'react'
import { AlertTriangle } from 'lucide-react'
import { Button } from './ui'

interface State {
  error: Error | null
}

/** Evita que un error de una pantalla deje toda la aplicación en blanco. */
export class ErrorBoundary extends Component<{ children: ReactNode }, State> {
  state: State = { error: null }

  static getDerivedStateFromError(error: Error): State {
    return { error }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error('Error de interfaz', error, info.componentStack)
  }

  render() {
    if (!this.state.error) return this.props.children
    return (
      <div className="mx-auto mt-16 max-w-md rounded-xl border border-rose-200 bg-white p-6 text-center shadow-card">
        <AlertTriangle className="mx-auto h-8 w-8 text-rose-500" />
        <h2 className="mt-3 text-base font-semibold text-slate-900">No se pudo mostrar esta sección</h2>
        <p className="mt-1 text-sm text-slate-500">Ocurrió un error inesperado. Puede reintentar o volver al inicio.</p>
        <div className="mt-4 flex justify-center gap-2">
          <Button variant="outline" onClick={() => this.setState({ error: null })}>Reintentar</Button>
          <Button onClick={() => (window.location.href = '/')}>Ir al inicio</Button>
        </div>
      </div>
    )
  }
}
