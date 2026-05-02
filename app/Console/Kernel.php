<?php

/**
 * Laravel 控制台内核文件
 *
 * 这个文件是 Laravel 应用程序的控制台入口点，负责定义 Artisan 命令和定时任务调度。
 * 它继承自 Illuminate\Foundation\Console\Kernel，提供了命令行接口的功能。
 *
 * 主要功能：
 * 1. 注册自定义 Artisan 命令
 * 2. 定义定时任务调度（Cron 作业）
 * 3. 加载控制台路由
 */

namespace App\Console;

/**
 * 导入必要的类
 */
use App\Utils\CacheKey;  // 自定义缓存键工具类
use Illuminate\Console\Scheduling\Schedule;  // Laravel 调度器类，用于定义定时任务
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;  // Laravel 基础控制台内核
use Illuminate\Support\Facades\Cache;  // Laravel 缓存门面

/**
 * 控制台内核类
 *
 * 这个类扩展了 Laravel 的基础控制台内核，允许我们自定义命令和调度。
 */
class Kernel extends ConsoleKernel
{
    /**
     * 应用程序提供的 Artisan 命令数组
     *
     * 这里可以注册自定义的 Artisan 命令类。
     * 目前为空数组，表示没有额外的自定义命令。
     *
     * @var array
     */
    protected $commands = [
        // 示例：Commands\ExampleCommand::class,
    ];

    /**
     * 定义应用程序的命令调度
     *
     * 这个方法用于设置定时任务（Cron 作业），Laravel 会根据这些设置自动执行任务。
     * 所有定时任务都在这里定义，包括频率和执行时间。
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule Laravel 调度器实例
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // 记录调度器最后检查时间到缓存中，用于监控调度器是否正常运行
        Cache::put(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null), time());

        // ========== 流量相关任务 ==========
        // 每分钟更新一次流量统计，避免任务重叠（如果上一个任务还没完成，不会启动新的）
        $schedule->command('traffic:update')->everyMinute()->withoutOverlapping();

        // ========== V2Board 统计任务 ==========
        // 每天凌晨 0:10 执行 V2Board 统计命令
        $schedule->command('v2board:statistics')->dailyAt('0:10');

        // ========== 检查相关任务 ==========
        // 每分钟检查订单状态，避免重叠
        $schedule->command('check:order')->everyMinute()->withoutOverlapping();
        // 每15分钟检查一次返佣状态
        $schedule->command('check:commission')->everyFifteenMinutes();
        // 每分钟检查工单状态
        $schedule->command('check:ticket')->everyMinute();
        // 每天晚上 22:30 检查续费提醒
        $schedule->command('check:renewal')->dailyAt('22:30');
        // 每分钟检查服务器状态，避免重叠
        $schedule->command('check:server')->everyMinute()->withoutOverlapping();
        // 每小时生成服务器状态报告
        $schedule->command('check:server --report')->hourly();

        // ========== 重置相关任务 ==========
        // 每天重置流量统计
        $schedule->command('reset:traffic')->daily();
        // 每天重置日志
        $schedule->command('reset:log')->daily();

        // ========== 发送相关任务 ==========
        // 每天中午 11:30 发送提醒邮件
        $schedule->command('send:remindMail')->dailyAt('11:30');

        // ========== 每日收入统计任务 ==========
        // 每天凌晨 00:15 执行上一日收入统计，并发送 Telegram 通知
        // 使用闭包调用 PaymentController 的 dailySummary 方法
        $schedule->call(function () {
            $controller = new \App\Http\Controllers\V1\Guest\PaymentController();
            $controller->dailySummary();
        })->dailyAt('00:15');

        // ========== 每月收入统计任务 ==========
        // 每月1号凌晨 00:20 执行上月15日至本月14日的综合数据统计，并发送 Telegram 通知
        // 使用闭包调用 PaymentController 的 monthlySummary 方法
        $schedule->call(function () {
            $controller = new \App\Http\Controllers\V1\Guest\PaymentController();
            $controller->monthlySummary();
        })->monthlyOn(15, '00:30');

        // ========== 签到相关任务 ==========
        // 注释掉的签到缓存清理任务，每天凌晨 00:00 执行
        // $schedule->command('checkin:clear-cache')->dailyAt('00:00');

        // ========== Horizon 监控任务 ==========
        // 每5分钟生成一次 Horizon 队列监控快照，用于性能监控
        $schedule->command('horizon:snapshot')->everyFiveMinutes();
    }

    /**
     * 注册应用程序的命令
     *
     * 这个方法用于加载控制台命令。
     * 它会自动加载 app/Console/Commands 目录下的所有命令类，
     * 并包含 routes/console.php 中的控制台路由定义。
     *
     * @return void
     */
    protected function commands()
    {
        // 加载 Commands 目录下的所有命令类
        $this->load(__DIR__ . '/Commands');

        // 加载控制台路由文件，允许通过路由定义命令
        require base_path('routes/console.php');
    }
}
