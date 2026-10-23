<?php

use App\Import\Parser\DelimitedTextParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DelimiterTextParserTest extends TestCase {
    #[DataProvider('successProvider')]
    public function testParseSuccessfully(string $input, array $expected, string $delimiter = ':') {
        $this->assertSame(
            $expected,
            DelimitedTextParser::split($input, $delimiter)
        );
    }

    public static function successProvider(): array {
        return [
            'simple pair' => [
                'zorin.1802:lumeria',
                ['zorin.1802', 'lumeria'],
            ],
            'three parts' => [
                'zorin.1802:lumeria:act3',
                ['zorin.1802', 'lumeria', 'act3'],
            ],
            'no pair delimiter' => [
                'zorin.1802',
                ['zorin.1802'],
            ],
            'quoted part containing pair delimiter' => [
                'veldmar_1911:"The Oath: Lumeria"',
                ['veldmar_1911', 'The Oath: Lumeria'],
            ],
            'quoted part without delimiter' => [
                'veldmar_1911:"Plain"',
                ['veldmar_1911', 'Plain'],
            ],
            'quoted first part' => [
                '"Orlen: Vol 2":tarvic',
                ['Orlen: Vol 2', 'tarvic'],
            ],
            'escaped quote inside quotes' => [
                'orlen.1799:"He said \\"hush\\": twice"',
                ['orlen.1799', 'He said "hush": twice'],
            ],
            'escaped backslash inside quotes' => [
                'orlen.1799:"back\\\\slash:end"',
                ['orlen.1799', 'back\\slash:end'],
            ],
            'empty quoted part' => [
                'orlen.1799:""',
                ['orlen.1799', ''],
            ],
            'simple list' => [
                'zorin.1802:"Brin;Calder";kavell.1750:"The Siege: Part of Norvel"',
                ['zorin.1802:"Brin;Calder"', 'kavell.1750:"The Siege: Part of Norvel"'],
                ';'
            ]
        ];
    }

    #[DataProvider('failProvider')]
    public function testParseFails(string $input, string $delimiter = ';') {
        $this->expectException(InvalidArgumentException::class);
        DelimitedTextParser::split($input, $delimiter);
    }

    public static function failProvider(): array {
        return [
            'unclosed quote' => ['zorin.1802:"lumeria'],
            'unclosed quote after escaped quote' => ['zorin.1802:"lum\\"eria'],
            'unclosed quote in multiple entries' => ['zorin.1802:"Brin;kavell.1750:thessan'],
            'closing quote escaped at end' => ['zorin.1802:"lumeria\\"'],
            'empty delimiter' => ['zorin.1802:lumeria', ''],
            'delimiter with multiple characters' => ['zorin.1802:lumeria', 'as'],
            
        ];
    }

    #[DataProvider('pairsSuccessProvider')]
    public function testParsePairsSuccess(string $input, array $expected, string $listDelimiter = ';', string $itemDelimiter = ':') {
        $this->assertSame(
            $expected,
            DelimitedTextParser::parseList($input, $listDelimiter, $itemDelimiter)
        );
    }

    public static function pairsSuccessProvider(): array {
        return [
            'multiple entries' => [
                'zorin.1802:lumeria;kavell.1750:thessan',
                [['zorin.1802', 'lumeria'], ['kavell.1750', 'thessan']],
            ],
            'quoted entry delimiter' => [
                'zorin.1802:"Brin;Calder";kavell.1750:"The Siege: Part of Norvel"',
                [['zorin.1802', 'Brin;Calder'], ['kavell.1750', 'The Siege: Part of Norvel']],
            ],
            'escaped quote with entry delimiter' => [
                'zorin.1802:"The \\"Wanderer\\"; Ode";kavell.1750:thessan',
                [['zorin.1802', 'The "Wanderer"; Ode'], ['kavell.1750', 'thessan']],
            ],
            'custom delimiters' => [
                'kavell.1750|"a|b"|c,tarvic.1760|d',
                [['kavell.1750', 'a|b', 'c'], ['tarvic.1760', 'd']],
                ',',
                '|'
            ],
        ];
    }

    #[DataProvider('failPairsProvider')]
    public function testParsePairsFails(string $input, string $listDelimiter = ';', string $pairDelimiter = ':') {
        $this->expectException(InvalidArgumentException::class);
        DelimitedTextParser::parseList($input, $listDelimiter, $pairDelimiter);
    }

    public static function failPairsProvider(): array {
        return [
            'pair delimiter longer than one char' => ['zorin.1802::lumeria', ';', '::'],
            'entry delimiter longer than one char' => ['zorin.1802:lumeria;;kavell.1750:thessan', ';;', ':'],
        ];
    }

}