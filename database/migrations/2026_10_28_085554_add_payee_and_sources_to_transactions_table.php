<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('payee_id')->nullable()->after('category_id')->constrained()->nullOnDelete();

            // Who set the value: `manual` (the user; rules never overwrite it) or `rule`. Null while
            // the value is empty.
            $table->string('category_source')->nullable()->after('payee_id');
            $table->string('payee_source')->nullable()->after('category_source');
        });

        // Phase 1 categories were all set by hand.
        DB::table('transactions')->whereNotNull('category_id')->update(['category_source' => 'manual']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payee_id');
            $table->dropColumn(['category_source', 'payee_source']);
        });
    }
};
