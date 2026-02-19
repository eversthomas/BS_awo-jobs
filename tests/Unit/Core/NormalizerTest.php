<?php

namespace BsAwoJobs\Tests\Unit\Core;

use BsAwoJobs\Core\Normalizer;
use PHPUnit\Framework\TestCase;

class NormalizerTest extends TestCase
{
    /**
     * @dataProvider normalizeStringProvider
     */
    public function test_normalize_string(string $input, string $expected): void
    {
        self::assertSame($expected, Normalizer::normalize_string($input));
    }

    public static function normalizeStringProvider(): array
    {
        return [
            'leer'           => ['', ''],
            'kleinbuchstaben' => ['ABC', 'abc'],
            'umlaute'        => ['München', 'muenchen'],
            'ß'              => ['Straße', 'strasse'],
            'gemischt'       => ['Kita 123 Wesel', 'kita123wesel'],
            'sonderzeichen'  => ['A-W-O (Test)', 'awotest'],
        ];
    }

    public function test_generate_facility_id_deterministisch(): void
    {
        $job = [
            'Einrichtung' => 'Kita Sonnenschein',
            'Strasse'     => 'Musterstraße 1',
            'PLZ'         => '46483',
            'Ort'         => 'Wesel',
        ];

        $id1 = Normalizer::generate_facility_id($job);
        $id2 = Normalizer::generate_facility_id($job);

        self::assertSame($id1, $id2);
        self::assertSame(16, strlen($id1));
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $id1);
    }

    public function test_generate_facility_id_unterschiedliche_jobs_unterschiedliche_ids(): void
    {
        $job1 = ['Einrichtung' => 'Kita A', 'Strasse' => '', 'PLZ' => '46483', 'Ort' => 'Wesel'];
        $job2 = ['Einrichtung' => 'Kita B', 'Strasse' => '', 'PLZ' => '46483', 'Ort' => 'Wesel'];

        self::assertNotSame(
            Normalizer::generate_facility_id($job1),
            Normalizer::generate_facility_id($job2)
        );
    }

    public function test_generate_facility_id_leere_felder(): void
    {
        $id = Normalizer::generate_facility_id([]);
        self::assertSame(16, strlen($id));
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $id);
    }
}
