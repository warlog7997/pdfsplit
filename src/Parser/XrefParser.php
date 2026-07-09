<?php

declare(strict_types=1);

namespace Subash\PhpPdf\Parser;

use Subash\PhpPdf\Exceptions\PdfParseException;
use Subash\PhpPdf\Filter\FlateDecode;

/**
 * Parses the cross-reference table from a PDF file.
 * Supports traditional xref tables and xref streams (PDF 1.5+).
 */
class XrefParser
{
    private array $trailer    = [];
    private array $objStmRefs = []; // Type 2: [objNum => [stmObjNum, indexInStm]]

    public function __construct(private string $data) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function getObjStmRefs(): array
    {
        return $this->objStmRefs;
    }

    public function findXrefOffset(): int
    {
        $end = substr($this->data, -1024);

        if (!preg_match('/startxref\s+(\d+)\s+%%EOF/s', $end, $matches)) {
            throw new PdfParseException('Could not find startxref in PDF');
        }

        return (int) $matches[1];
    }

    /**
     * Parse xref — auto-detects traditional table vs xref stream (PDF 1.5+).
     */
    public function parse(int $offset): array
    {
        // Skip any whitespace before keyword
        $peek = ltrim(substr($this->data, $offset, 20));

        if (str_starts_with($peek, 'xref')) {
            return $this->parseTraditional($offset);
        }

        // Xref stream: starts with "<objNum> <gen> obj"
        if (preg_match('/^\d+\s+\d+\s+obj/', $peek)) {
            return $this->parseXrefStream($offset);
        }

        throw new PdfParseException('Unrecognised xref format at offset ' . $offset);
    }

    public function parseTrailer(): array
    {
        // Already populated from xref stream
        if (!empty($this->trailer)) {
            return $this->trailer;
        }

        // Traditional trailer keyword
        if (!preg_match('/trailer\s*(<<)/s', $this->data, $m, PREG_OFFSET_CAPTURE)) {
            throw new PdfParseException('Could not find trailer dictionary');
        }

        $pos    = (int) $m[1][1]; // position of '<<'
        $result = $this->parseDictionaryAt($pos);
        return $result['value'];
    }

    // -------------------------------------------------------------------------
    // Traditional xref table
    // -------------------------------------------------------------------------

    private function parseTraditional(int $offset): array
    {
        $xref = [];
        $pos  = $offset + 4; // skip "xref"

        while (true) {
            $pos = $this->skipWhitespace($pos);

            if (substr($this->data, $pos, 7) === 'trailer') {
                break;
            }

            if (!preg_match('/(\d+)\s+(\d+)/', $this->data, $matches, 0, $pos)) {
                break;
            }

            $startObj = (int) $matches[1];
            $count    = (int) $matches[2];
            $pos     += strlen($matches[0]);
            $pos      = $this->skipWhitespace($pos);

            for ($i = 0; $i < $count; $i++) {
                $entry      = substr($this->data, $pos, 20);
                $byteOffset = (int) substr($entry, 0, 10);
                $inUse      = trim(substr($entry, 17, 1)) === 'n';

                if ($inUse) {
                    $xref[$startObj + $i] = $byteOffset;
                }

                $pos += 20;
            }
        }

        return $xref;
    }

    // -------------------------------------------------------------------------
    // Xref stream (PDF 1.5+)
    // -------------------------------------------------------------------------

