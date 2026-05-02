<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Protocols\NextinEncrypted;
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
    /**
     * 客户端订阅入口方法。
     *
     * @param Request $request
     * @return \Illuminate\Http\Response|string|null
     */
    public function subscribe(Request $request)
    {
        // 获取请求头中的 User-Agent，如果没有则设为空字符串。
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // 优先读取客户端传入的 flag 参数，否则使用 User-Agent 作为标记。
        $flag = $request->input('flag') ?? $userAgent;
        $flag = strtolower($flag); // 统一转为小写，方便后续匹配。

        // 从请求对象中取出已认证的用户信息。
        $user = $request->user;

        // 检查用户是否可用：账号未过期且未被封禁。
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            // 获取该用户可用的节点列表。
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);

            // 根据 UA/flag 对节点做域名重写。
            $this->applyDomainRewriteRules($servers, $flag);
            // 根据 UA/flag 对节点做白名单过滤。
            $this->applyNodeWhitelistRules($servers, $flag);

            if ($flag) {
                // 构造 NextinEncrypted 实例，用于后续判断是否需要返回加密配置。
                $nextinEncrypted = new NextinEncrypted($user, $servers);

                // 判断是否应该阻止该 User-Agent 的订阅。
                $shouldBlockNextinSubscription =
                    NextinEncrypted::shouldBlockSubscriptionForUserAgent($userAgent);

                // 判断是否应该返回加密的 Clash.Meta 订阅，
                // 当 UA 符合加密条件或 flag 中包含 nextinencrypted 时成立。
                $shouldReturnEncryptedClashMeta =
                    NextinEncrypted::shouldEncryptForUserAgent($userAgent)
                    || strpos($flag, $nextinEncrypted->flag) !== false;

                if ($shouldBlockNextinSubscription) {
                    // 当版本过低且配置为阻塞时，直接返回 403 禁止访问。
                    return response('', 403);
                }

                if ($shouldReturnEncryptedClashMeta || !strpos($flag, 'sing')) {
                    // 当需要返回加密配置，或者不是 Singbox 订阅时，先将订阅信息写入服务器列表。
                    $this->setSubscribeInfoToServers($servers, $user);
                    $nextinEncrypted = new NextinEncrypted($user, $servers);
                }

                if ($shouldReturnEncryptedClashMeta) {
                    // 返回加密后的 Clash.Meta 订阅内容。
                    return $nextinEncrypted->handle();
                }

                if (!strpos($flag, 'sing')) {
                    // 不是 Singbox 类型的订阅时，遍历所有 Protocols 目录下的协议类，
                    // 通过 flag 匹配具体协议并返回对应配置。
                    foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                        $file = 'App\\Protocols\\' . basename($file, '.php');
                        $class = new $file($user, $servers);
                        if (strpos($flag, $class->flag) !== false) {
                            return $class->handle();
                        }
                    }
                }

                if (strpos($flag, 'sing') !== false) {
                    // Singbox 订阅处理，根据版本选择新旧实现。
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

            // 走默认通用协议生成订阅配置。
            $class = new General($user, $servers);
            return $class->handle();
        }

        // 如果用户不可用则直接返回 null，不输出订阅内容。
        return null;
    }

    /**
     * 根据订阅规则重写节点域名或 IP。
     *
     * @param array $servers 引用传入节点数组
     * @param string $ua 用户代理或 flag
     * @return void
     */
    private function applyDomainRewriteRules(&$servers, $ua)
    {
        $rules = config('v2board.subscribe_domain_rewrite_rules', []);
        if (empty($rules) || empty($ua)) return;

        $matchedRules = [];
        foreach ($rules as $rule) {
            if (empty($rule['ua']) || empty($rule['domain']) || empty($rule['ip'])) continue;
            if (stripos($ua, $rule['ua']) !== false) {
                $matchedRules[] = $rule;
            }
        }
        if (empty($matchedRules)) return;

        $addressFields = ['host', 'server', 'address'];
        foreach ($servers as &$server) {
            foreach ($matchedRules as $rule) {
                foreach ($addressFields as $field) {
                    if (isset($server[$field]) && strtolower($server[$field]) === strtolower($rule['domain'])) {
                        $server[$field] = $rule['ip'];
                    }
                }
            }
        }
        unset($server);
    }

    /**
     * 根据节点白名单规则过滤节点。
     *
     * @param array $servers 引用传入节点数组
     * @param string $ua 用户代理或 flag
     * @return void
     */
    private function applyNodeWhitelistRules(&$servers, $ua)
    {
        $rules = config('v2board.subscribe_node_whitelist_rules', []);
        if (empty($rules) || !is_array($rules)) return;

        // 构造限制列表，key 为 type:id，value 为允许的 UA 关键字数组。
        $restricted = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) continue;
            $ruleUa = isset($rule['ua']) ? trim((string)$rule['ua']) : '';
            if ($ruleUa === '') continue;
            if (empty($rule['nodes']) || !is_array($rule['nodes'])) continue;
            foreach ($rule['nodes'] as $node) {
                if (!is_array($node) || empty($node['type']) || !isset($node['id'])) continue;
                $key = $node['type'] . ':' . (int)$node['id'];
                $restricted[$key][] = $ruleUa;
            }
        }
        if (empty($restricted)) return;

        // 过滤节点数组，仅保留允许该 UA 的节点。
        $servers = array_values(array_filter($servers, function ($server) use ($restricted, $ua) {
            $key = ($server['type'] ?? '') . ':' . (isset($server['id']) ? (int)$server['id'] : '');
            if (!isset($restricted[$key])) {
                return true;
            }
            if (empty($ua)) {
                return false;
            }
            foreach ($restricted[$key] as $allowedUa) {
                if ($allowedUa === '') continue;
                if (stripos($ua, $allowedUa) !== false) {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * 在服务器列表前端加入订阅信息节点，用于显示流量、到期时间等。
     *
     * @param array $servers 引用传入节点数组
     * @param array $user 当前用户信息
     * @return void
     */
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
            'name' => "看使用教程-FQA，加入群组防失联", 
        ]));
    }
}