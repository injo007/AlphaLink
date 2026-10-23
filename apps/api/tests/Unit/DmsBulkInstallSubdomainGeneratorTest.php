<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsBulkInstallSubdomainGenerator;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;

class DmsBulkInstallSubdomainGeneratorTest extends TestCase
{
    public function test_it_preserves_the_exact_dms_random_word_pool(): void
    {
        $words = (new ReflectionClass(DmsBulkInstallSubdomainGenerator::class))->getConstant('KEYWORDS');

        $this->assertCount(1022, $words);
        $this->assertSame(
            'f7dffc8b88a09175855975e2ab168024969445da58f59e03c08b5a7b38214571',
            hash('sha256', json_encode($words, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        );
    }

    public function test_it_selects_distinct_source_entries_and_matches_dms_count_boundaries(): void
    {
        $words = (new ReflectionClass(DmsBulkInstallSubdomainGenerator::class))->getConstant('KEYWORDS');
        $generator = new DmsBulkInstallSubdomainGenerator(new Randomizer(new Mt19937(42)));
        $sample = $generator->generate(32);

        $this->assertCount(32, $sample);
        foreach ($sample as $word) {
            $this->assertContains($word, $words);
        }

        $allWords = $generator->generate(count($words));
        sort($allWords);
        sort($words);
        $this->assertSame($words, $allWords);
        $this->assertSame([], $generator->generate(0));
        $this->assertSame([], $generator->generate(-1));

        $this->expectException(InvalidArgumentException::class);
        $generator->generate(count($words) + 1);
    }

    public function test_default_selection_uses_laravels_random_array_helper(): void
    {
        $words = (new ReflectionClass(DmsBulkInstallSubdomainGenerator::class))->getConstant('KEYWORDS');
        $selected = (new DmsBulkInstallSubdomainGenerator)->generate(1);

        $this->assertCount(1, $selected);
        $this->assertContains($selected[0], $words);
        $this->assertSame([], Arr::random($words, 0));
    }
}
