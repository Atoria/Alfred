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
        if (Schema::hasColumn(config('alfred.changes_table', 'alfred_changes'), 'pr_provider')) {
            return; // already there (e.g. a tenant-wide migrate re-running it)
        }

        Schema::table(config('alfred.changes_table', 'alfred_changes'), function (Blueprint $t) {
            $t->string('pr_provider', 40)->nullable();
            $t->string('pr_key')->nullable();
            $t->string('pr_url', 2048)->nullable();
            $t->string('pr_branch')->nullable();
            $t->string('pr_opened_by')->nullable();
            $t->timestamp('pr_opened_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table(config('alfred.changes_table', 'alfred_changes'), function (Blueprint $t) {
            $t->dropColumn(['pr_provider', 'pr_key', 'pr_url', 'pr_branch', 'pr_opened_by', 'pr_opened_at']);
        });
    }
};
