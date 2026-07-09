<?php

declare(strict_types=1);

namespace Subash\PhpPdf\Parser;

use Subash\PhpPdf\Exceptions\PdfParseException;
use Subash\PhpPdf\Filter\FlateDecode;

/**
 * Parses individual PDF objects from raw PDF data.
 * Supports both regular objects and objects compressed in ObjStm streams.
 */
class ObjectParser
{
    private array $objStmRefs  = []; // [objNum => [stmObjNum, indexInStm]]
    private array $xref        = [];
    private array $objStmCache = []; // [stmObjNum => decompressed stream text]

    public function __construct(
        private string     $data,
        private XrefParser $xrefParser,
    ) {}

    /**
     * Provide ObjStm reference map so compressed objects can be resolved.
     */
    public function setObjStmRefs(array $objStmRefs, array $xref): void
    {
        $this->objStmRefs = $objStmRefs;
        $this->xref       = $xref;
    }

    /**
     * Parse an object by its object number.
     * Automatically handles both regular and ObjStm-compressed objects.
     */
    public function parseObject(int $objNum): array
    {
        // Check if it's a compressed object (Type 2 xref entry)
        if (isset($this->objStmRefs[$objNum])) {
            return $this->parseFromObjStm($objNum, ...$this->objStmRefs[$objNum]);
        }

        // Regular object — look up in xref
        if (!isset($this->xref[$objNum])) {
            throw new PdfParseException("Object {$objNum} not found in xref");
        }

        return $this->parseAt($this->xref[$objNum]);
    }

    /**
     * Extract a raw object by its byte offset.
     */
    public function parseAt(int $offset): array
    {
        $slice = substr($this->data, $offset, 65536);

        if (!preg_match('/^\d+\s+\d+\s+obj\s*/A', $slice, $header)) {
            // Try without strict anchor for some PDFs
            if (!preg_match('/\d+\s+\d+\s+obj\s*/', $slice, $header)) {
                throw new PdfParseException("Could not parse object at offset {$offset}");
            }
        }

        $bodyStart = strlen($header[0]);
        $body      = substr($slice, $bodyStart);

        return $this->parseObjectBody($body, $offset + $bodyStart);
    }

    /**
     * Get the raw object text at a byte offset (used for copying objects).
     * Handles large objects and binary streams safely using /Length.
     */
    public function getRawAt(int $offset): string
    {
        // Read from offset to end of file — no size limit
        $slice = substr($this->data, $offset);

        if (!preg_match('/^\d+\s+\d+\s+obj\s*/A', $slice, $hdrMatch)) {
            throw new PdfParseException("Could not get raw object at offset {$offset}");
        }

        $pos = strlen($hdrMatch[0]);

        // Check if this is a stream object and find 'stream' keyword position
        $streamPos = strpos($slice, 'stream', $pos);
        $endobjPos = strpos($slice, 'endobj', $pos);

        if ($streamPos !== false && ($endobjPos === false || $streamPos < $endobjPos)) {
            // Stream object: use /Length to safely skip past binary stream data
            $dictPart = substr($slice, $pos, $streamPos - $pos);

            if (preg_match('/\/Length\s+(\d+)/', $dictPart, $lenMatch)) {
                $length    = (int) $lenMatch[1];
                $dataStart = $streamPos + 6; // skip 'stream'
                if (isset($slice[$dataStart]) && $slice[$dataStart] === "\r") $dataStart++;
                if (isset($slice[$dataStart]) && $slice[$dataStart] === "\n") $dataStart++;

                // Find endstream/endobj safely after the binary data
                $searchFrom = $dataStart + $length;
                $endstreamPos = strpos($slice, 'endstream', $searchFrom);
                if ($endstreamPos !== false) {
                    $endobjPos = strpos($slice, 'endobj', $endstreamPos);
                    if ($endobjPos !== false) {
                        return substr($slice, 0, $endobjPos + 6);
                    }
                }
            }

            // Fallback: find endstream then endobj
            $endstreamPos = strpos($slice, 'endstream', $streamPos);
            if ($endstreamPos !== false) {
                $endobjPos = strpos($slice, 'endobj', $endstreamPos);
                if ($endobjPos !== false) {
                    return substr($slice, 0, $endobjPos + 6);
                }
            }
        }

        // Non-stream object: find endobj directly
        if ($endobjPos !== false) {
            return substr($slice, 0, $endobjPos + 6);
        }

        throw new PdfParseException("Could not get raw object at offset {$offset}");
    }

