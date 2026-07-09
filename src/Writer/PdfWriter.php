<?php

declare(strict_types=1);

namespace Subash\PhpPdf\Writer;

use Subash\PhpPdf\Document\Page;
use Subash\PhpPdf\Filter\FlateDecode;

/**
 * Builds a new PDF document from a set of pages extracted from source documents.
 * Uses deep copy strategy — each page is self-contained with its own resources.
 */
class PdfWriter
{
    private array  $objects      = [];  // [objectNumber => raw object string]
    private int    $nextObjNum   = 1;
    private array  $pageObjNums  = [];  // object numbers of page objects in output

    /**
     * Add a page from a source document to this writer.
     * Copies the page and all its referenced resources with new object numbers.
     */
    public function addPage(
        Page   $page,
        string $sourceData,
        array  $sourceXref,
        array  $objStmRefs = [],
        \Subash\PhpPdf\Parser\ObjectParser $objectParser = null
    ): void {
        if ($objectParser === null) {
            $objectParser = new \Subash\PhpPdf\Parser\ObjectParser(
                $sourceData,
                new \Subash\PhpPdf\Parser\XrefParser($sourceData)
            );
            $objectParser->setObjStmRefs($objStmRefs, $sourceXref);
        }

        $objMap = [];
        $this->copyObjectTree($page->getObjectNumber(), $sourceXref, $objStmRefs, $objectParser, $objMap);
        $this->pageObjNums[] = $objMap[$page->getObjectNumber()];
    }

    /**
     * Recursively copy an object and all objects it references.
     * Handles both Type 1 (regular) and Type 2 (ObjStm-compressed) objects.
     */
    private function copyObjectTree(
        int    $srcObjNum,
        array  $sourceXref,
        array  $objStmRefs,
        \Subash\PhpPdf\Parser\ObjectParser $objectParser,
        array  &$objMap
    ): int {
        if (isset($objMap[$srcObjNum])) {
            return $objMap[$srcObjNum];
        }

        $isType1 = isset($sourceXref[$srcObjNum]);
        $isType2 = isset($objStmRefs[$srcObjNum]);

        if (!$isType1 && !$isType2) {
            return 0;
        }

        $newObjNum          = $this->nextObjNum++;
        $objMap[$srcObjNum] = $newObjNum;

        if ($isType1) {
            $rawObj = $objectParser->getRawAt($sourceXref[$srcObjNum]);
        } else {
            // ObjStm object: extract body text and wrap as a regular object
            $body   = $objectParser->getRawFromObjStm($srcObjNum);
            $rawObj = "{$srcObjNum} 0 obj\n{$body}\nendobj";
        }

        // Recursively copy all referenced objects
        preg_match_all('/(\d+)\s+(\d+)\s+R/', $rawObj, $refMatches, PREG_SET_ORDER);
        foreach ($refMatches as $ref) {
            $refObjNum = (int) $ref[1];
            if (!isset($objMap[$refObjNum])) {
                $this->copyObjectTree($refObjNum, $sourceXref, $objStmRefs, $objectParser, $objMap);
            }
        }

        // Rewrite with new object number and updated references
        $rewritten = preg_replace('/^\d+\s+\d+\s+obj/', "{$newObjNum} 0 obj", $rawObj, 1);
        $rewritten = preg_replace_callback(
            '/(\d+)\s+(\d+)\s+R/',
            function ($m) use ($objMap) {
                $newRef = $objMap[(int) $m[1]] ?? (int) $m[1];
                return "{$newRef} 0 R";
            },
            $rewritten
        );

        $this->objects[$newObjNum] = $rewritten;

        return $newObjNum;
    }

    /**
     * Build and return the complete PDF as a binary string.
     */
    public function build(): string
    {
        $output  = "%PDF-1.4\n";
        $offsets = [];

        // Write all copied objects
        foreach ($this->objects as $objNum => $rawObj) {
            // Skip /Type /Page and /Type /Pages objects — we'll rewrite them
            if (preg_match('/\/Type\s*\/Pages\b/', $rawObj)) {
                continue;
            }

            $offsets[$objNum] = strlen($output);
            $output          .= $rawObj . "\n";
        }

        // Build new Pages tree
        $pagesObjNum          = $this->nextObjNum++;
        $kidsRefs             = implode(' ', array_map(fn($n) => "{$n} 0 R", $this->pageObjNums));
        $pageCount            = count($this->pageObjNums);
        $offsets[$pagesObjNum] = strlen($output);

        $output .= "{$pagesObjNum} 0 obj\n<< /Type /Pages /Kids [{$kidsRefs}] /Count {$pageCount} >>\nendobj\n";

        // Fix /Parent reference in each page object and write
        foreach ($this->pageObjNums as $pageObjNum) {
            if (isset($this->objects[$pageObjNum])) {
                $pageRaw = $this->objects[$pageObjNum];

                // Update /Parent to point to our new pages object
                $pageRaw = preg_replace('/\/Parent\s+\d+\s+\d+\s+R/', "/Parent {$pagesObjNum} 0 R", $pageRaw);

                // Remove /Type /Pages refs that got mixed in
                $offsets[$pageObjNum] = strlen($output);
                $output              .= $pageRaw . "\n";
            }
        }

        // Build Catalog
        $catalogObjNum          = $this->nextObjNum++;
        $offsets[$catalogObjNum] = strlen($output);
        $output                 .= "{$catalogObjNum} 0 obj\n<< /Type /Catalog /Pages {$pagesObjNum} 0 R >>\nendobj\n";

        // Write xref table
        $xrefOffset = strlen($output);
        $totalObjs  = $this->nextObjNum;
        $output    .= "xref\n0 {$totalObjs}\n";
        $output    .= "0000000000 65535 f \n";

        for ($i = 1; $i < $totalObjs; $i++) {
            if (isset($offsets[$i])) {
                $output .= str_pad((string) $offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
            } else {
                $output .= "0000000000 65535 f \n";
            }
        }

        // Write trailer
        $output .= "trailer\n<< /Size {$totalObjs} /Root {$catalogObjNum} 0 R >>\n";
        $output .= "startxref\n{$xrefOffset}\n%%EOF\n";

        return $output;
    }

    /**
     * Save the built PDF to a file.
     */
    public function save(string $path): void
    {
        file_put_contents($path, $this->build());
    }
}
