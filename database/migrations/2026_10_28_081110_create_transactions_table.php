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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->time('time')->nullable();
            $table->text('description');
            // Signed Rappen. `amount` is the net effect on the balance (file amount minus fee).
            $table->bigInteger('amount');
            $table->bigInteger('fee')->default(0);
            $table->bigInteger('balance_after')->nullable();
            $table->char('currency', 3)->default('CHF');
            $table->string('status');
            $table->string('fingerprint', 64)->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'fingerprint']);
            $table->index(['account_id', 'status', 'date']);
            $table->index(['user_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
