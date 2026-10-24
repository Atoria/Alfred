<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Explanations\Explanation;
use Atoria\Alfred\Tests\TestCase;

class BusinessExplanationTest extends TestCase
{
    protected function submitWith(array $overrides): array
    {
        return ['submit_explanation', array_merge([
            'title' => 'Fill rate',
            'summary' => 'Shows how many of the posted shifts have someone booked.',
            'formula' => 'Booked shifts ÷ all shifts × 100',
            'example' => 'If 4 shifts are posted and 2 are booked, it shows 50%.',
            'steps' => ['Counts the booked shifts', 'Counts all shifts', 'Divides one by the other'],
            'data_sources' => ['Shifts'],
            'caveats' => ['Cancelled shifts still count towards the total'],
            'glossary' => ['Booked — a shift that has someone assigned to it'],
            'developer' => [
                'summary' => 'DashboardController::index divides Shift::booked()->count() by Shift::count().',
                'flow' => ['app/Http/Controllers/DashboardController.php:11 counts booked', 'app/Http/Controllers/DashboardController.php:12 counts all'],
                'queries' => ['select count(*) from shifts where status = booked'],
                'caching' => null,
                'gotchas' => ['Cancelled shifts are in the denominator'],
            ],
            'query_index' => 0,
            'confidence' => 'high',
        ], $overrides)];
    }

    public function test_code_in_the_business_part_is_sent_back_and_rewritten(): void
    {
        $verifier = $this->user('verifier@example.com');
        $token = $this->loadDashboard($verifier);

        $this->llm
            ->then([$this->submitWith(['summary' => 'This panel is produced by WeeklySalesReport::sheet() which runs a SQL query over weekly_sales_pending.'])])
            ->then([$this->submitWith([])]);

        $res = $this->actingAs($verifier)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])->assertOk();

        $feedback = $this->llm->toolOutputs()[0];
        $this->assertStringStartsWith('Not accepted', $feedback);
        foreach (['WeeklySalesReport::sheet', 'weekly_sales_pending', 'SQL'] as $term) {
            $this->assertStringContainsString($term, $feedback);
        }

        $res->assertJson([
            'summary' => 'Shows how many of the posted shifts have someone booked.',
            'example' => 'If 4 shifts are posted and 2 are booked, it shows 50%.',
            'glossary' => ['Booked — a shift that has someone assigned to it'],
            'developer' => ['summary' => 'DashboardController::index divides Shift::booked()->count() by Shift::count().'],
        ]);
        $this->assertSame(Explanation::FORMAT, Explanation::find($res->json('id'))->format);
    }

    public function test_after_two_rewrites_it_is_accepted_rather_than_failing(): void
    {
        $verifier = $this->user('verifier@example.com');
        $token = $this->loadDashboard($verifier);
        $jargon = $this->submitWith(['summary' => 'Uses ShiftService to compute it.']);
        $this->llm->then([$jargon])->then([$jargon])->then([$jargon]);

        $this->actingAs($verifier)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])
            ->assertOk()->assertJson(['summary' => 'Uses ShiftService to compute it.']);
        $this->assertCount(3, $this->llm->requests);
    }

    public function test_the_developer_part_is_only_for_people_allowed_to_see_code(): void
    {
        $this->llm->then([$this->submitWith([])]);
        $manager = $this->user();
        $this->actingAs($manager)->postJson('/_alfred/explain', ['token' => $this->loadDashboard($manager), 'element' => $this->element()])
            ->assertOk()->assertJsonMissingPath('developer')->assertJsonPath('example', 'If 4 shifts are posted and 2 are booked, it shows 50%.');

        \Gate::define('viewAlfredCode', fn ($user = null) => $user !== null);
        $this->actingAs($manager)->postJson('/_alfred/explain', ['token' => $this->loadDashboard($manager), 'element' => $this->element()])
            ->assertOk()->assertJsonPath('developer.flow.0', 'app/Http/Controllers/DashboardController.php:11 counts booked');
    }

    public function test_old_single_audience_explanations_are_regenerated_unless_verified(): void
    {
        $verifier = $this->user('verifier@example.com');
        $token = $this->loadDashboard($verifier);
        $this->llm->then([$this->submitWith([])]);
        $id = $this->actingAs($verifier)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])->json('id');
        Explanation::query()->whereKey($id)->update(['format' => 1, 'developer' => null]);

        $this->llm->then([$this->submitWith(['title' => 'Fill rate (v2)'])]);
        $this->actingAs($verifier)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])
            ->assertOk()->assertJson(['title' => 'Fill rate (v2)', 'cached' => false]);

        // a verified old one is kept as it is
        Explanation::query()->whereKey($id)->update(['format' => 1, 'status' => Explanation::VERIFIED]);
        $this->actingAs($verifier)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])
            ->assertOk()->assertJson(['cached' => true, 'verified' => true]);
        $this->assertCount(2, $this->llm->requests);
    }

    public function test_verifiers_can_edit_the_example_and_terms(): void
    {
        $verifier = $this->user('verifier@example.com');
        $this->llm->then([$this->submitWith([])]);
        $id = $this->actingAs($verifier)->postJson('/_alfred/explain', ['token' => $this->loadDashboard($verifier), 'element' => $this->element()])->json('id');

        $this->actingAs($verifier)->putJson("/_alfred/explanations/{$id}", ['example' => '10 posted, 9 booked → 90%.', 'glossary' => ['Posted — offered to staff']])
            ->assertOk()->assertJson(['example' => '10 posted, 9 booked → 90%.', 'glossary' => ['Posted — offered to staff'], 'verified' => true]);
    }
}
