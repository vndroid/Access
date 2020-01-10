# Access

## Typecho 访问统计插件

一款为 Typecho 设计的访问记录及统计插件。

## 预览

当前版本： 2.3.1(200110) | [CHANGELOG](/CHANGELOG)

当前语言： [English](/README.md) | [Simplified Chinese](/README_CN.md)

### 最近更新

* 支持 IPv6 地址（测试版）
* 常规错误修复和性能改进

### 注意事项

* 此分支使用的数据表名变更为 `_access` （移除了 _log 后缀）但表格式与主分支完全兼容，可手动修改表名
* 升级插件请请先禁用插件后更新，待更新完毕后重新启动插件，数据可自动恢复（视设置而定）
* 插件所在目标名必须为 `Access` 否则可能出现问题

### 功能亮点

- 展示 PV/UV 及更多信息
- 自动忽略管理员登录及访问日志
- 支持显示 referer 来源信息并且以其进行排序
- 可设置禁用插件时清空日志
- 使用较精准的在线地址分析接口（有主备双接口）
- 支持后端/前端两种方式写入日志
- 支持根据 IP 、文章、标题、路由等进行分类排序日志

### 待办事项

### 开发者

[@kokororin](https://github.com/kokororin)
 
[@一名宅](https://github.com/tinymins)

[@Zhizheng Zhang](https://github.com/izhizheng)

[@Vndroid](https://github.com/Vndroid)


