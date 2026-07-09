<?php

declare(strict_types=1);

namespace Subash\PhpPdf\Operations;

use Subash\PhpPdf\Document\Document;
use Subash\PhpPdf\Writer\PdfWriter;

/**
 * Splits a PDF document into individual single-page PDFs.
 */
class Splitter
{
    public function __construct(private Document $document) {}

    /**
     * Split into individual pages.
     * Returns an array of PDF binary strings, one per page.
     */
    public function split(): array
    {
        $results = [];

        $rawData      = $this->document->getRawData();
        $xref         = $this->document->getXref();
        $objStmRefs   = $this->document->getObjStmRefs();
        $objectParser = $this->document->getObjectParser();

        foreach ($this->document->getPages() as $page) {
            $writer = new PdfWriter();
            $writer->addPage($page, $rawData, $xref, $objStmRefs, $objectParser);
            $results[$page->getNumber()] = $writer->build();
        }

        return $results;
    }

    /**
     * Split and save each page as a separate file in a directory.
     * Files are named page_1.pdf, page_2.pdf, etc.
     */
    public function splitToDirectory(string $outputDir): array
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $paths  = [];
        $splits = $this->split();

        foreach ($splits as $pageNumber => $pdfData) {
            $path         = rtrim($outputDir, '/') . "/page_{$pageNumber}.pdf";
            file_put_contents($path, $pdfData);
            $paths[$pageNumber] = $path;
        }

        return $paths;
    }
}
