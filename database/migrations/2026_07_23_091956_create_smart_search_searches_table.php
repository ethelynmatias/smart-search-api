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
        Schema::create('smart_search_searches', function (Blueprint $table) {
            $table->id();
            $table->string('search_id')->nullable()->index();
            $table->string('type')->index();
            $table->string('status')->nullable();
            $table->string('client_ref')->nullable();
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('smart_search_searches');
    }
};
