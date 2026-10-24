<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Tests\TestCase;

class DrilldownTest extends TestCase
{
    protected function explained($user): array
    {
        $token = $this->loadDashboard($user);
        $this->llm->submit(['query_index' => 0]); // the booked count
        $id = $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element()])->assertOk()->json('id');

        return [$token, $id];
    }

    public function test_shows_the_rows_behind_a_count_with_this_pages_bindings(): void
    {
        $verifier = $this->user('verifier@example.com');
        [$token, $id] = $this->explained($verifier);

        $res = $this->actingAs($verifier)->postJson("/_alfred/explanations/{$id}/records", ['token' => $token])->assertOk();

        $this->assertTrue($res->json('aggregate'));
        $this->assertSame(['A', 'B'], array_column($res->json('rows'), 'ward'));
        $this->assertSame('••••', $res->json('rows.0.secret_token'), 'sensitive-looking columns are masked');
        $this->assertStringContainsString('select *', $res->json('sql'));
    }

    public function test_records_are_verifier_only_by_default(): void
    {
        $user = $this->user();
        [$token, $id] = $this->explained($user);

        $this->actingAs($user)->postJson("/_alfred/explanations/{$id}/records", ['token' => $token])->assertForbidden();
    }

    public function test_apps_can_open_records_up_and_non_verifiers_do_not_see_sql(): void
    {
        \Gate::define('viewAlfredRecords', fn ($user = null) => $user !== null);
        $user = $this->user();
        [$token, $id] = $this->explained($user);

        $this->actingAs($user)->postJson("/_alfred/explanations/{$id}/records", ['token' => $token])
            ->assertOk()->assertJsonMissingPath('sql')->assertJsonCount(2, 'rows');
    }

    public function test_needs_the_users_own_page(): void
    {
        $verifier = $this->user('verifier@example.com');
        [, $id] = $this->explained($verifier);

        $otherToken = $this->loadDashboard($this->user('other@example.com'));
        $this->actingAs($verifier)->postJson("/_alfred/explanations/{$id}/records", ['token' => $otherToken])->assertStatus(410);
    }
}
