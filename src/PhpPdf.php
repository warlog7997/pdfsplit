<?php

declare(strict_types=1);

namespace Subash\PhpPdf;

use Subash\PhpPdf\Document\Document;
use Subash\PhpPdf\Operations\Extractor;
use Subash\PhpPdf\Operations\Splitter;
use Subash\PhpPdf\Operations\Merger;
use Subash\PhpPdf\Parser\PdfParser;

/**
 * Main entry point for subash/phppdf.
 *
 * Usage:
 *   // Extract first 3 pages
 *   PhpPdf::load('input.pdf')->extract([1, 2, 3])->save('output.pdf');
 *
 *   // Split into individual pages
 *   PhpPdf::load('input.pdf')->split()->saveToDirectory('output/');
 *
 *   // Merge multiple PDFs
 *   PhpPdf::merge(['file1.pdf', 'file2.pdf'])->save('merged.pdf');
 */
class PhpPdf
{
    private Document $document;

    private function __construct(Document $document)
    {
        $this->document = $document;
    }

    /**
     * Load a PDF from a file path.
     */
    public static function load(string $path): self
    {
        $parser = new PdfParser();
        return new self($parser->parseFile($path));
    }

    /**
     * Load a PDF from raw binary string data.
     */
    public static function loadData(string $data): self
    {
        $parser = new PdfParser();
        return new self($parser->parseData($data));
    }

    /**
     * Extract specific pages (1-based page numbers).
     * Returns PDF binary string.
     *
     * Example: ->extract([1, 2, 3])
     */
    public function extract(array $pageNumbers): string
    {
        return (new Extractor($this->document))->extract($pageNumbers);
    }

    /**
     * Extract a range of pages.
     * Returns PDF binary string.
     *
     * Example: ->extractRange(1, 3)
     */
    public function extractRange(int $from, int $to): string
    {
        return (new Extractor($this->document))->extractRange($from, $to);
    }

    /**
     * Save extracted/built PDF to a file.
     */
    public function save(string $path): void
    {
        file_put_contents($path, $this->extract(
            range(1, $this->document->getPageCount())
        ));
    }

    /**
     * Get the Splitter for this document.
     */
    public function splitter(): Splitter
    {
        return new Splitter($this->document);
    }

    /**
     * Get page count.
     */
    public function getPageCount(): int
    {
        return $this->document->getPageCount();
    }

    /**
     * Access the underlying Document for diagnostics.
     */
    public function getDocument(): Document
    {
        return $this->document;
    }

    /**
     * Start a merge operation with multiple PDF files.
     */
    public static function merger(): Merger
    {
        return new Merger();
    }
}
