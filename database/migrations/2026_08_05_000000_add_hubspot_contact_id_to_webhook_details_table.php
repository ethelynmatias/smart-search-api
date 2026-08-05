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
        Schema::table('webhook_details', function (Blueprint $table) {
            // Held as its own column rather than read back off the payload,
            // which the completion callback overwrites with its own body.
            $table->string('hubspot_contact_id')->nullable()->index()->after('deal_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webhook_details', function (Blueprint $table) {
            $table->dropColumn('hubspot_contact_id');
        });
    }
};
