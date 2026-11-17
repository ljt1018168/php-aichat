<?php
/*
 * @Description: 字段脱敏 —— LLM 不得接触未脱敏原文
 *              实例方法（构造注入 Config + PermissionProvider）；
 *              纯函数 maskPhone/maskIdCard 保持静态。
 *              ChatHandler 构造时会注册 current()，供宿主工具在 execute() 内便捷取用。
 */

declare (strict_types = 1);

namespace aichat;

use aichat\contract\PermissionProvider;

/**
 * 字段级权限：控制"能看到字段的什么形态"
 *
 * 硬约束：工具返回给 LLM 的任何敏感数据，都必须经过本类处理。
 * 工具实现不得绕过 Masker 直接返回原始行。
 */
class Masker
{
    /** @var Masker|null 最近一次 ChatHandler 注册的实例（工具侧便捷入口） */
    private static $current;

    /** @var Config */
    private $config;
    /** @var PermissionProvider */
    private $permission;

    public function __construct(Config $config, PermissionProvider $permission)
    {
        $this->config     = $config;
        $this->permission = $permission;
    }

    public static function setCurrent(self $masker): void
    {
        self::$current = $masker;
    }

    /**
     * 取当前实例（由 ChatHandler 注册；宿主工具内使用）
     */
    public static function current(): self
    {
        if (self::$current === null) {
            throw new \RuntimeException('Masker 尚未初始化：请先构造 ChatHandler，或自行 new Masker 后 setCurrent()。');
        }
        return self::$current;
    }

    /**
     * 对单行数据做脱敏
     *
     * @param array $row    原始行
     * @param int   $userId 当前用户，用于判断是否可看完整手机号
     * @return array 脱敏后的行
     */
    public function maskRow(array $row, int $userId = 0): array
    {
        $canSeeFullPhone = $this->canSeeFullPhone($userId);

        foreach ($row as $key => $value) {
            $lower = strtolower((string)$key);

            // 手机号
            if ($lower === 'phone' || $lower === 'mobile' || $lower === 'tel') {
                $row[$key] = $canSeeFullPhone ? (string)$value : self::maskPhone((string)$value);
                continue;
            }

            // 身份证：默认不返回
            if ($lower === 'idcard' || $lower === 'id_card') {
                if (!$this->config->get('aichat.mask.idcard_return', false)) {
                    unset($row[$key]);
                } else {
                    $row[$key] = self::maskIdCard((string)$value);
                }
                continue;
            }

            // 住址：一律不返回
            if (strpos($lower, 'address') !== false) {
                if (!$this->config->get('aichat.mask.address_return', false)) {
                    unset($row[$key]);
                }
                continue;
            }

            // 银行卡等
            if (strpos($lower, 'bank') !== false && strpos($lower, 'card') !== false) {
                unset($row[$key]);
                continue;
            }
        }

        return $row;
    }

    /**
     * 批量脱敏
     */
    public function maskList(array $list, int $userId = 0): array
    {
        $result = [];
        foreach ($list as $row) {
            $result[] = $this->maskRow(is_array($row) ? $row : (array)$row, $userId);
        }
        return $result;
    }

    /**
     * 是否可查看完整手机号（由宿主权限提供者判定）
     */
    public function canSeeFullPhone(int $userId): bool
    {
        return $this->permission->canSeeFullPhone($userId);
    }

    // ==================== 纯函数（无依赖，保持静态） ====================

    /**
     * 手机号脱敏：138****1234
     */
    public static function maskPhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }
        // 非中国大陆手机号（含邮箱等）做保守处理
        if (!preg_match('/^1\d{10}$/', $phone)) {
            $len = mb_strlen($phone);
            if ($len <= 2) {
                return $phone;
            }
            return mb_substr($phone, 0, 1) . str_repeat('*', max(1, $len - 2)) . mb_substr($phone, -1);
        }
        return substr($phone, 0, 3) . '****' . substr($phone, 7);
    }

    /**
     * 身份证脱敏：前6后4
     */
    public static function maskIdCard(string $idCard): string
    {
        $idCard = trim($idCard);
        $len    = strlen($idCard);
        if ($len < 10) {
            return str_repeat('*', max(1, $len));
        }
        return substr($idCard, 0, 6) . str_repeat('*', $len - 10) . substr($idCard, -4);
    }
}