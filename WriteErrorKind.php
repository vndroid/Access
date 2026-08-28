<?php

namespace TypechoPlugin\Access;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 一次写入失败该归咎于「这一行数据」还是「这套环境」
 *
 * 队列的确认是整批 LTRIM，做不到只确认成功的几条，所以每一条写不进去的消息
 * 都必须当场决定去向：转进死信（此后不再重试），还是留在 processing 等下一轮。
 * 两种判错的代价严重不对称 ——
 *   把环境问题当成脏数据 → 整批倒进死信，死信满 DEAD_MAX_LENGTH 后丢最旧，数据永久消失；
 *   把脏数据当成环境问题 → 队列被这条消息堵一阵子，但一条也没丢。
 * 所以规则是「只有明确认定是这一行的错才归 Data」，认不出来一律往安全的方向倒。
 */
enum WriteErrorKind
{
    /**
     * 明确是这一行的错：字段超长、类型不符、违反约束
     *
     * 重试多少次都是同样的结果，留在队列里只会永远堵着，转死信。
     */
    case Data;

    /**
     * 明确不是这一行的错：连不上、没权限、库只读、磁盘满、被管理员中断、表不存在
     *
     * 换个时间同样的数据就能写进去，必须原样留着。
     */
    case Environment;

    /**
     * 认不出来
     *
     * 按 Environment 对待（留着重试），但会计入「卡了多久」：
     * 一直认不出又一直写不进去的批次不能永远占着队首，见 Queue::STUCK_SECONDS。
     */
    case Unknown;

    /** 是不是「留着重试」这一类 */
    public function shouldRetry(): bool
    {
        return $this !== self::Data;
    }

    /** 万一要进死信，记什么原因 */
    public function reason(): string
    {
        return match ($this) {
            self::Data        => 'db-rejected',
            self::Environment => 'db-environment',
            self::Unknown     => 'db-unknown',
        };
    }
}