    /**
     * Get the raw text of an ObjStm-compressed object (for copying into output PDF).
     * Returns the object body as a plain string, ready to wrap in "N 0 obj...endobj".
     */
    public function getRawFromObjStm(int $objNum): string
    {
        if (!isset($this->objStmRefs[$objNum])) {
            throw new PdfParseException("Object {$objNum} is not in any ObjStm");
        }

        [$stmObjNum, $indexInStm] = $this->objStmRefs[$objNum];
        $streamText = $this->getObjStmContent($stmObjNum);

        $stmObj = $this->parseAt($this->xref[$stmObjNum]);
        $first  = (int) ($stmObj['dictionary']['First'] ?? 0);

        $header = substr($streamText, 0, $first);
        preg_match_all('/(\d+)\s+(\d+)/', $header, $pairs, PREG_SET_ORDER);

        $offsets = [];
        foreach ($pairs as $pair) {
            $offsets[(int) $pair[1]] = (int) $pair[2];
        }

        if (!isset($offsets[$objNum])) {
            throw new PdfParseException("Object {$objNum} offset not found in ObjStm {$stmObjNum} header");
        }

        $start = $first + $offsets[$objNum];

        // Find the next object's start to determine this object's length
        $nextOffsets = array_filter($offsets, fn($o) => $o > $offsets[$objNum]);
        if (!empty($nextOffsets)) {
            $nextRelOffset = min($nextOffsets);
            $end = $first + $nextRelOffset;
        } else {
            $end = strlen($streamText);
        }

        return trim(substr($streamText, $start, $end - $start));
    }

    public function getObjStmRefs(): array
    {
        return $this->objStmRefs;
    }

    // -------------------------------------------------------------------------
    // ObjStm support
    // -------------------------------------------------------------------------

    /**
     * Extract an object from a compressed Object Stream (ObjStm).
     */
    private function parseFromObjStm(int $objNum, int $stmObjNum, int $indexInStm): array
    {
        $streamText = $this->getObjStmContent($stmObjNum);

        // The ObjStm header lists: "objNum offset objNum offset ..."
        // followed by the actual object bodies after /First bytes
        $stmObj = $this->parseAt($this->xref[$stmObjNum]);
        $first  = (int) ($stmObj['dictionary']['First'] ?? 0);
        $n      = (int) ($stmObj['dictionary']['N'] ?? 0);

        // Parse the index header (before /First)
        $header = substr($streamText, 0, $first);
        preg_match_all('/(\d+)\s+(\d+)/', $header, $pairs, PREG_SET_ORDER);

        $offsets = [];
        foreach ($pairs as $pair) {
            $offsets[(int) $pair[1]] = (int) $pair[2];
        }

        if (!isset($offsets[$objNum])) {
            throw new PdfParseException("Object {$objNum} not found in ObjStm {$stmObjNum}");
        }

        $objOffset  = $first + $offsets[$objNum];
        $objContent = substr($streamText, $objOffset);

        return $this->parseObjectBody($objContent, 0);
    }

