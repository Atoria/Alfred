<?php

namespace Atoria\Alfred\Tests\Feature;

use Atoria\Alfred\Context\ComponentResolver;
use Atoria\Alfred\Context\ContextStore;
use Atoria\Alfred\Tests\Fixtures\App\Livewire\FillRate;
use Atoria\Alfred\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;

class LivewireTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);
        Route::middleware('web')->get('/widgets', fn () => Blade::render('<html><body><livewire:fill-rate /></body></html>'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Livewire::component('fill-rate', FillRate::class);
    }

    public function test_records_components_and_attributes_their_queries(): void
    {
        $user = $this->user();
        $html = $this->actingAs($user)->get('/widgets')->assertOk()->getContent();

        $this->assertStringContainsString('wire:snapshot', $html);
        preg_match('/data-alfred="([^"]+)"/', $html, $m);
        $page = app(ContextStore::class)->get(json_decode(html_entity_decode($m[1]), true)['token']);

        $this->assertSame(FillRate::class, $page->components['fill-rate']);
        $sources = array_column($page->queryList(), 'source');
        $this->assertContains('app/Livewire/FillRate.php:12', $sources);
        $this->assertSame('fill-rate', $page->queryList()[0]['component']);

        // The DOM name resolves back to the class + file the agent should read.
        $resolver = new ComponentResolver;
        $this->assertSame(FillRate::class, $resolver->resolve('fill-rate', $page));
        $this->assertStringEndsWith('app/Livewire/FillRate.php', $resolver->file(FillRate::class));
    }

    public function test_prompt_points_the_agent_at_the_component_file(): void
    {
        $user = $this->user();
        $html = $this->actingAs($user)->get('/widgets')->getContent();
        preg_match('/data-alfred="([^"]+)"/', $html, $m);
        $token = json_decode(html_entity_decode($m[1]), true)['token'];

        $this->llm->submit(['query_index' => null]);
        $this->actingAs($user)->postJson('/_alfred/explain', ['token' => $token, 'element' => $this->element(['components' => ['fill-rate'], 'labels' => ['Booked shifts']])])->assertOk();

        $prompt = $this->llm->requests[0]['input'][0]['content'];
        $this->assertStringContainsString('fill-rate => '.FillRate::class.' (app/Livewire/FillRate.php)', $prompt);
    }
}
