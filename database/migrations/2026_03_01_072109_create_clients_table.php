<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            
            // Тип: юрлицо или физлицо
            $table->enum('type', ['legal', 'individual'])->default('legal');
            
            // Название/ФИО (одно поле для всего)
            $table->string('name');
            
            // ИНН
            $table->string('inn', 12)->nullable()->index();
            
            // Контакты
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            
            // Статус и комментарий
            $table->string('status')->default('active');
            $table->text('comment')->nullable();
            
            // Данные от DaData
            $table->json('client_data')->nullable();
            
            // Мягкое удаление
            $table->softDeletes();
            
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('clients');
    }
};