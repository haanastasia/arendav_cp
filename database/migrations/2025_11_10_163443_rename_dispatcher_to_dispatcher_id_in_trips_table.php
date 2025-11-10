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
            // Переименовываем поле dispatcher в dispatcher_id
            $table->renameColumn('dispatcher', 'dispatcher_id');
            
            // Меняем тип на integer для хранения ID
            $table->integer('dispatcher_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            // Возвращаем обратно
            $table->integer('dispatcher_id')->nullable()->change();
            $table->renameColumn('dispatcher_id', 'dispatcher');
        });
    }
};
