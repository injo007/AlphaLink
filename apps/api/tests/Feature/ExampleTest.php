<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_home_redirects_guests_to_sign_in(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
