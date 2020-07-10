# Access

## Typecho 访问统计插件

一款为 Typecho 设计的访问记录及统计插件。

## 预览

当前版本： 2.3.3(200710) | [CHANGELOG](/CHANGELOG)

当前语言： [English](/README.md) | [Simplified Chinese](/README_CN.md)

### 最近更新

* 支持 IPv6 地址
* 常规错误修复和性能改进

### 注意事项

* 使用的数据表名变更为 `_access` （移除了 _log 后缀），可手动修改表名；
* 升级插件请请先禁用插件后更新，待更新完毕后重新启动插件，数据可自动恢复（视设置而定）；
* 插件所在目标名必须为 `Access` 否则可能出现问题；
* 因 IPv6 支持问题，使用了 gmp 函数，需要安装 php_gmp 插件（因不同环境的部署方式不同，因此在此不再赘述）；
* 因新版弃用了本地数据库，因此 IP 查询可能影响速度会变慢，如果出现英文结果是因为当前网络环境不佳，访问 IPIP 的接口失败，因此使用了备用的接口，此功能需要 php_curl 插件支持（暂不支持 IPv6 地址归属地查询）；
* 如果用户使用旧版本的数据库升级到当前分支，请手动修改表的 ip 字段为 char(38) 以便支持 IPv6 地址的记录及查询；

### 功能亮点

- 展示 PV/UV 及更多信息
- 自动忽略管理员登录及访问日志
- 支持显示 referer 来源信息并且以其进行排序
- 可设置禁用插件时清空日志
- 使用较精准的在线地址分析接口（有主备双接口）
- 支持后端/前端两种方式写入日志
- 支持根据 IP 、文章、标题、路由等进行分类排序日志

### 待办事项

- 暂无

### 开发者

[@kokororin](https://github.com/kokororin)
 
[@一名宅](https://github.com/tinymins)

[@Zhizheng Zhang](https://github.com/izhizheng)

[@Vndroid](https://github.com/Vndroid)


