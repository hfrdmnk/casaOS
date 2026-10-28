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
        // A recurring payment. It matches its charges by payee (preferred) or by a description
        // match, optionally limited to one account; `amount` is the expected charge as positive
        // Rappen and `next_date` the day the next charge is expected.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('payee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description_match')->nullable();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount');
            $table->string('interval');
            $table->date('next_date');
            $table->string('status')->default('active');
            $table->string('source');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
