<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Models\Plan;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 支付控制器，处理支付回调、订单状态确认，以及每日收入和流量排行通知。
 *
 * 该控制器同时负责：
 * - 支付回调验证
 * - 订单支付状态更新
 * - 支付完成后的 Telegram 通知
 * - 每日收入统计与节点/用户流量排行生成
 */
class PaymentController extends Controller
{
    /**
     * 处理支付回调通知
     *
     * 接收第三方支付平台的回调请求，验证签名并更新订单状态。
     * 支付成功后会发送 Telegram 通知给管理员。
     *
     * @param string $method 支付方式标识（如 alipay、wechat等）
     * @param string $uuid 支付配置的 UUID
     * @param Request $request HTTP 请求对象，包含回调数据
     * @return string 返回支付平台期望的响应字符串
     */
    public function notify($method, $uuid, Request $request)
    {
        try {
            // 根据支付方式和配置 UUID 创建对应的支付服务实例
            $paymentService = new PaymentService($method, null, $uuid);
            
            // 调用支付服务的 notify 方法，传入原始请求数据进行签名校验和参数解析
            $verify = $paymentService->notify($request->input());
            
            // 签名或参数校验失败，直接返回 500 错误
            if (!$verify) {
                abort(500, 'verify error');
            }
            
            // 调用内部处理方法，更新订单状态并执行后续业务逻辑
            if (!$this->handle($verify['trade_no'], $verify['callback_no'])) {
                abort(500, 'handle error');
            }
            
            // 如果支付服务返回了自定义响应结果则使用它，否则返回默认的 success
            return (isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            // 捕获所有异常，统一返回 500 错误，避免泄露敏感信息给支付平台
            abort(500, 'fail');
        }
    }

    /**
     * 处理支付成功的业务逻辑
     *
     * 验证订单存在性，检查订单状态，调用订单服务完成支付流程，
     * 并发送详细的 Telegram 通知给管理员。
     *
     * @param string $tradeNo 平台交易订单号（唯一标识）
     * @param string $callbackNo 第三方支付平台的回调订单号
     * @return bool 处理成功返回 true，失败返回 false
     */
    private function handle($tradeNo, $callbackNo)
    {
        // 根据交易订单号查找订单记录
        $order = Order::where('trade_no', $tradeNo)->first();
        
        // 订单不存在，记录错误并返回
        if (!$order) {
            abort(500, 'order is not found');
        }
        
        // 订单状态不为 0（待支付），说明已经处理过，避免重复处理
        if ($order->status !== 0) {
            return true;
        }

        // 创建订单服务实例，执行支付后的业务逻辑（如开通套餐、增加余额等）
        $orderService = new OrderService($order);
        
        // 调用 paid 方法更新订单状态为已支付，并执行相关业务逻辑
        if (!$orderService->paid($callbackNo)) {
            // 订单状态更新失败，返回 false 让上层处理错误
            return false;
        }

        // 支付成功后，发送详细的 Telegram 通知给管理员
        $this->sendDetailedNotification($order);

        return true;
    }

    /**
     * 发送详细的支付成功 Telegram 通知
     *
     * 收集订单、用户、套餐、优惠券、支付方式等信息，
     * 计算今日收入统计，构建格式化的通知消息并发送给管理员。
     *
     * @param Order $order 已支付的订单对象
     * @return void
     */
    private function sendDetailedNotification($order)
    {
        // 查询订单所属的用户信息
        $user = User::find($order->user_id);

        // 查询订单关联的套餐信息
        $plan = Plan::find($order->plan_id);

        // 如果订单使用了优惠券，则查询优惠券详情
        $coupon = $order->coupon_id ? Coupon::find($order->coupon_id) : null;

        // 查询订单使用的支付方式信息
        $payment = $order->payment_id ? Payment::find($order->payment_id) : null;

        // 如果用户存在且有邀请人，则查询邀请人信息（用于返佣展示）
        $inviter = $user && $user->invite_user_id ? User::find($user->invite_user_id) : null;

        // ==================== 计算今日收入统计 ====================
        
        // 计算今日总收入：统计从今天 00:00 到当前时间的所有已完成订单总金额（排除待支付和已取消）
        $todayIncome = Order::where('created_at', '>=', strtotime(date('Y-m-d')))
            ->where('created_at', '<', time())
            ->whereNotIn('status', [0, 2])
            ->sum('total_amount');

        // 计算今日产生返佣的订单数量（有返佣金额的订单数）
        $todayCommissionCount = Order::where('created_at', '>=', strtotime(date('Y-m-d')))
            ->where('created_at', '<', time())
            ->whereNotIn('status', [0, 2])
            ->where('commission_balance', '>', 0)
            ->count();

        // 计算今日返佣总金额
        $todayCommissionBalance = Order::where('created_at', '>=', strtotime(date('Y-m-d')))
            ->where('created_at', '<', time())
            ->whereNotIn('status', [0, 2])
            ->sum('commission_balance');

        // ==================== 准备显示数据 ====================
        
        // 定义订单周期的中文名称映射表
        $periodMap = [
            'month_price' => '月付',
            'quarter_price' => '季付',
            'half_year_price' => '半年付',
            'year_price' => '年付',
            'two_year_price' => '两年付',
            'three_year_price' => '三年付',
            'onetime_price' => '一次性',
            'reset_price' => '重置包',
            'deposit' => '余额充值'
        ];

        // 根据订单周期字段获取对应的中文名称，未匹配则原样输出
        $periodName = $periodMap[$order->period] ?? $order->period;

        // 格式化用户注册时间，用户不存在时显示"未知"
        $registerDate = $user ? date('Y-m-d H:i:s', $user->created_at) : '未知';

        // 格式化订单支付时间，无支付时间时显示"未知"
        $paidtime = $order ? date('Y-m-d H:i:s', $order->paid_at) : '未知';

        // ==================== 构建支付金额描述 ====================
        
        $paymentAmountStr = '';
        
        // 情况1：仅现金支付（无余额抵扣）
        if ($order->total_amount > 0 && (!$order->balance_amount || $order->balance_amount == 0)) {
            $paymentAmountStr = '现付 ' . number_format($order->total_amount / 100, 2) . ' 元';
        } 
        // 情况2：仅余额支付（全额使用余额）
        elseif ($order->total_amount == 0 && $order->balance_amount > 0) {
            $paymentAmountStr = '余额 ' . number_format($order->balance_amount / 100, 2) . ' 元';
        } 
        // 情况3：混合支付（现金 + 余额组合支付）
        elseif ($order->total_amount > 0 && $order->balance_amount > 0) {
            $paymentAmountStr = '现付 ' . number_format($order->total_amount / 100, 2) . ' 元+余额 ' . number_format($order->balance_amount / 100, 2) . ' 元';
        }

        // ==================== 构建通知消息内容 ====================
        
        $messageLines = [];

        // 用户基本信息段
        if ($user && $user->email) {
            $messageLines[] = sprintf('📧 邮箱：%s', $user->email);
        }

        if ($paymentAmountStr) {
            $messageLines[] = sprintf('💰 收款：%s', $paymentAmountStr);
        }

        // 分隔线，增强可读性
        $messageLines[] = '———————————————';

        if ($user && $user->id) {
            $messageLines[] = sprintf('🆔 ＩＤ：%s', $user->id);
        }

        // 订单核心信息段
        if ($plan && $plan->name) {
            $messageLines[] = sprintf('📦 套餐：%s', $plan->name);
        }

        if ($order->trade_no) {
            $messageLines[] = sprintf('🧾 订单：%s', $order->trade_no);
        }

        if ($paidtime && $paidtime !== '未知') {
            $messageLines[] = sprintf('🕐 时间：%s', $paidtime);
        }

        // 分隔线，增强可读性
        $messageLines[] = '———————————————';

        // 支付详细信息段
        if ($payment && $payment->name) {
            $messageLines[] = sprintf('🌐 支付：%s', $payment->name);
        }

        if ($periodName) {
            $messageLines[] = sprintf('📅 周期：%s', $periodName);
        }

        if ($coupon && $coupon->name) {
            $messageLines[] = sprintf('🎫 优惠券：%s', $coupon->name);
        }

        if ($order->discount_amount && $order->discount_amount > 0) {
            $messageLines[] = sprintf('💰 优惠金额：%s 元', number_format($order->discount_amount / 100, 2));
        }

        // 邀请返佣信息段
        if ($inviter && $inviter->email) {
            $messageLines[] = sprintf('👥 邀请人：%s', $inviter->email);
        }

        if ($order->commission_balance && $order->commission_balance > 0) {
            $messageLines[] = sprintf('💰 邀请返佣：%s 元', number_format($order->commission_balance / 100, 2));
        }

        if ($registerDate && $registerDate !== '未知') {
            $messageLines[] = sprintf('📅 注册：%s', $registerDate);
        }
        
        // 第二道分隔线
        // $messageLines[] = '———————————————';

        // 今日统计信息段（仅在有数据时显示）
        // if ($todayIncome > 0) {
        //     $messageLines[] = sprintf('💵 今日收入金额：%s 元', number_format($todayIncome / 100, 2));
        // }

        // if ($todayCommissionBalance > 0) {
        //     $messageLines[] = sprintf('💵 今日返佣金额：%s 元', number_format($todayCommissionBalance / 100, 2));
        // }

        // if ($todayCommissionCount > 0) {
        //     $messageLines[] = sprintf('💵 今日返佣次数：%s', $todayCommissionCount);
        // }
        

        // 将所有消息行用换行符连接，生成最终的通知内容
        $message = implode("\n", $messageLines);

        // 创建 Telegram 服务实例并发送消息给所有管理员
        $telegramService = new TelegramService();
        $telegramService->sendMessageWithAdmin($message);
    }

    /**
     * 每日统计汇总 - 发送昨日业务数据报告
     *
     * 该定时任务方法会：
     * 1. 统计昨日的订单数量、订单总金额
     * 2. 统计昨日的返现次数、返佣金额
     * 3. 生成昨日节点流量排行（前15名）
     * 4. 生成昨日用户流量排行（前15名）
     * 5. 将所有统计数据通过 Telegram 发送给管理员
     *
     * @return void
     */
    public function dailySummary()
    {
        // ==================== 计算昨日时间范围 ====================
        
        // 获取昨日的日期字符串，格式如 "2026-04-30"
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        
        // 计算昨日起始时间戳（昨天 00:00:00）
        $yesterdayStart = strtotime($yesterday . ' 00:00:00');
        
        // 计算昨日结束时间戳（昨天 23:59:59）
        $yesterdayEnd = strtotime($yesterday . ' 23:59:59');

        // ==================== 统计昨日订单数据 ====================
        
        // 统计昨日有效订单总数（排除状态为 0-待支付 和 2-已取消/失败的订单）
        $totalOrders = Order::where('created_at', '>=', $yesterdayStart)
            ->where('created_at', '<=', $yesterdayEnd)
            ->whereNotIn('status', [0, 2])
            ->count();

        // 统计昨日订单金额总和（单位：分，后续需要除以100转换为元）
        $totalAmount = Order::where('created_at', '>=', $yesterdayStart)
            ->where('created_at', '<=', $yesterdayEnd)
            ->whereNotIn('status', [0, 2])
            ->sum('total_amount');

        // 统计昨日产生返现的订单数量（commission_balance > 0 表示有返佣）
        $commissionCount = Order::where('created_at', '>=', $yesterdayStart)
            ->where('created_at', '<=', $yesterdayEnd)
            ->whereNotIn('status', [0, 2])
            ->where('commission_balance', '>', 0)
            ->count();

        // 统计昨日返佣金额总和（单位：分）
        $commissionAmount = Order::where('created_at', '>=', $yesterdayStart)
            ->where('created_at', '<=', $yesterdayEnd)
            ->whereNotIn('status', [0, 2])
            ->sum('commission_balance');

        // ==================== 生成流量排行数据 ====================
        
        // 获取昨日节点流量排行文本（前15名）
        $serverRankText = $this->getServerRankingText($yesterdayStart, $yesterdayEnd);
        
        // 获取昨日用户流量排行文本（前15名）
        $userRankText = $this->getUserRankingText($yesterdayStart, $yesterdayEnd);

        // ==================== 构建并发送通知消息 ====================
        
        // 使用数组方式构建订单统计信息，确保换行符正确显示
        $messageLines = [];
        $messageLines[] = sprintf('📊%s 统计：', $yesterday);
        $messageLines[] = '———————————————';
        $messageLines[] = sprintf('📑订单总数：%d 单', $totalOrders);
        $messageLines[] = sprintf('💰订单金额：%s 元', number_format($totalAmount / 100, 2));  // 分转元，保留两位小数
        $messageLines[] = sprintf('💸返现次数：%d 单', $commissionCount);
        $messageLines[] = sprintf('💵返现金额：%s 元', number_format($commissionAmount / 100, 2));  // 分转元，保留两位小数
        
        $message = implode("\n", $messageLines);

        // 将节点流量排行和用户流量排行追加到消息末尾
        $message .= "\n\n\n\n" . $serverRankText . "\n\n\n\n" . $userRankText;

        // 创建 Telegram 服务实例并发送消息给所有管理员
        $telegramService = new TelegramService();
        $telegramService->sendMessageWithAdmin($message);

    }

    /**
     * 获取节点流量排行文本（通用方法）
     *
     * 从 v2_stat_server 表中查询指定时间范围的节点流量数据，
     * 按总流量（下载+上传）降序排序，取前15名，
     * 并格式化为易读的文本消息。
     *
     * @param int $startTime 起始时间戳
     * @param int $endTime 结束时间戳
     * @return string 格式化后的节点流量排行文本
     */
    private function getServerRankingText($startTime, $endTime)
    {
        // 定义 1GB 的字节数（用于流量单位转换）
        $gb = 1024 * 1024 * 1024;

        // 使用 StatServer 模型查询节点流量统计数据
        $serverStats = StatServer::select(
            'server_id', 
            'server_type', 
            DB::raw('SUM(D) as total_d'),  // 下载流量总和
            DB::raw('SUM(U) as total_u')   // 上传流量总和
        )
            ->where('record_at', '>=', $startTime)
            ->where('record_at', '<=', $endTime)
            ->groupBy('server_id', 'server_type')
            ->orderBy(DB::raw('SUM(D) + SUM(U)'), 'desc')
            ->limit(15)
            ->get();

        // 逐条构建格式化后的排行文本
        $lines = [];
        foreach ($serverStats as $stat) {
            // 根据服务器类型和 ID 获取节点名称
            $name = $this->getServerName($stat->server_type, $stat->server_id);
            
            // 只有成功获取到节点名称才加入排行列表
            if ($name) {
                // 计算总流量（字节转 GB，保留两位小数）
                $total_gb = number_format(($stat->total_d + $stat->total_u) / $gb, 2);
                
                // 格式化输出：节点名 -- 共总流量 GB
                $lines[] = sprintf('%s -- %s GB', $name, $total_gb);
            }
        }

        // 返回带有标题的完整排行文本
        return "📊 节点流量排行：\n———————————————\n" . implode("\n", $lines);
    }

    /**
     * 获取用户流量排行文本（通用方法）
     *
     * 从 v2_stat_user 表中查询指定时间范围的用户流量数据，
     * 按总流量（下载+上传）降序排序，取前15名，
     * 并关联用户表获取邮箱，格式化为易读的文本消息。
     *
     * @param int $startTime 起始时间戳
     * @param int $endTime 结束时间戳
     * @return string 格式化后的用户流量排行文本
     */
    private function getUserRankingText($startTime, $endTime)
    {
        // 定义 1GB 的字节数（用于流量单位转换）
        $gb = 1024 * 1024 * 1024;

        // 使用 StatUser 模型查询用户流量统计数据
        $userStats = StatUser::select(
            'user_id',
            DB::raw('SUM(D) as total_d'),  // 下载流量总和
            DB::raw('SUM(U) as total_u')   // 上传流量总和
        )
            ->where('record_at', '>=', $startTime)
            ->where('record_at', '<=', $endTime)
            ->groupBy('user_id')
            ->orderBy(DB::raw('SUM(D) + SUM(U)'), 'desc')
            ->limit(15)
            ->get();

        // 逐条构建用户排行文本
        $lines = [];
        foreach ($userStats as $stat) {
            // 根据 user_id 查询用户邮箱
            $email = User::where('id', $stat->user_id)->value('email');
            
            // 只有成功获取到邮箱才加入排行列表
            if ($email) {
                // 计算总流量（字节转 GB，保留两位小数）
                $total_gb = number_format(($stat->total_d + $stat->total_u) / $gb, 2);
                
                // 格式化输出：邮箱 -- 共总流量 GB
                $lines[] = sprintf('%s -- %s GB', $email, $total_gb);
            }
        }

        // 返回带有标题的完整排行文本
        return "📊 用户流量排行：\n———————————————\n" . implode("\n", $lines);
    }

    /**
     * 每月统计汇总 - 发送上月综合业务数据报告
     *
     * 该定时任务方法会在每月1号执行，统计从上月15日到本月14日的完整业务数据：
     * 1. 统计该周期的订单数量、订单总金额
     * 2. 统计该周期的返现次数、返佣金额
     * 3. 统计该周期新增用户数
     * 4. 生成该周期节点流量排行（前15名）
     * 5. 生成该周期用户流量排行（前15名）
     * 6. 将所有统计数据通过 Telegram 发送给管理员
     *
     * @return void
     */
    public function monthlySummary()
    {
        // ==================== 计算统计时间范围（上月15日 到 本月14日）====================
        
        // 获取当前日期信息
        $currentYear = date('Y');
        $currentMonth = date('m');
        
        // 计算统计周期起始时间：上月15日 00:00:00
        $lastMonth15 = date('Y-m-15', strtotime('-1 month'));
        $periodStart = strtotime($lastMonth15 . ' 00:00:00');
        
        // 计算统计周期结束时间：本月14日 23:59:59
        $currentMonth14 = date('Y-m-14');
        $periodEnd = strtotime($currentMonth14 . ' 23:59:59');
        
        // 用于显示的周期描述（如：2026年04月15日 至 2026年05月14日）
        $startDateDisplay = date('Y年m月d日', $periodStart);
        $endDateDisplay = date('Y年m月d日', $periodEnd);
        $periodDisplay = sprintf('%s 至 %s', $startDateDisplay, $endDateDisplay);
        
        // 用于显示的统计月份（以上月为主，如：2026年04月）
        $statMonth = date('Y年m月', $periodStart);

        // ==================== 统计周期内订单数据 ====================
        
        // 统计周期内有效订单总数（排除状态为 0-待支付 和 2-已取消/失败的订单）
        $totalOrders = Order::where('created_at', '>=', $periodStart)
            ->where('created_at', '<=', $periodEnd)
            ->whereNotIn('status', [0, 2])
            ->count();

        // 统计周期内订单金额总和（单位：分，后续需要除以100转换为元）
        $totalAmount = Order::where('created_at', '>=', $periodStart)
            ->where('created_at', '<=', $periodEnd)
            ->whereNotIn('status', [0, 2])
            ->sum('total_amount');

        // 统计周期内产生返现的订单数量（commission_balance > 0 表示有返佣）
        $commissionCount = Order::where('created_at', '>=', $periodStart)
            ->where('created_at', '<=', $periodEnd)
            ->whereNotIn('status', [0, 2])
            ->where('commission_balance', '>', 0)
            ->count();

        // 统计周期内返佣金额总和（单位：分）
        $commissionAmount = Order::where('created_at', '>=', $periodStart)
            ->where('created_at', '<=', $periodEnd)
            ->whereNotIn('status', [0, 2])
            ->sum('commission_balance');

        // ==================== 统计周期内新增用户数 ====================
        
        $newUsersCount = User::where('created_at', '>=', $periodStart)
            ->where('created_at', '<=', $periodEnd)
            ->count();

        // ==================== 生成流量排行数据 ====================
        
        // 获取周期内节点流量排行文本（前15名）
        $serverRankText = $this->getServerRankingText($periodStart, $periodEnd);
        
        // 获取周期内用户流量排行文本（前15名）
        $userRankText = $this->getUserRankingText($periodStart, $periodEnd);

        // ==================== 构建并发送通知消息 ====================
        
        // 使用数组方式构建订单统计信息，确保换行符正确显示
        $messageLines = [];
        $messageLines[] = sprintf('📊 %s 月度统计（%s）：', $statMonth, $periodDisplay);
        $messageLines[] = '———————————————';
        $messageLines[] = sprintf('👥 新增用户：%d 人', $newUsersCount);
        $messageLines[] = sprintf('📑 订单总数：%d 单', $totalOrders);
        $messageLines[] = sprintf('💰 订单金额：%s 元', number_format($totalAmount / 100, 2));  // 分转元，保留两位小数
        $messageLines[] = sprintf('💸 返现次数：%d 单', $commissionCount);
        $messageLines[] = sprintf('💵 返现金额：%s 元', number_format($commissionAmount / 100, 2));  // 分转元，保留两位小数
        
        $message = implode("\n", $messageLines);

        // 将节点流量排行和用户流量排行追加到消息末尾
        $message .= "\n\n\n\n" . $serverRankText . "\n\n\n\n" . $userRankText;

        // 创建 Telegram 服务实例并发送消息给所有管理员
        $telegramService = new TelegramService();
        $telegramService->sendMessageWithAdmin($message);
    }

    /**
     * 根据服务器类型和 ID 获取服务器名称
     *
     * 通过动态拼接模型类名的方式，支持多种服务器类型（如 Vmess、Trojan、Hysteria 等）。
     * 例如：serverType='vmess' 会查找 App\Models\ServerVmess 模型。
     *
     * @param string $serverType 服务器类型标识（如 vmess、trojan、hysteria 等）
     * @param int $serverId 服务器在数据库中的 ID
     * @return string|null 返回服务器名称，如果模型不存在或记录未找到则返回 null
     */
    private function getServerName($serverType, $serverId)
    {
        // 动态拼接模型类名，首字母大写
        // 例如：'vmess' -> 'App\Models\ServerVmess'
        $modelClass = 'App\\Models\\Server' . ucfirst($serverType);
        
        // 检查模型类是否存在，避免调用不存在的类导致错误
        if (class_exists($modelClass)) {
            // 查询该服务器的 name 字段并返回
            return $modelClass::where('id', $serverId)->value('name');
        }
        
        // 模型类不存在，返回 null
        return null;
    }
}
