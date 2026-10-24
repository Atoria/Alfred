<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return \Atoria\Alfred\Alfred::connection() ?? parent::getConnection();
    }

    public function up(): void
    {
        if (Schema::hasColumn(config('alfred.changes_table', 'alfred_changes'), 'location')) {
            return; // already there (e.g. a tenant-wide migrate re-running it)
        }

        Schema::table(config('alfred.changes_table', 'alfred_changes'), function (Blueprint $table) {
            // where the clicked element lives in the code: route, controller, components, views, query sources
            $table->json('location')->nullable()->after('element');
        });
    }

    public function down(): void
    {
        Schema::table(config('alfred.changes_table', 'alfred_changes'), function (Blueprint $table) {
            $table->dropColumn('location');
        });
    }
};
