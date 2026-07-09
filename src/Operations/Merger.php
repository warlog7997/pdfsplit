<?php

declare(strict_types=1);

namespace Subash\PhpPdf\Operations;

use Subash\PhpPdf\Document\Document;
use Subash\PhpPdf\Writer\PdfWriter;

/**
 * Merges multiple PDF documents into one.
 */
class Merger
{
    /** @var Document[] */
    private array $documents = [];

    /**
     * Add a document to be merged.
     */
    public function add(Document $document): self
    {
        $this->documents[] = $document;
        return $this;
    }

    /**
     * Merge all added documents and return the result as a PDF binary string.
     */
    public function merge(): string
    {
        $writer = new PdfWriter();

        foreach ($this->documents as $document) {
            foreach ($document->getPages() as $page) {
                $writer->addPage($page, $document->getRawData(), $document->getXref());
            }
        }

        return $writer->build();
    }

    /**
     * Merge and save to a file.
     */
    public function save(string $path): void
    {
        file_put_contents($path, $this->merge());
    }
}
