<?php

declare(strict_types=1);

namespace Subash\PhpPdf\Operations;

use Subash\PhpPdf\Document\Document;
use Subash\PhpPdf\Writer\PdfWriter;
use Subash\PhpPdf\Exceptions\PdfParseException;

/**
 * Extracts specific pages from a PDF document.
 */
class Extractor
{
    public function __construct(private Document $document) {}

    /**
     * Extract specific page numbers into a new PDF string.
     * Page numbers are 1-based.
     */
    public function extract(array $pageNumbers): string
    {
        $writer = new PdfWriter();

        foreach ($pageNumbers as $number) {
            $page = $this->document->getPage($number);

            if ($page === null) {
                throw new PdfParseException("Page {$number} does not exist in document");
            }

            $writer->addPage($page, $this->document->getRawData(), $this->document->getXref());
        }

        return $writer->build();
    }

    /**
     * Extract a range of pages (e.g. pages 1 to 3).
     */
    public function extractRange(int $from, int $to): string
    {
        if ($from < 1 || $to < $from || $to > $this->document->getPageCount()) {
            throw new PdfParseException("Invalid page range {$from}-{$to}");
        }

        return $this->extract(range($from, $to));
    }
}
