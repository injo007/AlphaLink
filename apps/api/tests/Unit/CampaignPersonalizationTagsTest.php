<?php

namespace Tests\Unit;

use App\Services\Campaigns\CampaignPersonalizationTags;
use PHPUnit\Framework\TestCase;

class CampaignPersonalizationTagsTest extends TestCase
{
    public function test_personalizes_names_and_uses_the_email_alias_when_a_name_is_missing(): void
    {
        $personalizer = new CampaignPersonalizationTags;
        $profile = ['first_name' => 'Anne', 'last_name' => 'O\'Neil'];

        $this->assertSame(
            "Subject: Hello Anne O'Neil\r\nTo: member@example.test",
            $personalizer->personalizeSubjectHeader(
                "Subject: Hello __Fname__ __Lname__\r\nTo: member@example.test",
                $profile,
                'member@example.test',
            ),
        );
        $this->assertSame(
            '<p>Anne O&#039;Neil</p>',
            $personalizer->personalizeBody('<p>__Fname__ __Lname__</p>', $profile, 'member@example.test', true),
        );
        $this->assertSame(
            'Hello no.name no.name',
            $personalizer->personalizeBody('Hello __Fname__ __Lname__', [], 'no.name@example.test', false),
        );
    }
}
