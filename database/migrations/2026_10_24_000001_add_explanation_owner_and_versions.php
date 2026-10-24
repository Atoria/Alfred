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
        $explanations = config('alfred.table', 'alfred_explanations');
        if (! Schema::hasColumn($explanations, 'owner')) {
            Schema::table($explanations, function (Blueprint $t) {
                // Set for explanations only their author sees (not allowed to share); null = shared.
                $t->string('owner', 191)->nullable()->index();
            });
        }

        $versions = config('alfred.versions_table', 'alfred_explanation_versions');
        if (! Schema::hasTable($versions)) {
            Schema::create($versions, function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('explanation_id')->index();
                $t->string('tenant', 191)->nullable()->index();
                $t->unsignedInteger('version');
                $t->string('action', 20); // generated | regenerated | edited | verified | kept | restored
                $t->string('by')->nullable();
                $t->json('snapshot');
                $t->timestamp('created_at')->nullable();
                $t->unique(['explanation_id', 'version']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(config('alfred.versions_table', 'alfred_explanation_versions'));
        $explanations = config('alfred.table', 'alfred_explanations');
        if (Schema::hasColumn($explanations, 'owner')) {
            Schema::table($explanations, function (Blueprint $t) {
                $t->dropIndex(['owner']);
                $t->dropColumn('owner');
            });
        }
    }
};
