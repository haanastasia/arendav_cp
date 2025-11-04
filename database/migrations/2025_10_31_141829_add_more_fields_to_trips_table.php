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
            // Блок 2: Техническая информация
            $table->boolean('has_waybill')->default(false)->after('status'); // Путевка
            $table->string('height')->nullable()->after('has_waybill'); // Высота
            $table->string('car_number')->nullable()->after('height'); // № авто
            
            // Блок 3: Финансы (расширяем)
            $table->decimal('actual_amount', 10, 2)->nullable()->after('amount'); // Факт. сумма
            $table->decimal('tech_amount', 10, 2)->nullable()->after('actual_amount'); // Сумма Ст. тех
            $table->decimal('dispatcher_percent', 10, 2)->nullable()->after('tech_amount'); // % Диспетчера
            $table->decimal('vat', 10, 2)->nullable()->after('dispatcher_percent'); // НДС
            $table->decimal('total', 10, 2)->nullable()->after('vat'); // Итого
            $table->decimal('usn', 10, 2)->nullable()->after('total'); // УСН
            
            // Блок 4: Детали работы
            $table->string('work_time')->nullable()->after('address'); // Время работы (текст)
            $table->integer('hours')->nullable()->after('work_time'); // Час
            $table->integer('km')->nullable()->after('hours'); // Км
            $table->integer('km_check')->nullable()->after('km'); // СВЕРКА КМ
            
            // Блок 5: Дополнительно
            $table->string('invoice')->nullable()->after('usn'); // Счет
            $table->string('paid_status')->nullable()->after('invoice'); // Оплачен
            $table->string('tech_payment')->nullable()->after('paid_status'); // Оплата ст.тех
            $table->string('reason')->nullable()->after('tech_payment'); // Причина отказа/переноса
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            //
        });
    }
};
