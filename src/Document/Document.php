<?php

declare(strict_types=1);

namespace Subash\PhpPdf\Document;

use Subash\PhpPdf\Parser\ObjectParser;
use Subash\PhpPdf\Parser\XrefParser;

/**
 * Represents a parsed PDF document.
 */
class Document
{
    private array  $pages      = [];
    private string $version    = '1.4';
    private array  $trailer    = [];
    private array  $xref       = [];
    private array  $objStmRefs = []; // Type 2: [objNum => [stmObjNum, indexInStm]]

    public function __construct(
        private string       $rawData,
        private XrefParser   $xrefParser,
        private ObjectParser $objectParser,
    ) {}

    /**
     * Parse the document — extract version, xref, trailer, and all pages.
     */
    public function parse(): void
    {
        // Extract PDF version
        if (preg_match('/%PDF-(\d+\.\d+)/', $this->rawData, $matches)) {
            $this->version = $matches[1];
        }

        // Parse xref chain (handles incremental updates and xref streams)
        $xrefOffset = $this->xrefParser->findXrefOffset();
        [$this->xref, $this->trailer, $this->objStmRefs] = $this->parseXrefChain($xrefOffset);

        // Give ObjectParser knowledge of ObjStm references
        $this->objectParser->setObjStmRefs($this->objStmRefs, $this->xref);

        // Find catalog
        $rootRef = $this->trailer['Root'] ?? null;
        if (!$rootRef) {
            throw new \RuntimeException('No Root entry in trailer');
        }

        $catalog  = $this->objectParser->parseObject($rootRef['obj']);
        $pagesRef = $catalog['dictionary']['Pages'] ?? null;

        if (!$pagesRef) {
            throw new \RuntimeException('Could not find Pages root in catalog');
        }

        // Collect all pages from page tree
        $pageTree    = new PageTree($this->objectParser, $this->xref);
        $this->pages = $pageTree->collect($pagesRef['obj']);
    }

    /**
     * Parse all xref tables in the /Prev chain and merge them.
     * Returns [merged xref, latest trailer, merged objStmRefs].
     */
    private function parseXrefChain(int $offset): array
    {
        $merged      = [];
        $objStmRefs  = [];
        $trailer     = [];
        $visited     = [];
        $isFirst     = true;

        while ($offset > 0 && !in_array($offset, $visited)) {
            $visited[] = $offset;

            $parser  = new XrefParser($this->rawData);
            $entries = $parser->parse($offset);
            $t       = $parser->parseTrailer();
            $refs    = $parser->getObjStmRefs();

            if ($isFirst) {
                $trailer = $t;
                $isFirst = false;
            }

            foreach ($entries as $objNum => $byteOffset) {
                if (!isset($merged[$objNum])) {
                    $merged[$objNum] = $byteOffset;
                }
            }

            foreach ($refs as $objNum => $ref) {
                if (!isset($objStmRefs[$objNum])) {
                    $objStmRefs[$objNum] = $ref;
                }
            }

            $prev   = $t['Prev'] ?? 0;
            $offset = is_array($prev) ? 0 : (int) $prev;
        }

        return [$merged, $trailer, $objStmRefs];
    }

    public function getPages(): array        { return $this->pages; }
    public function getPageCount(): int      { return count($this->pages); }
    public function getPage(int $n): ?Page   { return $this->pages[$n - 1] ?? null; }
    public function getVersion(): string     { return $this->version; }
    public function getXref(): array         { return $this->xref; }
    public function getObjStmRefs(): array   { return $this->objStmRefs; }
    public function getTrailer(): array      { return $this->trailer; }
    public function getRawData(): string     { return $this->rawData; }
    public function getObjectParser(): ObjectParser { return $this->objectParser; }
}
