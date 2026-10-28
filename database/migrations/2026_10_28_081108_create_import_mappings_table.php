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
        Schema::create('import_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            // The file shape this mapping matches: decoded with `encoding`, `skip_lines` preamble
            // lines dropped, then the next row split on `delimiter` must equal `header` exactly.
            $table->string('signature', 64);
            $table->string('encoding');
            $table->string('delimiter', 1);
            $table->unsignedSmallInteger('skip_lines')->default(0);
            $table->json('header');

            // Column positions (0-based) into `header`.
            $table->unsignedSmallInteger('date_column');
            $table->string('date_format');
            $table->unsignedSmallInteger('description_column');
            $table->string('amount_mode');
            $table->unsignedSmallInteger('amount_column')->nullable();
            $table->unsignedSmallInteger('debit_column')->nullable();
            $table->unsignedSmallInteger('credit_column')->nullable();
            $table->boolean('invert_sign')->default(false);
            $table->string('decimal_separator', 1);
            $table->string('thousands_separator', 1)->nullable();
            $table->unsignedSmallInteger('fee_column')->nullable();
            $table->unsignedSmallInteger('balance_column')->nullable();
            $table->unsignedSmallInteger('currency_column')->nullable();
            $table->unsignedSmallInteger('status_column')->nullable();
            $table->json('status_map')->nullable();
            $table->unsignedSmallInteger('filter_column')->nullable();
            $table->string('filter_value')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'signature']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_mappings');
    }
};
