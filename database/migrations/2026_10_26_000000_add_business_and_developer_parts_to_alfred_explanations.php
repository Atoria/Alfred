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
        if (Schema::hasColumn(config('alfred.table', 'alfred_explanations'), 'example')) {
            return; // already there (e.g. a tenant-wide migrate re-running it)
        }

        Schema::table(config('alfred.table', 'alfred_explanations'), function (Blueprint $table) {
            $table->text('example')->nullable()->after('formula');      // worked example with simple numbers
            $table->json('glossary')->nullable()->after('caveats');     // "Term — meaning"
            $table->json('developer')->nullable()->after('technical_notes'); // summary, flow, queries, caching, gotchas
            $table->unsignedSmallInteger('format')->default(1)->after('developer'); // 2 = business + developer parts
        });
    }

    public function down(): void
    {
        Schema::table(config('alfred.table', 'alfred_explanations'), function (Blueprint $table) {
            $table->dropColumn(['example', 'glossary', 'developer', 'format']);
        });
    }
};
