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
        Schema::table('transactions', function (Blueprint $table) {
            // The subscription this charge belongs to.
            $table->foreignId('subscription_id')->nullable()->after('transfer_counterpart_id')
                ->constrained()->nullOnDelete();

            // Who decided `subscription_id`: `auto` (matching) or `manual` (the user). A manual
            // decision, including unlinking, is final: automatic linking never touches the row again.
            $table->string('subscription_source')->nullable()->after('subscription_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_id');
            $table->dropColumn('subscription_source');
        });
    }
};
