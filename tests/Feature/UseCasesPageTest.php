<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UseCasesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_shows_four_examples_and_says_the_people_are_illustrative(): void
    {
        $response = $this->get('/how-people-use-pegwatch')->assertOk()
            ->assertSee('How people use Pegwatch')
            ->assertSee('The people are illustrative composites, not real customers.')
            ->assertSee('A prospector pegging dead ground')
            ->assertSee('A tenement consultant')
            ->assertSee('Graticular block');

        $this->assertSame(4, substr_count($response->getContent(), 'data-test="use-case"'));
        $response->assertSee(route('pro').'?ref=use-case-prospector', false);
    }

    public function test_the_footer_and_plans_page_link_to_it(): void
    {
        $this->get('/pro')->assertOk()->assertSee(route('use-cases'));
        $this->get('/data')->assertOk()->assertSee('How people use it');
    }
}
