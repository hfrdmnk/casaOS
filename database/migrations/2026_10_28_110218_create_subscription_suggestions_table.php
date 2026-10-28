<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // A group of regular charges that detection found. `match_key` identifies the group
        // (`payee:<id>` or `description:<normalized description>`) and, with the interval, what a
        // dismissal applies to: a dismissed row (with `dismissed_at`) is never suggested again.
        // Open rows are rebuilt on every detection run.
        Schema::create('subscription_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('match_key');
            $table->string('name');
            $table->foreignId('payee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description_match')->nullable();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount');
            $table->string('interval');
            $table->date('next_date');
            $table->json('transaction_ids');
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'match_key', 'interval']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_suggestions');
    }
};
