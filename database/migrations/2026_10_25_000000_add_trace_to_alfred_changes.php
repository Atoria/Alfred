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
        if (Schema::hasColumn(config('alfred.changes_table', 'alfred_changes'), 'trace')) {
            return; // already there (e.g. a tenant-wide migrate re-running it)
        }

        Schema::table(config('alfred.changes_table', 'alfred_changes'), function (Blueprint $table) {
            $table->json('trace')->nullable()->after('diff'); // what the agent tried (tool calls + results), for debugging
        });
    }

    public function down(): void
    {
        Schema::table(config('alfred.changes_table', 'alfred_changes'), function (Blueprint $table) {
            $table->dropColumn('trace');
        });
    }
};
