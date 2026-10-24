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
        if (Schema::hasTable(config('alfred.changes_table', 'alfred_changes'))) {
            return; // already there (e.g. a tenant-wide migrate re-running it)
        }

        Schema::create(config('alfred.changes_table', 'alfred_changes'), function (Blueprint $table) {
            $table->id();
            $table->string('user_key');                  // who asked (u:<id>)
            $table->string('requested_by')->nullable();  // email/name for display
            $table->string('environment', 40);           // APP_ENV it was made in
            $table->string('route')->nullable();
            $table->text('url')->nullable();
            $table->json('element')->nullable();          // what was pointed at (labels, components, selector)
            $table->text('instruction');                  // what they asked for

            $table->string('summary')->nullable();
            $table->text('notes')->nullable();
            $table->json('edits')->nullable();            // [{path, search, replace}]
            $table->json('files')->nullable();            // path => {before_sha1, after_sha1}
            $table->longText('diff')->nullable();         // unified, git-apply compatible
            $table->json('backups')->nullable();          // path => original contents (for undo)

            $table->string('status', 16)->default('proposed'); // proposed | applied | reverted | discarded | failed
            $table->text('error')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);

            $table->string('applied_by')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->string('reverted_by')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();

            $table->index(['user_key', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('alfred.changes_table', 'alfred_changes'));
    }
};
