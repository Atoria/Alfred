<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Changes\Change;
use Atoria\Alfred\Preview\PreviewErrors;
use Atoria\Alfred\Tests\TestCase;
use Illuminate\Support\Facades\File;

/** "Not quite right": feedback on a change, and edits that would show markup as text. */
class RefineTest extends TestCase
{
    private string $sandbox;

    private const CONTROLLER = 'app/Http/Controllers/DashboardController.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir().'/alfred-refine-'.uniqid();
        File::copyDirectory(self::fixturePath(), $this->sandbox);
        config(['alfred.base_path' => $this->sandbox, 'alfred.edit.enabled' => true, 'alfred.edit.environments' => ['testing'], 'alfred.edit.mode' => 'write']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);
        parent::tearDown();
    }

    protected function read(): array
    {
        return ['read_file', ['path' => self::CONTROLLER, 'start_line' => null, 'end_line' => null]];
    }

    protected function edit(string $replace, string $summary = 'Highlight a 0% fill rate'): array
    {
        return ['propose_edit', ['summary' => $summary, 'notes' => null, 'edits' => [[
            'path' => self::CONTROLLER,
            'search' => "'fillRate' => \$total ? round(\$booked / \$total * 100) : 0,",
            'replace' => $replace,
        ]]]];
    }

    protected function propose($user, string $instruction = 'Make 0% red')
    {
        return $this->actingAs($user)->postJson('/_alfred/changes', ['token' => $this->loadDashboard($user), 'element' => $this->element(), 'instruction' => $instruction]);
    }

    public function test_markup_in_a_php_string_is_sent_back_once(): void
    {
        $verifier = $this->user('verifier@example.com');
        $html = "'fillRate' => \$total ? round(\$booked / \$total * 100) : '<span class=\"text-red-600\">0%</span>',";
        $this->llm->then([$this->read()])->then([$this->edit($html)])->then([$this->edit("'fillRate' => \$total ? round(\$booked / \$total * 100) : 0,\n            'fillRateTone' => \$total && \$booked ? 'success' : 'danger',")]);

        $this->propose($verifier)->assertCreated()->assertJsonPath('status', 'proposed');

        $feedback = $this->llm->toolOutputs()[1];
        $this->assertStringContainsString('this would show HTML tags as text', $feedback);
        $this->assertStringContainsString('<span class="text-red-600">0%</span>', $feedback);
        $this->assertStringContainsString('->descriptionColor(', $feedback);
        $this->assertStringContainsString('HtmlString', $feedback);
    }

    public function test_the_warning_is_given_once_then_the_agent_may_insist(): void
    {
        $verifier = $this->user('verifier@example.com');
        $html = "'fillRate' => \$total ? round(\$booked / \$total * 100) : '<b>0</b>',";
        $this->llm->then([$this->read()])->then([$this->edit($html)])->then([$this->edit($html)]);

        $this->propose($verifier)->assertCreated()->assertJsonPath('status', 'proposed');
    }

    public function test_feedback_on_a_change_gives_a_new_attempt_with_what_went_wrong(): void
    {
        config(['alfred.edit.mode' => 'propose']);
        $verifier = $this->user('verifier@example.com');
        $this->llm->then([$this->read()])->then([$this->edit("'fillRate' => \$total ? round(\$booked / \$total * 100) : -1,", 'Show -1 when empty')]);
        $first = $this->propose($verifier, 'Show something when there are no shifts')->json('id');
        PreviewErrors::put($first, 'ErrorException: Undefined variable $rate at app/Widgets/Stats.php:58');

        $this->actingAs($verifier)->getJson('/_alfred/changes/'.$first)
            ->assertJsonPath('preview_error', 'ErrorException: Undefined variable $rate at app/Widgets/Stats.php:58')
            ->assertJsonPath('can_refine', true);

        $this->llm->then([$this->read()])->then([$this->edit("'fillRate' => \$total ? round(\$booked / \$total * 100) : null,", 'Show a dash when empty')]);
        $second = $this->actingAs($verifier)->postJson("/_alfred/changes/{$first}/refine", [
            'token' => $this->loadDashboard($verifier), 'feedback' => 'Not -1, show a dash instead',
        ])->assertCreated()
            ->assertJsonPath('summary', 'Show a dash when empty')
            ->assertJsonPath('refines', $first)
            ->json();

        $prompt = end($this->llm->requests)['input'][0]['content'];
        $this->assertStringContainsString('# A previous attempt did not work', $prompt);
        $this->assertStringContainsString('It is NOT applied', $prompt);
        $this->assertStringContainsString('Not -1, show a dash instead', $prompt);
        $this->assertStringContainsString('Undefined variable $rate', $prompt, 'the preview error goes along');
        $this->assertStringContainsString(': -1,', $prompt, 'and the previous diff');
        $this->assertStringContainsString("Show something when there are no shifts\n\nThen: Not -1, show a dash instead", $prompt);

        $old = Change::find($first);
        $this->assertSame(Change::DISCARDED, $old->status);
        $this->assertStringContainsString('Replaced by change #'.$second['id'], $old->notes);

        // Refining the refinement keeps the original request, not a growing chain.
        $this->llm->then([$this->read()])->then([$this->edit("'fillRate' => \$total ? round(\$booked / \$total * 100) : '',", 'Show nothing when empty')]);
        $this->actingAs($verifier)->postJson("/_alfred/changes/{$second['id']}/refine", ['token' => $this->loadDashboard($verifier), 'feedback' => 'Actually show nothing'])->assertCreated();
        $this->assertSame("Show something when there are no shifts\n\nThen: Actually show nothing", Change::query()->latest('id')->value('instruction'));
    }

    public function test_an_applied_change_is_refined_on_top_of_itself(): void
    {
        $verifier = $this->user('verifier@example.com');
        $this->llm->then([$this->read()])->then([$this->edit("'fillRate' => \$total ? round(\$booked / \$total * 100) : -1,")]);
        $first = $this->propose($verifier)->json('id');
        $this->actingAs($verifier)->postJson("/_alfred/changes/{$first}/apply")->assertOk();

        $this->llm->then([$this->read()])->then([['propose_edit', ['summary' => 'Use null', 'notes' => null, 'edits' => [[
            'path' => self::CONTROLLER, 'search' => ': -1,', 'replace' => ': null,',
        ]]]]]);
        $this->actingAs($verifier)->postJson("/_alfred/changes/{$first}/refine", ['token' => $this->loadDashboard($verifier), 'feedback' => 'Use null instead'])->assertCreated();

        $this->assertStringContainsString('It IS applied', end($this->llm->requests)['input'][0]['content']);
        $this->assertSame(Change::APPLIED, Change::find($first)->status, 'an applied change stays applied (undo is separate)');
    }

    public function test_who_and_what_can_be_refined(): void
    {
        $verifier = $this->user('verifier@example.com');
        $request = $this->actingAs($verifier)->postJson('/_alfred/requests', ['token' => $this->loadDashboard($verifier), 'element' => $this->element(), 'text' => 'Please add a filter'])->json('id');
        $this->actingAs($verifier)->postJson("/_alfred/changes/{$request}/refine", ['token' => $this->loadDashboard($verifier), 'feedback' => 'more'])->assertStatus(409);

        $user = $this->user('someone@example.com'); // not a verifier: can't edit
        $this->actingAs($user)->postJson("/_alfred/changes/{$request}/refine", ['token' => $this->loadDashboard($user), 'feedback' => 'more'])->assertForbidden();
    }
}
