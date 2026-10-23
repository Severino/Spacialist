<?php

namespace App\Import\Parser;

use InvalidArgumentException;

class DelimitedTextParser {

    const quotes = ['"', "'"];

    public static function sanitizeWord($word) {
        $word = trim($word);
        // Check if entire string is enclosed with
        // quotes, then we remove them
        // e.g. "zorin.1802":"Brin;Calder" should be valid
        // but removing the outer quotes would break the next parsing step.
        $inQuotes = false;
        $quoteType = null;

        // Check if we need to check for enclosign quotes
        if(in_array($word[0], self::quotes)) {
            $quoteType = $word[0];
        } else {
            return $word;
        }

        // If the last char is not the same quote, return.
        if($word[strlen($word) - 1] !== $quoteType) {
            return $word;
        }

        for($i = 1; $i < strlen($word) - 1; $i++) {
            $char = $word[$i];
            if($char === '\\') {
                $i++;
            } else if($char === $quoteType) {
                return $word;
            }
        }
        
        return substr($word, 1, strlen($word)-2);
    }

    /**
     * Split on a delimiter, ignoring delimiters inside quotes.
     *
     * If $limit is 2, split only at the first delimiter
     * encountered outside quotes.
     */
    public static function split(
        string $input,
        string $delimiter,
    ): array {
        if(strlen($delimiter) !== 1) {
            throw new InvalidArgumentException(
                'Delimiter must be a single character.'
            );
        }

        $whitespaces = ["\n", "\r", "\t", "\v", "\x00"]; // taken from trim()
        $escapeNext = false;

        $parts = [];
        $buffer = '';
        $inQuotes = false;
        $inQuoteType = null;
        $length = strlen($input);
        $splitPosition = -1;

        // We search a string and want to split the ':' when not quoted, e.g. `verne:"A ship: the 'Nautilus'!"` => ["verne", "A ship: the 'Nautilus'!" ]
        for($i = 0; $i < $length; $i++) {

            $char = $input[$i];

            if(!$inQuotes) {
                if($i === $splitPosition) {

                    // If we find a whitespace at the split position
                    // we skip it.
                    if(in_array($char, $whitespaces)) {
                        $splitPosition++;
                        continue;
                    }

                    if(strlen($buffer) > 0) {
                        // We trim the buffer to get rid of trailing white spaces
                        // as it would require a backtrace to remove those.
                        // For simplicity we just trim it.
                        $parts[] =self::sanitizeWord($buffer);
                        $buffer = '';
                    }
                }

                // We can only hit delimiters if we are
                // outside the quoted context.
                if($char === $delimiter) {
                    // We split on next iteration;
                    $splitPosition = $i + 1;
                    continue;
                }
                // If we encounter a quote directly after a split position
                // we identify it as a quoted text.
                else if(in_array($char, self::quotes)) {
                    $inQuotes = true;
                    $inQuoteType = $char;
                    // Outer Quotes are not buffered we are only interested in the string.
                }
            } else {
                // If we encounter a quote character
                // of the same type the quoted string
                // finishes
                if($char === $inQuoteType && !$escapeNext) {
                    $inQuotes = false;
                    $inQuoteType = null;
                } else {
                    if($char === "\\" && !$escapeNext) {
                        $escapeNext = true;
                        continue;
                    } else {
                        $escapeNext = false;
                    }
                }
            }

            // We always write to the buffer unless a continue prohibits it.
            $buffer .= $char;
        }

        if($inQuotes) {
            throw new InvalidArgumentException(
                'Unclosed quoted region.'
            );
        }

        $parts[] = self::sanitizeWord($buffer);

        return $parts;
    }

    /**
     * Parse a delimited list of key/value entries.
     */
    public static function parseList(
        string $input,
        string $listDelimiter = ';',
        string $pairDelimiter = ':',
    ): array {

        $entries = self::split($input, $listDelimiter);
        $result = [];

        foreach($entries as $entry) {
            $result[] = self::split($entry, $pairDelimiter);
        }

        return $result;
    }
}