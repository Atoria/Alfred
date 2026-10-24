<?php

use Atoria\Alfred\Alfred;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Alfred::connection() ?? parent::getConnection();
    }

    public function up(): void
    {
        if (Schema::hasColumn(config('alfred.changes_table', 'alfred_changes'), 'ticket_provider')) {
            return; // already there (e.g. a tenant-wide migrate re-running it)
        }

        $table = config('alfred.changes_table', 'alfred_changes');

        Schema::table($table, function (Blueprint $t) {
            $t->string('ticket_provider', 40)->nullable();
            $t->string('ticket_key')->nullable();
            $t->string('ticket_url', 2048)->nullable();
        });

        // Tickets made before trackers existed were always Jira.
        DB::table($table)->whereNotNull('jira_key')->update([
            'ticket_provider' => 'jira',
            'ticket_key' => DB::raw('jira_key'),
            'ticket_url' => DB::raw('jira_url'),
        ]);
    }

    public function down(): void
    {
        Schema::table(config('alfred.changes_table', 'alfred_changes'), function (Blueprint $t) {
            $t->dropColumn(['ticket_provider', 'ticket_key', 'ticket_url']);
        });
    }
};
