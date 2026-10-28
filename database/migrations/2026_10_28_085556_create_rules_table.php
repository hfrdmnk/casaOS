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
        // Categorization rules, applied in `position` order. `conditions` is a list of
        // {field, operator, value} that must all match; `actions` a list of {type, value}.
        Schema::create('rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->unsignedInteger('position');
            $table->boolean('enabled')->default(true);
            $table->jsonb('conditions');
            $table->jsonb('actions');
            $table->timestamps();

            $table->index(['user_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rules');
    }
};
