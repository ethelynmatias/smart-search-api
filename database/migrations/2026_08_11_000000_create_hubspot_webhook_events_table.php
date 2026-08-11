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
        Schema::create('hubspot_webhook_events', function (Blueprint $table) {
            $table->id();
            // HubSpot's own id for the change. Unique, so a redelivery of an
            // event already seen cannot be inserted a second time and the
            // searches behind it are run once however often it arrives.
            $table->unsignedBigInteger('event_id')->unique();
            $table->unsignedBigInteger('object_id')->nullable();
            $table->string('subscription_type')->nullable();
            $table->string('property_name')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hubspot_webhook_events');
    }
};
