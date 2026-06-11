<?php

namespace App\File;

class CsvOptions {
    public function __construct(
        public readonly bool $hasHeaderRow = false,
        public readonly int $delimiter = 0,
        public readonly string $encoding = 'UTF-8',
    ) { }
}