<?php

namespace TypechoPlugin\Access;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 历史数据迁移的结果
 */
enum MigrateStatus: string
{
    /** 无需迁移（主库没有数据表或表为空） */
    case None = 'none';

    /** 之前已经迁移完成 */
    case Already = 'already';

    /** 本次迁移完成 */
    case Done = 'done';

    /** 本次迁移了一部分，还有剩余 */
    case Partial = 'partial';

    /** 数据量过大，未在 Web 请求里执行 */
    case Skipped = 'skipped';
}