    private function parseXrefStream(int $offset): array
    {
        // Find and parse the object header: "<num> <gen> obj"
        if (!preg_match('/\d+\s+\d+\s+obj\s*/A', $this->data, $m, 0, $offset)) {
            throw new PdfParseException('Could not find xref stream object at offset ' . $offset);
        }

        $pos    = $offset + strlen($m[0]);
        $parsed = $this->parseDictionaryAt($pos);
        $dict   = $parsed['value'];
        $pos    = $parsed['end'];

        // Store trailer info
        $this->trailer = $dict;

        // Advance past "stream" keyword and line ending
        $pos = $this->skipToStreamData($pos);

        // Read exactly /Length bytes (binary safe)
        $length     = (int) ($dict['Length'] ?? 0);
        $streamData = substr($this->data, $pos, $length);

        // Decompress if needed
        $filter = $dict['Filter'] ?? '';
        if ($filter === '/FlateDecode' || $filter === 'FlateDecode') {
            $streamData = FlateDecode::decompress($streamData);

            // Apply PNG predictor if DecodeParms is present
            $decodeParms = $dict['DecodeParms'] ?? [];
            if (!empty($decodeParms) && is_array($decodeParms)) {
                $streamData = FlateDecode::applyPredictor($streamData, $decodeParms);
            }
        }

        // Parse /W field widths
        $w  = $dict['W'] ?? [1, 4, 2];
        $w1 = (int) ($w[0] ?? 1);
        $w2 = (int) ($w[1] ?? 4);
        $w3 = (int) ($w[2] ?? 2);
        $entrySize = $w1 + $w2 + $w3;

        // Parse /Index ranges
        $index = $dict['Index'] ?? [0, (int) ($dict['Size'] ?? 0)];

        $xref       = [];
        $dataPos    = 0;
        $dataLength = strlen($streamData);

        for ($pair = 0; $pair + 1 < count($index); $pair += 2) {
            $startObj = (int) $index[$pair];
            $count    = (int) $index[$pair + 1];

            for ($i = 0; $i < $count; $i++) {
                if ($dataPos + $entrySize > $dataLength) {
                    break 2;
                }

                $type   = $w1 > 0 ? $this->readInt($streamData, $dataPos, $w1) : 1;
                $field2 = $this->readInt($streamData, $dataPos + $w1, $w2);
                $dataPos += $entrySize;

                // field3 = generation (Type 1) or index within ObjStm (Type 2)
                $field3 = $w3 > 0 ? $this->readInt($streamData, $dataPos - $w3, $w3) : 0;

                if ($type === 1 && $field2 > 0) {
                    // Regular in-use object — field2 = byte offset
                    $xref[$startObj + $i] = $field2;
                } elseif ($type === 2) {
                    // Compressed in ObjStm — field2 = ObjStm object number, field3 = index
                    $this->objStmRefs[$startObj + $i] = [(int) $field2, (int) $field3];
                }
                // Type 0 = free — skip
            }
        }

        return $xref;
    }

    // -------------------------------------------------------------------------
    // Proper token-based dictionary parser
    // -------------------------------------------------------------------------

    /**
     * Parse a PDF dictionary starting at position $pos (which points to '<<').
     * Returns ['value' => array, 'end' => int (position after '>>')]
     */
    public function parseDictionaryAt(int $pos): array
    {
        $pos = $this->skipWhitespace($pos);

        if (substr($this->data, $pos, 2) !== '<<') {
            throw new PdfParseException("Expected '<<' at position {$pos}, got: " . substr($this->data, $pos, 10));
        }

        $pos   += 2;
        $result = [];

        while ($pos < strlen($this->data)) {
            $pos = $this->skipWhitespace($pos);

            // End of dictionary
            if (substr($this->data, $pos, 2) === '>>') {
                $pos += 2;
                break;
            }

            // Key must start with '/'
            if ($this->data[$pos] !== '/') {
                // Skip unexpected character
                $pos++;
                continue;
            }

            // Read key — strip leading '/' so keys are plain strings e.g. 'Root' not '/Root'
            $keyParsed = $this->parseName($pos);
            $key       = ltrim($keyParsed['value'], '/');
            $pos       = $keyParsed['end'];
            $pos       = $this->skipWhitespace($pos);

            // Read value
            $valueParsed  = $this->parseValue($pos);
            $result[$key] = $valueParsed['value'];
            $pos          = $valueParsed['end'];
        }

        return ['value' => $result, 'end' => $pos];
    }

    /**
     * Parse any PDF value at position $pos.
     */
    private function parseValue(int $pos): array
    {
        $pos  = $this->skipWhitespace($pos);
        $char = $this->data[$pos] ?? '';

        // Dictionary
        if (substr($this->data, $pos, 2) === '<<') {
            $parsed = $this->parseDictionaryAt($pos);
            return $parsed;
        }

        // Array
        if ($char === '[') {
            return $this->parseArray($pos);
        }

        // Name
        if ($char === '/') {
            return $this->parseName($pos);
        }

        // String (literal)
        if ($char === '(') {
            return $this->parseLiteralString($pos);
        }

        // Hex string
        if ($char === '<' && ($this->data[$pos + 1] ?? '') !== '<') {
            return $this->parseHexString($pos);
        }

        // Number or indirect reference
        if ($char === '-' || ctype_digit($char)) {
            return $this->parseNumberOrRef($pos);
        }

        // Boolean / null
        if (substr($this->data, $pos, 4) === 'true') {
            return ['value' => true, 'end' => $pos + 4];
        }
        if (substr($this->data, $pos, 5) === 'false') {
            return ['value' => false, 'end' => $pos + 5];
        }
        if (substr($this->data, $pos, 4) === 'null') {
            return ['value' => null, 'end' => $pos + 4];
        }

        // Unknown — skip character
        return ['value' => null, 'end' => $pos + 1];
    }

