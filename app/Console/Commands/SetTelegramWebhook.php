<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Telegram\Bot\Laravel\Facades\Telegram;

class SetTelegramWebhook extends Command
{
    protected $signature = 'telegram:webhook';
    protected $description = 'Set Telegram webhook URL';

    public function handle()
    {
        $url = 'https://cp.arendav.ru/api/telegram/webhook';
        
        $this->info("Setting webhook to: " . $url);
        
        try {
            $response = Telegram::setWebhook([
                'url' => $url,
                'max_connections' => 40,
                'allowed_updates' => ['message', 'callback_query'],
            ]);
            
            $this->info('Webhook response: ' . print_r($response, true));
            
            // Проверим информацию о webhook
            $webhookInfo = Telegram::getWebhookInfo();
            $this->info('Webhook info: ' . print_r($webhookInfo, true));
            
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            $this->error('Full error: ' . $e->getTraceAsString());
        }
    }
}