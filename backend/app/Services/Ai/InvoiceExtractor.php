<?php

namespace App\Services\Ai;

use Anthropic\Client;
use RuntimeException;

/**
 * Extrae los datos de un comprobante (PDF o imagen) usando la API de Claude
 * con salida estructurada (JSON Schema). Solo se usa cuando el documento no
 * viene en XML UBL y AI_EXTRACTION_ENABLED=true.
 */
class InvoiceExtractor
{
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public static function enabled(): bool
    {
        return (bool) config('accounting.ai.enabled') && filled(config('accounting.ai.api_key'));
    }

    public static function supports(?string $mime): bool
    {
        return $mime === 'application/pdf' || in_array($mime, self::IMAGE_TYPES, true);
    }

    public function extract(string $binary, string $mime): array
    {
        $client = new Client(apiKey: config('accounting.ai.api_key'));

        $data = base64_encode($binary);
        $fileBlock = $mime === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => $data]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mime, 'data' => $data]];

        $message = $client->messages->create(
            model: config('accounting.ai.model'),
            maxTokens: 16000,
            system: 'Eres un asistente contable peruano. Extraes con exactitud los datos de comprobantes de pago '
                .'(facturas, boletas, notas de crédito y débito) emitidos según normas SUNAT. '
                .'Si un dato no aparece en el documento, devuelve una cadena vacía o 0; nunca lo inventes.',
            messages: [[
                'role' => 'user',
                'content' => [
                    $fileBlock,
                    ['type' => 'text', 'text' => 'Extrae los datos de este comprobante. Fechas en formato YYYY-MM-DD. '
                        .'Tipo de documento según catálogo SUNAT 01: 01 factura, 03 boleta, 07 nota de crédito, 08 nota de débito. '
                        .'Montos numéricos sin separador de miles.'],
                ],
            ]],
            outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $this->schema()]],
        );

        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('El modelo no pudo procesar el documento; requiere registro manual.');
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $json = json_decode($block->text, true);
                if (is_array($json)) {
                    return $json;
                }
            }
        }

        throw new RuntimeException('No se obtuvo una respuesta válida de la extracción con IA.');
    }

    private function schema(): array
    {
        $str = ['type' => 'string'];
        $num = ['type' => 'number'];

        return [
            'type' => 'object',
            'properties' => [
                'document_type' => ['type' => 'string', 'enum' => ['01', '03', '07', '08']],
                'series' => $str,
                'number' => $str,
                'issue_date' => $str,
                'due_date' => $str,
                'currency' => ['type' => 'string', 'enum' => ['PEN', 'USD', 'EUR']],
                'issuer_ruc' => $str,
                'issuer_name' => $str,
                'customer_doc' => $str,
                'customer_name' => $str,
                'taxable_amount' => $num,
                'exempt_amount' => $num,
                'unaffected_amount' => $num,
                'igv' => $num,
                'isc' => $num,
                'other_charges' => $num,
                'total' => $num,
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'description' => $str,
                            'quantity' => $num,
                            'unit_price' => $num,
                            'subtotal' => $num,
                            'igv' => $num,
                            'total' => $num,
                        ],
                        'required' => ['description', 'quantity', 'unit_price', 'subtotal', 'igv', 'total'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => [
                'document_type', 'series', 'number', 'issue_date', 'due_date', 'currency', 'issuer_ruc', 'issuer_name',
                'customer_doc', 'customer_name', 'taxable_amount', 'exempt_amount', 'unaffected_amount', 'igv', 'isc',
                'other_charges', 'total', 'items',
            ],
            'additionalProperties' => false,
        ];
    }
}
