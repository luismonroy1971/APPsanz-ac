<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\Documents\DocumentProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $documentId) {}

    public function handle(DocumentProcessor $processor): void
    {
        $document = Document::find($this->documentId);
        if ($document && in_array($document->status, ['ingresado', 'reingresado'], true)) {
            $processor->process($document);
        }
    }
}
