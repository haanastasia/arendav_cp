<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TelegramNotificationService;

class SendTripReminders extends Command
{
    protected $signature = 'trips:send-reminders';
    protected $description = 'Send scheduled trip reminders to drivers';

    protected $telegramService;

    public function __construct(TelegramNotificationService $telegramService)
    {
        parent::__construct();
        $this->telegramService = $telegramService;
    }

    public function handle()
    {
        $this->info('Starting to send trip reminders...');
        
        $count = $this->telegramService->sendScheduledReminders();
        
        $this->info("Sent {$count} reminders.");
        
        return Command::SUCCESS;
    }
}