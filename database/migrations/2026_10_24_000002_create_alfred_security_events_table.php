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
        $table = config('alfred.security_events_table', 'alfred_security_events');
        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $t) {
            $t->id();
            $t->string('tenant', 191)->nullable()->index();
            $t->string('user_key', 191)->index();
            $t->string('user_label')->nullable();
            $t->string('category', 40);          // instructions | secrets | other_tenant | other_person
            $t->string('where', 40);             // follow-up | change | refine | …
            $t->text('excerpt');                 // what they typed (shortened)
            $t->string('matched', 200)->nullable();
            $t->string('route')->nullable();
            $t->string('ip_hash', 64)->nullable();
            $t->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('alfred.security_events_table', 'alfred_security_events'));
    }
};
