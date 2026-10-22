<?php

namespace App\Import\Parser;

use InvalidArgumentException;

final class DelimitedTextParser {

    /**
     * Split on a delimiter, ignoring delimiters inside quotes.
     *
     * If $limit is 2, split only at the first delimiter
     * encountered outside quotes.
     */
    public function split(
        string $input,
        string $delimiter,
    ): array {
        if(strlen($delimiter) !== 1) {
            throw new InvalidArgumentException(
                'Delimiter must be a single character.'
            );
        }

        $quotes = ['"', "'"];
        $whitespaces = [" ", "\t"];
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
                        $parts[] = trim($buffer);
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
                else if(in_array($char, $quotes)) {
                    $inQuotes = true;
                    $inQuoteType = $char;
                    // Outer Quotes are not buffered we are only interested in the string.
                    continue;
                }

                // if we did hit nothing else we append the text
                $buffer .= $char;
            } else {
                // If we encounter a quote character
                // of the same type the quoted string
                // finishes
                if($char === $inQuoteType && !$escapeNext) {
                    $inQuotes = false;
                    $inQuoteType = null;
                    continue;
                } else {
                    if($char === "\\" && !$escapeNext){
                        $escapeNext = true;
                        continue;
                    }else{
                        $escapeNext = false;
                    }
                
                    $buffer .= $char;
                    continue;
                }
            }
        }

        if($inQuotes) {
            throw new InvalidArgumentException(
                'Unclosed quoted region.'
            );
        }

        $parts[] = trim($buffer);

        return $parts;
    }

    /**
     * Parse a delimited list of key/value entries.
     *
     * Example: key1:"value; with delimiters";key2:other
     */
    public function parsePairs(
        string $input,
        string $entryDelimiter = ';',
        string $pairDelimiter = ':',
    ): array {
        $entries = $this->split($input, $entryDelimiter);
        $result = [];

        foreach($entries as $entry) {
            if(trim($entry) === '') {
                continue;
            }

            $result[] = $this->split($entry, $pairDelimiter);
        }

        return $result;
    }
}