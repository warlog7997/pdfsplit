<?php

declare(strict_types=1);

namespace Subash\PhpPdf\Document;

/**
 * Represents a single page in a PDF document.
 */
class Page
{
    public function __construct(
        private int   $number,
        private int   $objectNumber,
        private array $dictionary,
        private array $resources = [],
    ) {}

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getObjectNumber(): int
    {
        return $this->objectNumber;
    }

    public function getDictionary(): array
    {
        return $this->dictionary;
    }

    public function getResources(): array
    {
        return $this->resources;
    }

    public function getMediaBox(): array
    {
        return $this->dictionary['MediaBox'] ?? [0, 0, 612, 792];
    }
}
