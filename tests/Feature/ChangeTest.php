<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Changes\Change;
use Atoria\Alfred\Tests\TestCase;
use Illuminate\Support\Facades\File;

class ChangeTest extends TestCase
{
    private string $sandbox;

    private const VIEW = 'resources/views/dashboard.blade.php';

    protected function setUp(): void
    {
        parent::setUp();

        // Work on a throwaway copy of the fixture app: these tests write files.
        $this->sandbox = sys_get_temp_dir().'/alfred-sandbox-'.uniqid();
        File::copyDirectory(self::fixturePath(), $this->sandbox);
        config()->set('alfred.base_path', $this->sandbox);
        config()->set('alfred.edit.enabled', true);
        config()->set('alfred.edit.environments', ['testing']);
        config()->set('alfred.edit.mode', 'write');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);
        parent::tearDown();
    }

    protected function file(string $path): string
    {
        return file_get_contents($this->sandbox.'/'.$path);
    }

    protected function proposeLeftAlign(): static
    {
        $this->llm
            ->then([['read_file', ['path' => self::VIEW, 'start_line' => null, 'end_line' => null]]])
            ->then([['propose_edit', [
                'summary' => 'Left-align the fill rate',
                'notes' => null,
                'edits' => [['path' => self::VIEW, 'search' => '<p data-explain="fill-rate">', 'replace' => '<p data-explain="fill-rate" class="text-left">']],
            ]]]);

        return $this;
    }

    protected function requestChange($user, string $instruction = 'move it a bit to the left')
    {
        return $this->actingAs($user)->postJson('/_alfred/changes', [
            'token' => $this->loadDashboard($user),
            'element' => $this->element(),
            'instruction' => $instruction,
        ]);
    }

    public function test_proposes_a_diff_without_touching_files(): void
    {
        $verifier = $this->user('verifier@example.com');
        $before = $this->file(self::VIEW);
        $this->proposeLeftAlign();

        $res = $this->requestChange($verifier)->assertCreated()->assertJson([
            'status' => 'proposed',
            'summary' => 'Left-align the fill rate',
            'files' => [self::VIEW],
            'has_edits' => true,
            'can_apply' => true,
            'mode' => 'write',
        ]);

        $this->assertSame($before, $this->file(self::VIEW), 'proposing must not write');
        $this->assertStringContainsString("-        <p data-explain=\"fill-rate\">{{ \$fillRate }}%</p>\n+        <p data-explain=\"fill-rate\" class=\"text-left\">{{ \$fillRate }}%</p>", $res->json('diff'));
        $this->assertStringContainsString('# Requested change'."\n".'move it a bit to the left', $this->llm->requests[0]['input'][0]['content']);

        // The patch applies cleanly with git.
        $repo = $this->sandbox.'-git';
        File::copyDirectory($this->sandbox, $repo);
        file_put_contents($repo.'/p.diff', $res->json('diff'));
        exec('cd '.escapeshellarg($repo).' && git apply --check p.diff 2>&1', $out, $code);
        File::deleteDirectory($repo);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function test_apply_then_undo(): void
    {
        $verifier = $this->user('verifier@example.com');
        $original = $this->file(self::VIEW);
        $id = $this->proposeLeftAlign()->requestChange($verifier)->json('id');

        $this->actingAs($verifier)->postJson("/_alfred/changes/{$id}/apply")
            ->assertOk()->assertJson(['status' => 'applied', 'can_revert' => true, 'applied_by' => 'verifier@example.com']);
        $this->assertStringContainsString('class="text-left"', $this->file(self::VIEW));

        $this->actingAs($verifier)->postJson("/_alfred/changes/{$id}/apply")->assertStatus(409);

        $this->actingAs($verifier)->postJson("/_alfred/changes/{$id}/revert")->assertOk()->assertJson(['status' => 'reverted', 'can_apply' => true]);
        $this->assertSame($original, $this->file(self::VIEW));

        // and it can be applied again
        $this->actingAs($verifier)->postJson("/_alfred/changes/{$id}/apply")->assertOk();
        $this->assertStringContainsString('class="text-left"', $this->file(self::VIEW));
    }

    public function test_refuses_to_apply_over_a_file_that_changed_since_the_proposal(): void
    {
        $verifier = $this->user('verifier@example.com');
        $id = $this->proposeLeftAlign()->requestChange($verifier)->json('id');

        file_put_contents($this->sandbox.'/'.self::VIEW, $this->file(self::VIEW)."\n<!-- someone else -->\n");

        $this->actingAs($verifier)->postJson("/_alfred/changes/{$id}/apply")
            ->assertStatus(409)->assertJsonPath('message', self::VIEW.' has changed since this was proposed. Ask Alfred again to get a fresh change.');
        $this->assertStringNotContainsString('text-left', $this->file(self::VIEW));
    }

    public function test_refuses_to_undo_over_later_edits(): void
    {
        $verifier = $this->user('verifier@example.com');
        $id = $this->proposeLeftAlign()->requestChange($verifier)->json('id');
        $this->actingAs($verifier)->postJson("/_alfred/changes/{$id}/apply")->assertOk();

        $edited = $this->file(self::VIEW)."\n<!-- edited after -->\n";
        file_put_contents($this->sandbox.'/'.self::VIEW, $edited);

        $this->actingAs($verifier)->postJson("/_alfred/changes/{$id}/revert")->assertStatus(409);
        $this->assertSame($edited, $this->file(self::VIEW), 'the later edit is kept');
    }

    public function test_bad_edits_go_back_to_the_agent_until_they_are_valid(): void
    {
        $verifier = $this->user('verifier@example.com');
        $this->llm
            // 1. edits a file it has not read
            ->then([['propose_edit', ['summary' => 'x', 'notes' => null, 'edits' => [['path' => self::VIEW, 'search' => '<p', 'replace' => '<p class="a"']]]]])
            // 2. reads it, then copies the line-number prefix, and also tries .env and config
            ->then([['read_file', ['path' => self::VIEW, 'start_line' => null, 'end_line' => null]]])
            ->then([['propose_edit', ['summary' => 'x', 'notes' => null, 'edits' => [
                ['path' => self::VIEW, 'search' => '    7          <p data-explain="fill-rate">', 'replace' => 'x'],
                ['path' => '.env', 'search' => 'A', 'replace' => 'B'],
                ['path' => 'config/services.php', 'search' => 'hardcoded', 'replace' => 'x'],
            ]]]])
            // 3. ambiguous search text
            ->then([['propose_edit', ['summary' => 'x', 'notes' => null, 'edits' => [['path' => self::VIEW, 'search' => '<', 'replace' => '«']]]]])
            // 4. valid
            ->then([['propose_edit', ['summary' => 'Left-align', 'notes' => 'Needs the Tailwind build running.', 'edits' => [['path' => self::VIEW, 'search' => '<p data-explain="fill-rate">', 'replace' => '<p data-explain="fill-rate" class="text-left">']]]]]);

        $this->requestChange($verifier)->assertCreated()->assertJson(['summary' => 'Left-align', 'notes' => 'Needs the Tailwind build running.']);

        [$notRead, , $prefixAndForbidden, $ambiguous] = $this->llm->toolOutputs();
        $this->assertStringContainsString('read resources/views/dashboard.blade.php with read_file before editing it', $notRead);
        $this->assertStringNotContainsString('Edit 1', $prefixAndForbidden, 'copied line-number prefixes are stripped automatically');
        $this->assertStringContainsString('.env cannot be changed', $prefixAndForbidden);
        $this->assertStringContainsString('config/services.php cannot be changed', $prefixAndForbidden);
        $this->assertStringContainsString('occurs', $ambiguous);
    }

    /** A real-world failure: the right idea, with the indentation copied wrong. */
    public function test_badly_indented_edits_are_placed_and_reindented(): void
    {
        $path = 'app/Filament/Resources/PlanPrices/PlanPriceResource.php';
        File::ensureDirectoryExists(dirname($this->sandbox.'/'.$path));
        file_put_contents($this->sandbox.'/'.$path, <<<'PHP'
<?php

namespace App\Filament\Resources\PlanPrices;

class PlanPriceResource
{
    public static function table($table)
    {
        return $table
            ->columns([
                TextColumn::make('source')
                    ->label('Region')
                    ->badge()
                    ->sortable(),
                TextColumn::make('monthlyPlan.name')
                    ->label('Monthly price')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('yearlyPlan.name')
                    ->label('Yearly price')
                    ->sortable()
                    ->searchable(),
            ]);
    }
}

PHP);
        $verifier = $this->user('verifier@example.com');
        $this->llm
            ->then([['read_file', ['path' => $path, 'start_line' => null, 'end_line' => null]]])
            // 1. ambiguous: these two lines appear under both grade columns
            ->then([['propose_edit', ['summary' => 'x', 'notes' => null, 'edits' => [['path' => $path, 'search' => "->sortable()\n->searchable(),", 'replace' => "->badge()\n->sortable()\n->searchable(),"]]]]])
            // 2. right lines, wrong indentation (what gpt-5-mini kept doing)
            ->then([['propose_edit', ['summary' => 'Show Monthly price as badges', 'notes' => null, 'edits' => [[
                'path' => $path,
                'search' => "TextColumn::make('monthlyPlan.name')\n    ->label('Monthly price')",
                'replace' => "TextColumn::make('monthlyPlan.name')\n    ->label('Monthly price')\n    ->badge()",
            ]]]]]);

        $res = $this->requestChange($verifier, 'Could you make Monthly price column in badges please?')->assertCreated()->assertJson(['has_edits' => true, 'status' => 'proposed']);

        $this->assertStringContainsString('matches line 17, line 21', $this->llm->toolOutputs()[1]);
        $this->assertStringContainsString("                     ->label('Monthly price')\n+                    ->badge()\n", $res->json('diff'));
        $this->assertSame('medium', end($this->llm->requests)['effort'], 'edits use the edit reasoning effort');
        $this->assertCount(3, $res->json('trace'));

        $id = $res->json('id');
        $this->actingAs($verifier)->postJson("/_alfred/changes/{$id}/apply")->assertOk();
        $this->assertStringContainsString("->label('Monthly price')\n                    ->badge()\n                    ->sortable()", $this->file($path));
    }

    public function test_a_run_that_never_produces_an_edit_is_recorded_as_failed_with_its_trace(): void
    {
        config()->set('alfred.edit.max_steps', 2);
        $verifier = $this->user('verifier@example.com');
        $bad = [['propose_edit', ['summary' => 'x', 'notes' => null, 'edits' => [['path' => self::VIEW, 'search' => 'nope', 'replace' => 'x']]]]];
        $this->llm->then([['read_file', ['path' => self::VIEW, 'start_line' => null, 'end_line' => null]]])
            ->then($bad)->then($bad)->then($bad)->then($bad);

        $res = $this->requestChange($verifier)->assertCreated()->assertJson(['status' => 'failed', 'has_edits' => false, 'can_apply' => false]);

        $this->assertSame('The agent did not produce a usable result.', $res->json('error'));
        $results = implode("\n", array_column($res->json('trace'), 'result'));
        $this->assertStringContainsString('the search text was not found', $results);
        $this->assertSame('propose_edit', $res->json('trace.1.tool'));
        $this->assertSame(Change::FAILED, Change::find($res->json('id'))->status);
    }

    public function test_whole_file_searches_are_rejected(): void
    {
        config()->set('alfred.edit.max_search_lines', 5);
        $verifier = $this->user('verifier@example.com');
        $whole = $this->file(self::VIEW);
        $this->llm
            ->then([['read_file', ['path' => self::VIEW, 'start_line' => null, 'end_line' => null]]])
            ->then([['propose_edit', ['summary' => 'x', 'notes' => null, 'edits' => [['path' => self::VIEW, 'search' => $whole, 'replace' => str_replace('<p data-explain', '<p class="x" data-explain', $whole)]]]]])
            ->then([['propose_edit', ['summary' => 'Left-align', 'notes' => null, 'edits' => [['path' => self::VIEW, 'search' => '<p data-explain="fill-rate">', 'replace' => '<p data-explain="fill-rate" class="text-left">']]]]]);

        $this->requestChange($verifier)->assertCreated();
        $this->assertStringContainsString('Target only the few lines you change', $this->llm->toolOutputs()[1]);
    }

    public function test_edits_that_break_php_are_rejected(): void
    {
        $verifier = $this->user('verifier@example.com');
        $this->llm
            ->then([['read_file', ['path' => 'app/Models/Shift.php', 'start_line' => null, 'end_line' => null]]])
            ->then([['propose_edit', ['summary' => 'x', 'notes' => null, 'edits' => [['path' => 'app/Models/Shift.php', 'search' => 'protected $guarded = [];', 'replace' => 'protected $guarded = [']]]]])
            ->then([['propose_edit', ['summary' => 'No change', 'notes' => 'This is not a UI change.', 'edits' => []]]]);

        $this->requestChange($verifier, 'change how fill rate is calculated')
            ->assertCreated()->assertJson(['has_edits' => false, 'can_apply' => false, 'notes' => 'This is not a UI change.']);

        $this->assertStringContainsString('would not parse', $this->llm->toolOutputs()[1]);
    }

    public function test_propose_mode_never_writes_but_offers_a_patch(): void
    {
        config()->set('alfred.edit.mode', 'propose');
        $verifier = $this->user('verifier@example.com');
        $res = $this->proposeLeftAlign()->requestChange($verifier)->assertJson(['mode' => 'propose', 'can_apply' => false]);
        $id = $res->json('id');

        $this->actingAs($verifier)->postJson("/_alfred/changes/{$id}/apply")->assertStatus(409);
        $this->assertStringNotContainsString('text-left', $this->file(self::VIEW));

        $patch = $this->actingAs($verifier)->get("/_alfred/changes/{$id}/patch")->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="alfred-change-'.$id.'.patch"');
        $this->assertStringStartsWith('diff --git a/'.self::VIEW, $patch->getContent());
    }

    public function test_auto_mode_writes_only_in_local(): void
    {
        config()->set('alfred.edit.mode', null);
        $this->assertSame('propose', app(\Atoria\Alfred\Alfred::class)->editMode());

        $this->app['env'] = 'local';
        $this->assertSame('write', app(\Atoria\Alfred\Alfred::class)->editMode());
    }

    public function test_discard(): void
    {
        $verifier = $this->user('verifier@example.com');
        $id = $this->proposeLeftAlign()->requestChange($verifier)->json('id');

        $this->actingAs($verifier)->postJson("/_alfred/changes/{$id}/discard")->assertOk()->assertJson(['status' => 'discarded', 'can_apply' => false]);
        $this->actingAs($verifier)->postJson("/_alfred/changes/{$id}/apply")->assertStatus(409);
    }

    public function test_history_lists_recent_changes(): void
    {
        $verifier = $this->user('verifier@example.com');
        $this->proposeLeftAlign()->requestChange($verifier);

        $this->actingAs($verifier)->getJson('/_alfred/changes')->assertOk()
            ->assertJsonPath('changes.0.summary', 'Left-align the fill rate')
            ->assertJsonPath('changes.0.requested_by', 'verifier@example.com');
    }

    public function test_off_by_default_and_gated(): void
    {
        $verifier = $this->user('verifier@example.com');

        config()->set('alfred.edit.enabled', false);
        $this->requestChange($verifier)->assertForbidden();
        $this->assertStringContainsString('&quot;canEdit&quot;:false', $this->actingAs($verifier)->get('/dashboard')->getContent());

        config()->set('alfred.edit.enabled', true);
        config()->set('alfred.edit.environments', ['local', 'staging']);
        $this->requestChange($verifier)->assertForbidden(); // we are in "testing"

        config()->set('alfred.edit.environments', ['staging', 'testing']);
        $this->requestChange($this->user())->assertForbidden(); // not a verifier
        $this->assertStringContainsString('&quot;canEdit&quot;:true', $this->actingAs($verifier)->get('/dashboard')->getContent());
    }

    public function test_custom_edit_gate(): void
    {
        \Gate::define('editAlfred', fn ($user = null) => $user?->email === 'designer@example.com');
        $this->proposeLeftAlign();

        $this->requestChange($this->user('verifier@example.com'))->assertForbidden();
        $this->requestChange($this->user('designer@example.com'))->assertCreated();
    }

    public function test_changes_are_rate_limited(): void
    {
        config()->set('alfred.rate_limit', 1);
        $verifier = $this->user('verifier@example.com');
        $this->proposeLeftAlign()->requestChange($verifier)->assertCreated();

        $this->requestChange($verifier)->assertStatus(429);
    }
}
