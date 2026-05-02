<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TelegramService;

class UpdateTelegramCommands extends Command
{
    /**
     * 控制台命令的名称和签名。
     *
     * @var string
     */
    protected $signature = 'telegram:update-commands';

    /**
     * 控制台命令的描述。
     *
     * @var string
     */
    protected $description = 'Update Telegram bot commands';

    /**
     * 创建一个新的命令实例。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 执行控制台命令。
     *
     * @return int
     */
    public function handle()
    {
        try {
            $telegramService = new TelegramService();
            $commands = $telegramService->discoverCommands(base_path('app/Plugins/Telegram/Commands'));
            
            $telegramService->setMyCommands($commands);
            
            $this->info('Telegram commands updated successfully!');
            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to update Telegram commands: ' . $e->getMessage());
            return 1;
        }
    }
}