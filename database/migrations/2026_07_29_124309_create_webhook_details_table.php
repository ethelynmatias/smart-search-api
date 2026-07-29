<?php

use App\Enums\WebhookDetailStatus;
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
        Schema::create('webhook_details', function (Blueprint $table) {
            $table->id();
            $table->uuid('group_id')->nullable()->index();
            $table->string('deal_id')->nullable()->index();
            $table->string('ssid')->nullable();
            $table->string('search_subject_id')->nullable()->index();
            // smartdoc for now; aml joins later.
            $table->string('type')->default('smartdoc')->index();
            // pending | processing | completed | failed | expired
            $table->string('status')->default(WebhookDetailStatus::Pending->value)->index();
            $table->json('payload')->nullable();
            $table->timestamps();

            // One row per search: the ssid identifies it, and the leading
            // column keeps this usable as the plain ssid lookup index too.
            $table->unique(['ssid', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_details');
    }
};
