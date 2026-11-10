import { useDropzone } from 'react-dropzone'
import { FileText, UploadCloud, X } from 'lucide-react'
import { cn, fmtBytes } from '@/lib/utils'

export function FileDrop({ files, onChange, multiple = true, accept, hint }: { files: File[]; onChange: (f: File[]) => void; multiple?: boolean; accept?: Record<string, string[]>; hint?: string }) {
  const { getRootProps, getInputProps, isDragActive } = useDropzone({
    multiple,
    accept,
    maxSize: 20 * 1024 * 1024,
    onDrop: (accepted) => onChange(multiple ? [...files, ...accepted] : accepted.slice(0, 1)),
  })

  return (
    <div>
      <div
        {...getRootProps()}
        className={cn(
          'flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed px-4 py-8 text-center transition',
          isDragActive ? 'border-brand-500 bg-brand-50' : 'border-slate-300 bg-slate-50 hover:border-brand-400 hover:bg-brand-50/40',
        )}
      >
        <input {...getInputProps()} />
        <UploadCloud className="mb-2 h-8 w-8 text-brand-500" />
        <p className="text-sm font-medium text-slate-700">{isDragActive ? 'Suelte los archivos aquí' : 'Arrastre archivos o haga clic para seleccionar'}</p>
        <p className="mt-1 text-xs text-slate-500">{hint ?? 'XML, ZIP, PDF, imágenes, Excel · máx. 20 MB por archivo'}</p>
      </div>
      {files.length > 0 && (
        <ul className="mt-3 divide-y divide-slate-100 rounded-lg border border-slate-200">
          {files.map((f, i) => (
            <li key={`${f.name}-${i}`} className="flex items-center gap-3 px-3 py-2 text-sm">
              <FileText className="h-4 w-4 shrink-0 text-slate-400" />
              <span className="min-w-0 flex-1 truncate">{f.name}</span>
              <span className="text-xs text-slate-400">{fmtBytes(f.size)}</span>
              <button type="button" onClick={() => onChange(files.filter((_, j) => j !== i))} className="text-slate-400 hover:text-rose-600" aria-label="Quitar">
                <X className="h-4 w-4" />
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
