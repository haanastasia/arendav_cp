<?php

namespace App\Telegram\Commands;

use Telegram\Bot\Commands\Command;

class RegisterCommand extends Command // ← ОБЯЗАТЕЛЬНО extends Command
{
    protected string $name = 'register';
    protected string $description = 'Регистрация водителя';

    public function handle()
    {
        // твой код...
    }
}