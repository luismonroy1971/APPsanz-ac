<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\User;
use App\Services\Accounting\VoucherService;
use App\Services\Ai\InvoiceExtractor;
use App\Services\Sunat\UblInvoiceParser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * S1: procesa documentos recibidos del cliente.
 *  - XML UBL SUNAT  -> parseo automático
 *  - PDF / imagen   -> extracción con IA (si está habilitada) o registro manual
 *  - Otros          -> quedan "en proceso" para gestión del analista
 * Luego valida y registra el comprobante con su asiento contable.
 */
class DocumentProcessor
{
    public function __construct(
        private readonly UblInvoiceParser $parser,
        private readonly VoucherService $vouchers,
    ) {}

    public function process(Document $document, ?User $user = null): Document
    {
        if ($document->category !== 'comprobante') {
            // Documentos no transaccionales (bancarios, planillas, contratos...) los gestiona el analista.
            if ($document->status === 'ingresado' || $document->status === 'reingresado') {
                $document->transitionTo('en_proceso', $user, 'Documento recibido para revisión del analista.');
            }

            return $document;
        }

        if ($document->voucher()->exists()) {
            return $document;
        }

        $document->transitionTo('en_proceso', $user, 'Procesamiento automático iniciado.');

        try {
            [$data, $method] = $this->extract($document);
        } catch (Throwable $e) {
            Log::warning('Extracción fallida', ['document' => $document->id, 'error' => $e->getMessage()]);
            $document->update(['validation_errors' => [$e->getMessage()], 'processing_method' => null]);
            $document->transitionTo('observado', $user, 'No se pudo extraer la información: '.$e->getMessage());

            return $document;
        }

        if ($data === null) {
            $document->update(['processing_method' => 'manual']);
            $document->history()->create([
                'user_id' => $user?->id,
                'from_status' => 'en_proceso',
                'to_status' => 'en_proceso',
                'comment' => 'Requiere registro manual del analista (formato sin extracción automática).',
            ]);

            return $document;
        }

        return $this->register($document, $data, $method, $user);
    }

    /** Registra el comprobante a partir de datos (extraídos o corregidos por el analista). */
    public function register(Document $document, array $data, string $method, ?User $user = null): Document
    {
        $result = $this->vouchers->register($document->client, $data, $document->id, $user?->id);

        $document->update([
            'processing_method' => $method,
            'extracted_data' => $this->vouchers->normalize($data),
            'validation_errors' => array_merge($result['errors'], $result['warnings']) ?: null,
            'period' => isset($data['issue_date']) ? substr((string) $data['issue_date'], 0, 7) : $document->period,
        ]);

        if ($result['errors']) {
            $document->transitionTo('observado', $user, implode(' ', $result['errors']));
        } else {
            $document->update(['processed_at' => now()]);
            $comment = 'Comprobante '.$result['voucher']->full_number.' registrado con asiento automático.';
            if ($result['warnings']) {
                $comment .= ' Advertencias: '.implode(' ', $result['warnings']);
            }
            $document->transitionTo('procesado', $user, $comment);
        }

        return $document->fresh(['voucher', 'history.user']);
    }

    /** @return array{0: ?array, 1: ?string} */
    private function extract(Document $document): array
    {
        $disk = Storage::disk('local');
        $ext = strtolower((string) $document->extension);

        if ($ext === 'xml' || in_array($document->mime_type, ['text/xml', 'application/xml'], true)) {
            return [$this->parser->parse($disk->get($document->path)), 'xml_ubl'];
        }

        if ($ext === 'zip') {
            $xml = $this->xmlFromZip($disk->path($document->path));
            if ($xml) {
                return [$this->parser->parse($xml), 'xml_ubl'];
            }
        }

        if (InvoiceExtractor::enabled() && InvoiceExtractor::supports($document->mime_type)) {
            return [app(InvoiceExtractor::class)->extract($disk->get($document->path), $document->mime_type), 'ia'];
        }

        return [null, null];
    }

    /** Los CPE suelen enviarse como ZIP con el XML firmado dentro. */
    private function xmlFromZip(string $path): ?string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (str_ends_with(strtolower($name), '.xml')) {
                    return $zip->getFromIndex($i) ?: null;
                }
            }
        } finally {
            $zip->close();
        }

        return null;
    }
}
