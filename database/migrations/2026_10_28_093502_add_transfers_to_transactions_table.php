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
            // A transfer moves money between two of the user's accounts (or to/from one the app does
            // not track) and is neither income nor spending.
            $table->boolean('is_transfer')->default(false)->after('payee_source');

            // Who decided `is_transfer`: `manual` (the user; nothing automatic touches the row again),
            // `rule` or `auto` (transfer pairing). Null while nobody has.
            $table->string('transfer_source')->nullable()->after('is_transfer');

            // The other side of the pair; both sides point at each other. Null for a transfer to or
            // from an account the app does not track.
            $table->foreignId('transfer_counterpart_id')->nullable()->after('transfer_source')
                ->constrained('transactions')->nullOnDelete();

            $table->index(['user_id', 'is_transfer']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_transfer']);
            $table->dropConstrainedForeignId('transfer_counterpart_id');
            $table->dropColumn(['is_transfer', 'transfer_source']);
        });
    }
};