    /**
     * Decompress and cache an ObjStm stream by its object number.
     */
    private function getObjStmContent(int $stmObjNum): string
    {
        if (isset($this->objStmCache[$stmObjNum])) {
            return $this->objStmCache[$stmObjNum];
        }

        if (!isset($this->xref[$stmObjNum])) {
            throw new PdfParseException("ObjStm object {$stmObjNum} not found in xref");
        }

        $offset = $this->xref[$stmObjNum];
        $slice  = substr($this->data, $offset, 131072);

        if (!preg_match('/\d+\s+\d+\s+obj\s*(<<.*?>>)\s*stream\r?\n/s', $slice, $matches, PREG_OFFSET_CAPTURE)) {
            throw new PdfParseException("Could not parse ObjStm at offset {$offset}");
        }

        $dict       = $this->xrefParser->parseDictionary($matches[1][0]);
        $length     = (int) ($dict['Length'] ?? 0);
        $streamStart = $offset + $matches[0][1] + strlen($matches[0][0]);
        $streamData  = substr($this->data, $streamStart, $length > 0 ? $length : 65536);

        // Trim trailing line ending before endstream
        $streamData = rtrim($streamData, "\r\n");

        $filter = $dict['Filter'] ?? '';
        if ($filter === '/FlateDecode' || $filter === 'FlateDecode') {
            $streamData  = FlateDecode::decompress($streamData);
            $decodeParms = $dict['DecodeParms'] ?? [];
            if (!empty($decodeParms) && is_array($decodeParms)) {
                $streamData = FlateDecode::applyPredictor($streamData, $decodeParms);
            }
        }

        $this->objStmCache[$stmObjNum] = $streamData;
        return $streamData;
    }

    // -------------------------------------------------------------------------
    // Object body parser
    // -------------------------------------------------------------------------

    /**
     * Parse the body of a PDF object (after "N G obj").
     * Returns ['dictionary' => [], 'stream' => string|null]
     */
    private function parseObjectBody(string $body, int $baseOffset): array
    {
        $body = ltrim($body);
        $dict   = [];
        $stream = null;

        if (str_starts_with($body, '<<')) {
            // Find matching >>
            $dictEnd = $this->findDictEnd($body, 0);
            $dictStr = substr($body, 0, $dictEnd + 2);
            $dict    = $this->xrefParser->parseDictionary($dictStr);

            $afterDict = ltrim(substr($body, $dictEnd + 2));

            // Check for stream
            if (str_starts_with($afterDict, 'stream')) {
                $streamStart = 6; // skip "stream"
                if (isset($afterDict[$streamStart]) && $afterDict[$streamStart] === "\r") $streamStart++;
                if (isset($afterDict[$streamStart]) && $afterDict[$streamStart] === "\n") $streamStart++;

                $length = (int) ($dict['Length'] ?? 0);

                if ($length > 0) {
                    $stream = substr($afterDict, $streamStart, $length);
                } else {
                    // Fallback: read until endstream
                    $endPos = strpos($afterDict, 'endstream', $streamStart);
                    $stream = $endPos !== false
                        ? rtrim(substr($afterDict, $streamStart, $endPos - $streamStart), "\r\n")
                        : substr($afterDict, $streamStart, 65536);
                }

                $filter = $dict['Filter'] ?? '';
                if ($filter === '/FlateDecode' || $filter === 'FlateDecode') {
                    try {
                        $stream = FlateDecode::decompress($stream);
                    } catch (\Exception) {
                        // Keep raw stream if decompression fails
                    }
                }
            }
        } elseif (preg_match('/^(\d+)\s+(\d+)\s+R/', $body, $ref)) {
            $dict = ['_ref' => ['type' => 'ref', 'obj' => (int) $ref[1], 'gen' => (int) $ref[2]]];
        }

        return ['dictionary' => $dict, 'stream' => $stream];
    }

    /**
     * Find the position of the matching '>>' for a '<<' at $start.
     */
    private function findDictEnd(string $text, int $start): int
    {
        $depth = 0;
        $len   = strlen($text);
        $i     = $start;

        while ($i < $len - 1) {
            if ($text[$i] === '<' && $text[$i + 1] === '<') {
                $depth++;
                $i += 2;
            } elseif ($text[$i] === '>' && $text[$i + 1] === '>') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
                $i += 2;
            } else {
                $i++;
            }
        }

        return $len - 2;
    }
}
