<?php

require_once __DIR__ . '/vendor/autoload.php';

use Subash\PhpPdf\PhpPdf;
use Subash\PhpPdf\Parser\PdfParser;

$inputDir  = __DIR__ . '/input';
$outputDir = __DIR__ . '/split_output';

if (!is_dir($inputDir)) {
    mkdir($inputDir, 0755, true);
    die("Created input/ folder — add your PDFs there and run again.\n");
}

$pdfs = glob($inputDir . '/*.pdf');

if (empty($pdfs)) {
    die("No PDFs found in input/ — add some and run again.\n");
}

$passed = 0;
$failed = 0;

foreach ($pdfs as $pdfPath) {
    $name     = basename($pdfPath, '.pdf');
    $fileSize = round(filesize($pdfPath) / 1024, 1);
    echo "─── {$name}.pdf ({$fileSize} KB) ───\n";

    try {
        $pdf = PhpPdf::load($pdfPath);
        $doc = $pdf->getDocument();

        // Diagnostics
        $version    = $doc->getVersion();
        $xref       = $doc->getXref();
        $objStmRefs = $doc->getObjStmRefs();
        $trailer    = $doc->getTrailer();
        $pageCount  = $pdf->getPageCount();
        $hasPrev    = isset($trailer['Prev']) ? 'yes' : 'no';

        echo "  Version   : PDF {$version}\n";
        echo "  Pages     : {$pageCount}\n";
        echo "  Xref objs : " . count($xref) . " Type1, " . count($objStmRefs) . " Type2 (ObjStm)\n";
        echo "  Incremental: {$hasPrev}\n";

        // Split
        $dest  = $outputDir . '/' . $name;
        $paths = $pdf->splitter()->splitToDirectory($dest);
        echo "  Split     : " . count($paths) . " files → split_output/{$name}/\n";

        // Verify output files are non-empty and start with %PDF
        $bad = 0;
        foreach ($paths as $p) {
            $h = file_get_contents($p, false, null, 0, 4);
            if ($h !== '%PDF') $bad++;
        }
        if ($bad > 0) {
            echo "  WARNING   : {$bad} output file(s) do not start with %PDF\n";
        } else {
            echo "  Output    : all files valid\n";
        }

        $passed++;
    } catch (\Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
        echo "  " . $e->getFile() . ':' . $e->getLine() . "\n";
        $failed++;
    }

    echo "\n";
}

echo "Done — {$passed} passed, {$failed} failed.\n";
