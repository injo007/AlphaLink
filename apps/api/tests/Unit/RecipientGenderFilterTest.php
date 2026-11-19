<?php

namespace Tests\Unit;

use App\Services\Audiences\RecipientGenderFilter;
use PHPUnit\Framework\TestCase;

class RecipientGenderFilterTest extends TestCase
{
    public function test_all_keeps_unknown_gender_while_specific_filters_require_explicit_data(): void
    {
        $filter = new RecipientGenderFilter;

        self::assertTrue($filter->matches('all', null));
        self::assertFalse($filter->matches('female', null));
        self::assertFalse($filter->matches('male', null));
        self::assertTrue($filter->matches('female', ['sex' => 'F']));
        self::assertTrue($filter->matches('male', null, ['gender' => 'Man']));
        self::assertFalse($filter->matches('female', ['sex' => 'male'], ['sex' => 'female']));
    }
}
