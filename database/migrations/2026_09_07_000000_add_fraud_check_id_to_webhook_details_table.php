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
            // The fraud check runs alongside the search this row waits on, so
            // its id sits next to the ssid rather than in a row of its own.
            $table->string('fraud_check_id')->nullable()->index()->after('ssid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webhook_details', function (Blueprint $table) {
            $table->dropColumn('fraud_check_id');
        });
    }
};