    private function parseName(int $pos): array
    {
        $pos++; // skip '/'
        $name = '';

        while ($pos < strlen($this->data)) {
            $c = $this->data[$pos];
            if (ctype_space($c) || in_array($c, ['/', '[', ']', '(', ')', '<', '>', '{', '}', '%'])) {
                break;
            }
            $name .= $c;
            $pos++;
        }

        return ['value' => '/' . $name, 'end' => $pos];
    }

    private function parseArray(int $pos): array
    {
        $pos++;  // skip '['
        $result = [];

        while ($pos < strlen($this->data)) {
            $pos  = $this->skipWhitespace($pos);
            $char = $this->data[$pos] ?? '';

            if ($char === ']') {
                $pos++;
                break;
            }

            $parsed   = $this->parseValue($pos);
            $result[] = $parsed['value'];
            $pos      = $parsed['end'];
        }

        return ['value' => $result, 'end' => $pos];
    }

    private function parseLiteralString(int $pos): array
    {
        $pos++;   // skip '('
        $depth  = 1;
        $result = '';

        while ($pos < strlen($this->data) && $depth > 0) {
            $c = $this->data[$pos];
            if ($c === '\\') {
                $result .= $this->data[++$pos] ?? '';
            } elseif ($c === '(') {
                $depth++;
                $result .= $c;
            } elseif ($c === ')') {
                $depth--;
                if ($depth > 0) $result .= $c;
            } else {
                $result .= $c;
            }
            $pos++;
        }

        return ['value' => $result, 'end' => $pos];
    }

    private function parseHexString(int $pos): array
    {
        $pos++;  // skip '<'
        $hex = '';

        while ($pos < strlen($this->data) && $this->data[$pos] !== '>') {
            $hex .= $this->data[$pos++];
        }

        $pos++;  // skip '>'
        return ['value' => @hex2bin(str_replace(' ', '', $hex)) ?: $hex, 'end' => $pos];
    }

    private function parseNumberOrRef(int $pos): array
    {
        // Read first number
        preg_match('/[+-]?\d+(\.\d+)?/', $this->data, $m, 0, $pos);
        $firstNum = $m[0];
        $afterFirst = $pos + strlen($firstNum);

        // Check for indirect reference: "N G R"
        $rest = substr($this->data, $afterFirst, 20);
        if (preg_match('/^\s+(\d+)\s+R\b/', $rest, $refMatch)) {
            return [
                'value' => [
                    'type' => 'ref',
                    'obj'  => (int) $firstNum,
                    'gen'  => (int) $refMatch[1],
                ],
                'end' => $afterFirst + strlen($refMatch[0]),
            ];
        }

        // Plain number
        $value = str_contains($firstNum, '.') ? (float) $firstNum : (int) $firstNum;
        return ['value' => $value, 'end' => $afterFirst];
    }

    // -------------------------------------------------------------------------
    // Convenience wrapper used by Document/ObjectParser
    // -------------------------------------------------------------------------

    /**
     * Parse a dictionary from a raw string (wraps parseDictionaryAt).
     */
    public function parseDictionary(string $dictString): array
    {
        // Find '<<' in the string
        $pos = strpos($dictString, '<<');
        if ($pos === false) {
            return [];
        }

        // We need to parse against the full file data for offset accuracy,
        // but here we get a substring. Use a temporary XrefParser on the string.
        $temp   = new self($dictString);
        $result = $temp->parseDictionaryAt($pos);
        return $result['value'];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function skipWhitespace(int $pos): int
    {
        $len = strlen($this->data);
        while ($pos < $len && ctype_space($this->data[$pos])) {
            $pos++;
        }
        return $pos;
    }

    /**
     * Advance past "stream" keyword and the following line ending (\r\n or \n).
     */
    private function skipToStreamData(int $pos): int
    {
        $streamPos = strpos($this->data, 'stream', $pos);
        if ($streamPos === false) {
            throw new PdfParseException('Could not find stream keyword');
        }

        $pos = $streamPos + 6; // skip "stream"

        // Skip \r\n or \n
        if (isset($this->data[$pos]) && $this->data[$pos] === "\r") $pos++;
        if (isset($this->data[$pos]) && $this->data[$pos] === "\n") $pos++;

        return $pos;
    }

    /**
     * Read a big-endian unsigned integer of $width bytes from binary string.
     */
    private function readInt(string $data, int $offset, int $width): int
    {
        if ($width <= 0 || $offset + $width > strlen($data)) {
            return 0;
        }

        $result = 0;
        for ($i = 0; $i < $width; $i++) {
            $result = ($result << 8) | ord($data[$offset + $i]);
        }

        return $result;
    }
}
