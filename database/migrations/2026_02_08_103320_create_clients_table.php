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
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Краткое название
            $table->string('full_name')->nullable(); // Полное название
            $table->string('inn')->nullable()->index(); // ИНН
            $table->string('kpp')->nullable(); // КПП
            $table->string('ogrn')->nullable(); // ОГРН
            $table->json('address')->nullable(); // Адрес
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('type')->default('LEGAL'); // LEGAL, INDIVIDUAL
            $table->string('status')->nullable(); // ACTIVE, LIQUIDATED, etc
            $table->string('source')->default('manual'); // dadata, manual
            $table->json('data')->nullable(); // Полные данные от DaData
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};