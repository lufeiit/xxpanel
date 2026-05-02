<?php

namespace App\Services;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

class MailService
{
    /**
     * 到期提醒：提前多少秒开始发通知（由后台「到期提醒提前天数」配置）
     */
    public static function expireRemindLeadSeconds(): int
    {
        $days = max(1, (int)config('v2board.remind_expire_days', 1));

        return $days * 86400;
    }

    public static function trafficRemindPercent(): int
    {
        $p = (int)config('v2board.remind_traffic_percent', 95);

        return max(1, min(100, $p));
    }

    public function sendTelegramNotification(User $user, string $message)
    {
        if (!$user->telegram_id) {
            return;
        }
        try {
            (new TelegramService())->sendMessage($user->telegram_id, $message);
        } catch (\Throwable $e) {
            \Log::warning('send telegram remind failed', [
                'user_id' => $user->id,
                'telegram_id' => $user->telegram_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function expireRemindTimes(): int
    {
        return max(1, (int)config('v2board.remind_expire_times', 1));
    }

    /**
     * 缓存 TTL：到期后自动清除，续费后 expired_at 变化 key 也会不同
     */
    private function expireRemindCacheTtl(User $user): int
    {
        if ($user->expired_at === null) {
            return 60;
        }

        return max(60, $user->expired_at - time());
    }

    /**
     * 获取本轮到期提醒的已发送次数（以 expired_at 为维度，续费后自动归零）
     */
    private function getExpireSentCount(User $user): int
    {
        $key = CacheKey::get('REMIND_EXPIRE_SENT_COUNT', $user->id . '_' . $user->expired_at);
        return (int) Cache::get($key, 0);
    }

    public function incrementExpireSentCount(User $user): void
    {
        $key = CacheKey::get('REMIND_EXPIRE_SENT_COUNT', $user->id . '_' . $user->expired_at);
        $sent = $this->getExpireSentCount($user) + 1;
        Cache::put($key, $sent, $this->expireRemindCacheTtl($user));
    }

    /**
     * 判断当前时间是否应该发送第 N 次提醒
     * 将 [expired_at - lead, expired_at] 区间等分为 times 份，每份一个发送时间点
     * 当 now >= 第 N 个时间点 且 已发送次数 < N 时，应该发送
     */
    public function shouldSendExpireRemind(User $user): bool
    {
        if ($user->expired_at === null) {
            return false;
        }
        $lead = self::expireRemindLeadSeconds();
        $start = $user->expired_at - $lead;
        $now = time();
        if ($now < $start || $now >= $user->expired_at) {
            return false;
        }
        $times = self::expireRemindTimes();
        $sent = $this->getExpireSentCount($user);
        if ($sent >= $times) {
            return false;
        }
        // 等间隔：interval = lead / times，第 N 次（0-indexed）的发送时间点 = start + N * interval
        // 例：3天3次 → 第0天、第1天、第2天各发一次
        $interval = $lead / $times;
        $nextSendAt = $start + $sent * $interval;
        return $now >= $nextSendAt;
    }

    public function remindTrafficIsWarnValue($u, $d, $transferEnable): bool
    {
        if ($transferEnable <= 0) {
            return false;
        }
        $percent = self::trafficRemindPercent();
        $used = $u + $d;

        return ($used / $transferEnable) >= ($percent / 100);
    }

    public function remindTraffic(User $user)
    {
        if (!$user->remind_traffic) {
            return;
        }
        if (!$this->remindTrafficIsWarnValue($user->u, $user->d, $user->transfer_enable)) {
            return;
        }
        $flag = CacheKey::get('LAST_SEND_EMAIL_REMIND_TRAFFIC', $user->id);
        if (Cache::get($flag)) {
            return;
        }
        if (!Cache::put($flag, 1, 24 * 3600)) {
            return;
        }

        $percent = self::trafficRemindPercent();

        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('The traffic usage in :app_name has reached :percent%', [
                'app_name' => config('v2board.app_name', 'V2board'),
                'percent' => (string) $percent,
            ]),
            'template_name' => 'remindTraffic',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url'),
                'traffic_percent' => $percent,
            ],
        ]);

        if ($user->telegram_id) {
            $message = "⚠️ 您的流量使用已达到{$percent}%，请及时充值。\n\n💡 当前已使用流量：{$this->formatTraffic($user->u + $user->d)}\n📊 总流量：{$this->formatTraffic($user->transfer_enable)}";
            $this->sendTelegramNotification($user, $message);
        }
    }

    public function remindExpire(User $user)
    {
        // 循环发送所有已到期但未发送的提醒（应对 cron 频率低于提醒间隔的情况）
        $maxLoops = self::expireRemindTimes();
        $loops = 0;
        while ($this->shouldSendExpireRemind($user) && $loops < $maxLoops) {
            $loops++;
            $days = max(1, (int)config('v2board.remind_expire_days', 1));
            $expireDate = date('Y-m-d H:i', $user->expired_at);
            $remainHours = max(1, (int)round(($user->expired_at - time()) / 3600));

            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => __('The service in :app_name is about to expire', [
                    'app_name' => config('v2board.app_name', 'V2board'),
                ]),
                'template_name' => 'remindExpire',
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'),
                    'url' => config('v2board.app_url'),
                    'expire_in_days' => $days,
                ],
            ]);

            if ($user->telegram_id) {
                $message = "⏰ 您的服务即将到期（剩余约 {$remainHours} 小时），请及时续费。\n\n📅 到期时间：{$expireDate}";
                $this->sendTelegramNotification($user, $message);
            }

            $this->incrementExpireSentCount($user);
        }
    }

    public function formatTraffic($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
