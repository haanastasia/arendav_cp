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
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            
            // Основные поля
            $table->string('name'); // Название заявки
            $table->date('date'); // Дата
            $table->time('time')->nullable(); // Время
            $table->string('dispatcher')->nullable(); // Диспетчер (пока текст)
            $table->string('client_name'); // Наименование клиента
            $table->string('client_type')->default('П'); // Тип клиента: П, Н
            $table->string('status')->default('В работе'); // Статус: В работе, Выполнена, Отменена
            
            // Водитель
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            
            // Простая финансовая информация
            $table->decimal('amount', 10, 2)->nullable(); // Сумма заявки
            $table->string('payment_type')->nullable(); // Тип оплаты: н, ндс, бн
            
            $table->text('address')->nullable(); // Адрес подачи
            $table->text('notes')->nullable(); // Примечание
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
