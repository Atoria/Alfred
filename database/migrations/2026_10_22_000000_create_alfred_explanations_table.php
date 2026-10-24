<?php

use Atoria\Alfred\Alfred;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Alfred::connection() ?? parent::getConnection();
    }

    public function up(): void
    {
        if (Schema::hasTable(config('alfred.table', 'alfred_explanations'))) {
            return; // already there (e.g. a tenant-wide migrate re-running it)
        }

        Schema::create(config('alfred.table', 'alfred_explanations'), function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();          // stable identity of "this thing on this page"
            $table->string('route')->nullable();           // route name or URI pattern
            $table->string('component')->nullable();       // innermost Livewire component, if any
            $table->string('label')->nullable();           // human label seen when generated (digits masked)

            $table->string('title');
            $table->text('summary');
            $table->text('formula')->nullable();
            $table->json('steps')->nullable();
            $table->json('data_sources')->nullable();
            $table->json('caveats')->nullable();
            $table->json('technical_notes')->nullable();   // file:line refs — verifiers only
            $table->string('confidence', 16)->default('medium');

            $table->text('query_sql')->nullable();         // SQL template behind the value, for drill-down
            $table->string('query_connection')->nullable();
            $table->string('query_source')->nullable();    // file:line that ran it

            $table->json('files')->nullable();             // path => sha1 of every file the agent read
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);

            $table->string('status', 16)->default('generated'); // generated | verified
            $table->boolean('edited')->default(false);
            $table->string('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('alfred.table', 'alfred_explanations'));
    }
};
