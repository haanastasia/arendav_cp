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
        Schema::table('trips', function (Blueprint $table) {
            // Переименуем старые поля и добавим новые
            $table->renameColumn('hours', 'hours_dispatcher');
            $table->renameColumn('km', 'km_dispatcher');
            
            // Добавляем новые поля для водителя
            $table->integer('hours_driver')->nullable()->after('hours_dispatcher');
            $table->integer('km_driver')->nullable()->after('km_dispatcher');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->renameColumn('hours_dispatcher', 'hours');
            $table->renameColumn('km_dispatcher', 'km');
            $table->dropColumn(['hours_driver', 'km_driver']);
        });
    }
};
