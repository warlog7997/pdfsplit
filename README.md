# subash/phppdf

A pure PHP library for PDF page extraction, splitting, and merging — no external dependencies, no ImageMagick, no GhostScript.

Handles all modern PDF formats including PDF 1.5+ xref streams, ObjStm compressed objects, FlateDecode with PNG predictor, and incremental updates.

## Requirements

- PHP 8.0+
- `ext-zlib`

## Installation

```bash
composer require subash/phppdf
```

## Usage

### Split every page into individual PDFs

```php
use Subash\PhpPdf\PhpPdf;

// Save to directory — produces page_1.pdf, page_2.pdf, ...
PhpPdf::load('document.pdf')
    ->splitter()
    ->splitToDirectory('/output/dir/');

// Or get binary strings (store in DB, upload to S3, etc.)
$pages = PhpPdf::load('document.pdf')
    ->splitter()
    ->split();
// [1 => '<pdf binary>', 2 => '<pdf binary>', ...]
```

### Extract specific pages

```php
// Extract pages 1 and 3
$pdfBinary = PhpPdf::load('document.pdf')->extract([1, 3]);
file_put_contents('extracted.pdf', $pdfBinary);
```

### Extract a page range

```php
// Extract pages 1 through 5
$pdfBinary = PhpPdf::load('document.pdf')->extractRange(1, 5);
file_put_contents('pages1to5.pdf', $pdfBinary);
```

### Load from binary string

```php
$pdfBinary = file_get_contents('document.pdf');
$pdf = PhpPdf::loadData($pdfBinary);
echo $pdf->getPageCount();
```

## Supported PDF features

| Feature | Supported |
|---|---|
| Traditional xref tables (PDF 1.1–1.4) | ✅ |
| Xref streams (PDF 1.5+) | ✅ |
| ObjStm compressed objects (Type 2) | ✅ |
| FlateDecode + PNG predictor | ✅ |
| Incremental updates (/Prev chain) | ✅ |
| Large files / large objects | ✅ |
| Encrypted / password-protected PDFs | ❌ |

## License

MIT
