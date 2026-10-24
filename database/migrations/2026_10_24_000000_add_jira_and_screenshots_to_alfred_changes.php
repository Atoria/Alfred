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
        if (Schema::hasColumn(config('alfred.changes_table', 'alfred_changes'), 'screenshots')) {
            return; // already there (e.g. a tenant-wide migrate re-running it)
        }

        Schema::table(config('alfred.changes_table', 'alfred_changes'), function (Blueprint $table) {
            $table->json('screenshots')->nullable()->after('backups'); // kind => storage path
            $table->string('jira_key')->nullable()->after('screenshots');
            $table->string('jira_url')->nullable()->after('jira_key');
            $table->string('ticketed_by')->nullable()->after('jira_url');
            $table->timestamp('ticketed_at')->nullable()->after('ticketed_by');
        });
    }

    public function down(): void
    {
        Schema::table(config('alfred.changes_table', 'alfred_changes'), function (Blueprint $table) {
            $table->dropColumn(['screenshots', 'jira_key', 'jira_url', 'ticketed_by', 'ticketed_at']);
        });
    }
};
