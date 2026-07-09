<?php

declare(strict_types=1);

namespace Subash\PhpPdf\Parser;

use Subash\PhpPdf\Document\Document;
use Subash\PhpPdf\Exceptions\PdfParseException;

/**
 * Main entry point for parsing a PDF file into a Document.
 */
class PdfParser
{
    /**
     * Load and parse a PDF from a file path.
     */
    public function parseFile(string $path): Document
    {
        if (!file_exists($path)) {
            throw new PdfParseException("File not found: {$path}");
        }

        $data = file_get_contents($path);

        if ($data === false) {
            throw new PdfParseException("Could not read file: {$path}");
        }

        return $this->parseData($data);
    }

    /**
     * Parse a PDF from raw binary string data.
     */
    public function parseData(string $data): Document
    {
        if (!str_starts_with($data, '%PDF-')) {
            throw new PdfParseException('Not a valid PDF file — missing %PDF header');
        }

        $xrefParser   = new XrefParser($data);
        $objectParser = new ObjectParser($data, $xrefParser);
        $document     = new Document($data, $xrefParser, $objectParser);

        $document->parse();

        return $document;
    }
}
