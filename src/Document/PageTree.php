<?php

declare(strict_types=1);

namespace Subash\PhpPdf\Document;

use Subash\PhpPdf\Parser\ObjectParser;

/**
 * Traverses the PDF page tree to collect all pages in order.
 */
class PageTree
{
    private array $pages = [];

    public function __construct(
        private ObjectParser $objectParser,
        private array        $xref,
    ) {}

    /**
     * Walk the page tree starting from the root Pages object.
     * Returns an ordered array of Page objects.
     */
    public function collect(int $rootObjectNumber): array
    {
        $this->pages = [];
        $pageNumber  = 1;
        $this->walk($rootObjectNumber, $pageNumber);
        return $this->pages;
    }

    private function walk(int $objectNumber, int &$pageNumber): void
    {
        try {
            $object = $this->objectParser->parseObject($objectNumber);
        } catch (\Exception) {
            return;
        }
        $dict   = $object['dictionary'];

        $type = $dict['Type'] ?? '';

        // Intermediate node — recurse into kids
        if ($type === '/Pages') {
            $kids = $dict['Kids'] ?? [];
            foreach ($kids as $kid) {
                if (is_array($kid) && $kid['type'] === 'ref') {
                    $this->walk($kid['obj'], $pageNumber);
                }
            }
        }
        // Leaf node — actual page
        elseif ($type === '/Page') {
            $this->pages[] = new Page(
                number:       $pageNumber++,
                objectNumber: $objectNumber,
                dictionary:   $dict,
                resources:    $dict['Resources'] ?? [],
            );
        }
    }
}
