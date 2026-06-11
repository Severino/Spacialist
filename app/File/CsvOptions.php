<?php

namespace App\File;

class CsvOptions {
    public function __construct(
        public readonly ?bool $hasHeaderRow = false,
        public readonly ?string $delimiter = ',',
        public readonly ?string $encoding = 'UTF-8',
    ) { }

    public static function fromParams(array $options): CsvOptions {
        return new CsvOptions(
            hasHeaderRow: isset($options['has_header_row']) ? filter_var($options['has_header_row'], FILTER_VALIDATE_BOOLEAN) : null,
            delimiter: isset($options['delimiter']) ? trim($options['delimiter']) : null,
            encoding: isset($options['encoding']) ? trim($options['encoding']) : null,
        );
    }

    public function toString(): string {
        return "CsvOptions(hasHeaderRow: " . ($this->hasHeaderRow ? 'true' : 'false') . ", delimiter: '" . $this->delimiter . "', encoding: '" . $this->encoding . "')";
    }
}