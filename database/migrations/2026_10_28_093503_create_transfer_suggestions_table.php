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
        // Candidate transfer pairs that transfer pairing could not decide on its own. An open row
        // (no `dismissed_at`) waits for the user to confirm or dismiss it; a dismissed row keeps the
        // two transactions from being suggested or paired automatically again.
        Schema::create('transfer_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outflow_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('inflow_id')->constrained('transactions')->cascadeOnDelete();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['outflow_id', 'inflow_id']);
            $table->index('inflow_id');
            $table->index(['user_id', 'dismissed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfer_suggestions');
    }
};
