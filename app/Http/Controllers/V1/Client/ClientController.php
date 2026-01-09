<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Protocols\ClashMeta;
use App\Services\ServerService;
use App\Services\UserService;
use App\Services\PlanService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $flag = $request->input('flag')
            ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);
        $user = $request->user;
        // account not expired and is not banned.
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
            if($flag) {
                if (!strpos($flag, 'sing')) {
                    $this->setSubscribeInfoToServers($servers, $user);
                    foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                        $file = 'App\\Protocols\\' . basename($file, '.php');
                        $class = new $file($user, $servers);
                        if (strpos($flag, $class->flag) !== false) {
                            return $class->handle();
                        }
                    }
                }
                if (strpos($flag, 'sing') !== false) {
                    $version = null;
                    if (preg_match('/sing-box\s+([0-9.]+)/i', $flag, $matches)) {
                        $version = $matches[1];
                    }
                    if (!is_null($version) && $version >= '1.12.0') {
                        $class = new Singbox($user, $servers);
                    } else {
                        $class = new SingboxOld($user, $servers);
                    }
                    return $class->handle();
                }
            }
            $class = new General($user, $servers);
            return $class->handle();
        }
    }

    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0])) return;
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;
        // 获取用户的plan_id并查找对应的plan name
        $planId = $user['plan_id'];
        $planName = '未知计划'; // 默认值，如果找不到对应的plan则显示未知计划

        if ($planId) {
            $planService = new PlanService($planId);
            if ($planService->plan) {
                $planName = $planService->plan->name;
            }
        }
        $username = $user['email'];
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $userll = Helper::trafficConvert($totalTraffic);
        $usedTraffic = Helper::trafficConvert($useTraffic);
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $currentDateTime = date('Y-m-d H:i');
        $currentDate = time(); // 获取当前时间戳
        $expiredDate = $user['expired_at'] ? date('Y.m.d', $user['expired_at']) : '长期有效';
        $userexpiredDate = $user['expired_at'] ? date('d', $user['expired_at']) : '长期有效';
        $remainingDays = $user['expired_at'] ? ceil(($user['expired_at'] - $currentDate) / 86400) : '长期有效'; // 计算剩余天数
        // $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        $expiredInfo = "已用流量{$usedTraffic}";
        if ($resetDay) {
        $expiredInfo .= " | {$userexpiredDate}号重置";  
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "获取配置时间 {$currentDateTime}",
        ]));
        array_unshift($servers, array_merge($servers[0], [
            'name' => "{$expiredInfo}",
        ]));
        array_unshift($servers, array_merge($servers[0], [
            'name' => "有效期至 {$expiredDate} | 剩{$remainingDays}天",
        ]));
        array_unshift($servers, array_merge($servers[0], [
            'name' => "{$planName} | 每月 {$userll}", 
        ]));
        array_unshift($servers, array_merge($servers[0], [
            'name' => "账户 {$username}", 
        ]));
        array_unshift($servers, array_merge($servers[0], [
            'name' => "官网 迷途云.com | 请加入群组", 
        ]));
    }
}