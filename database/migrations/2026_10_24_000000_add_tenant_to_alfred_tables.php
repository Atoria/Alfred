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
        foreach ([config('alfred.table', 'alfred_explanations'), config('alfred.changes_table', 'alfred_changes')] as $table) {
            if (! Schema::hasColumn($table, 'tenant')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string('tenant', 191)->nullable()->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ([config('alfred.table', 'alfred_explanations'), config('alfred.changes_table', 'alfred_changes')] as $table) {
            if (Schema::hasColumn($table, 'tenant')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropIndex(['tenant']);
                    $t->dropColumn('tenant');
                });
            }
        }
    }
};
