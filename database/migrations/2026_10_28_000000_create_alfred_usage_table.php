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
        $name = config('alfred.usage_table', 'alfred_usage');
        if (Schema::hasTable($name)) {
            return;
        }

        Schema::create($name, function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('tenant', 191)->default(''); // '' = no tenant (unique keys treat NULLs as distinct)
            $table->string('user_key', 191);
            $table->string('kind', 32); // explanation | change | follow-up
            $table->string('model', 100)->default('');
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->timestamps();

            $table->unique(['day', 'tenant', 'user_key', 'kind', 'model'], 'alfred_usage_bucket');
            $table->index(['day', 'user_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('alfred.usage_table', 'alfred_usage'));
    }
};
