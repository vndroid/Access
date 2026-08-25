<?php
/**
 * @var Options $options
 * @var Request $request
 * @var Security $security
 * @var User $user
 * @var Menu $menu
 */
include_once 'common.php';
include 'header.php';
include 'menu.php';

use Typecho\Request;
use TypechoPlugin\Access\Core;
use TypechoPlugin\Access\Plugin as AccessPlugin;
use Utils\Helper;
use Widget\Menu;
use Widget\Options;
use Widget\Security;
use Widget\User;

$access = new Core();
$initAction = $access->action;
?>
<link rel="stylesheet" href="<?php $options->pluginUrl('Access/css/style.css?v=3.0.1')?>">
<link rel="stylesheet" href="<?php $options->pluginUrl('Access/sweetalert/sweetalert.css')?>">
<main class="main">
    <div class="body container">
        <div class="typecho-page-title">
           <h2 id="access-page-title"><?php echo $access->title;?></h2>
        </div>

        <!-- ========== 历史数据迁移（仅在使用独立数据库且有待迁移数据时出现） ========== -->
        <div id="access-migrate" class="access-migrate" style="display:none">
            <p class="access-migrate-text" id="access-migrate-text"></p>
            <div class="access-migrate-bar"><span id="access-migrate-fill"></span></div>
            <p class="access-migrate-actions">
                <button type="button" class="btn btn-s primary" id="access-migrate-start"><?php _e('开始迁移'); ?></button>
                <button type="button" class="btn btn-s" id="access-migrate-stop" style="display:none"><?php _e('暂停'); ?></button>
                <span class="description" id="access-migrate-hint"><?php _e('迁移期间请勿关闭本页面；中途暂停或关闭后可随时回到这里继续。'); ?></span>
            </p>
        </div>

        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12 typecho-list">
                <div class="typecho-list-operate">
                    <ul class="typecho-option-tabs" id="access-tabs">
                        <li data-tab="overview"<?=($initAction == 'overview' ? ' class="current"' : '')?>><a href="#"><?php _e('访问概览'); ?></a></li>
                        <li data-tab="logs"<?=($initAction == 'logs' ? ' class="current"' : '')?>><a href="#"><?php _e('访问日志'); ?></a></li>
                    </ul>
                    <ul class="typecho-option-tabs" id="access-refresh">
                        <li data-tab="refresh"><a href="#"><?php _e('刷新'); ?></a></li>
                    </ul>
                </div>

                <!-- ========== 日志面板 ========== -->
                <div id="panel-logs" style="<?php if($initAction != 'logs'): ?>display:none<?php endif; ?>">
                <form class="typecho-list-operate search-form" method="get">
                    <div class="operate">
                        <label><i class="sr-only"><?php _e('全选'); ?></i><input class="typecho-table-select-all" type="checkbox" /></label>
                        <div class="btn-group btn-drop">
                            <button class="btn dropdown-toggle btn-s" type="button"><i class="sr-only"><?php _e('操作'); ?></i><?php _e('选中项'); ?> <i class="i-caret-down"></i></button>
                            <ul class="dropdown-menu">
                                <li><button type="button" data-action="delete" class="access-delete-btn"><?php _e('删除'); ?></button></li>
                            </ul>
                        </div>
                    </div>
                    <div class="search" role="search">
                        <a href="#" id="logs-clear-filter" style="display:none"><?php _e('&laquo; 取消筛选'); ?></a>
                        <label>
                            <select name="filter" id="logs-filter">
                                <option value="all"><?php _e('全部条件'); ?></option>
                                <option value="ip"><?php _e('按IP'); ?></option>
                                <option value="post"><?php _e('按文章'); ?></option>
                                <option value="path"><?php _e('按路由'); ?></option>
                            </select>
                        </label>
                        <label id="logs-filter-ip" style="display:none">
                            <input type="text" class="text-s" placeholder="输入IP" name="ip" />
                        </label>
                        <label id="logs-filter-post" style="display:none">
                            <select name="cid" id="cid-select">
                                <option value=""><?php _e('加载中…'); ?></option>
                            </select>
                        </label>
                        <label id="logs-filter-path" style="display:none">
                            <input type="text" class="text-s" placeholder="输入路由" name="path" />
                        </label>
                        <label>
                            <select name="type" id="logs-type">
                                <option value="1"><?php _e('默认（仅人类）'); ?></option>
                                <option value="2"><?php _e('筛选（仅爬虫）'); ?></option>
                                <option value="3"><?php _e('所有'); ?></option>
                            </select>
                        </label>
                        <button type="button" class="btn btn-s" id="logs-search-btn"><?php _e('筛选'); ?></button>
                    </div>
                </form>
                <form class="operate-form" method="post">
                    <div class="typecho-table-wrap">
                        <table class="typecho-list-table mono-table small-font">
                            <colgroup>
                                <col width="3%"/>
                                <col width="21%"/>
                                <col width="18%"/>
                                <col width="15%"/>
                                <col width="20%"/>
                                <col width="14%"/>
                            </colgroup>
                            <thead>
                            <tr>
                                <th>\</th>
                                <th><?php _e('Path'); ?></th>
                                <th><?php _e('UA'); ?></th>
                                <th><?php _e('IP'); ?></th>
                                <th><?php _e('Referrals'); ?></th>
                                <th><?php _e('Date'); ?></th>
                            </tr>
                            </thead>
                            <tbody id="logs-table-body">
                                <tr><td colspan="6" class="access-skeleton" style="height:300px"><?php _e('加载中…'); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </form>
                <form class="typecho-list-operate" method="get">
                    <div class="operate">
                        <label><i class="sr-only"><?php _e('全选'); ?></i><input type="checkbox" class="typecho-table-select-all" /></label>
                        <div class="btn-group btn-drop">
                            <button class="btn dropdown-toggle btn-s" type="button"><i class="sr-only"><?php _e('操作'); ?></i><?php _e('选中项'); ?> <i class="i-caret-down"></i></button>
                            <ul class="dropdown-menu">
                                <li><button type="button" data-action="delete" class="access-delete-btn"><?php _e('删除'); ?></button></li>
                            </ul>
                        </div>
                    </div>
                    <ul class="typecho-pager" id="logs-pager"></ul>
                </form>
                </div>

                <!-- ========== 概览面板 ========== -->
                <div id="panel-overview" style="<?php if($initAction != 'overview'): ?>display:none<?php endif; ?>">

               <h4 class="typecho-list-table-title">访问数据总览</h4>
                <div class="typecho-table-wrap">
                    <table class="typecho-list-table mono-table">
                        <colgroup>
                            <col width="9%"/>
                            <col width="15%"/>
                            <col width="15%"/>
                            <col width="15%"/>
                        </colgroup>
                        <thead>
                            <tr>
                                <th> </th>
                                <th><?php _e('PV'); ?></th>
                                <th><?php _e('UV'); ?></th>
                                <th><?php _e('IP'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Today</td>
                                <td id="ov-today-pv" class="access-skeleton">--</td>
                                <td id="ov-today-uv" class="access-skeleton">--</td>
                                <td id="ov-today-ip" class="access-skeleton">--</td>
                            </tr>
                            <tr>
                                <td>Yesterday</td>
                                <td id="ov-yesterday-pv" class="access-skeleton">--</td>
                                <td id="ov-yesterday-uv" class="access-skeleton">--</td>
                                <td id="ov-yesterday-ip" class="access-skeleton">--</td>
                            </tr>
                            <tr>
                                <td>Total</td>
                                <td id="ov-total-pv" class="access-skeleton">--</td>
                                <td id="ov-total-uv" class="access-skeleton">--</td>
                                <td id="ov-total-ip" class="access-skeleton">--</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

               <h4 class="typecho-list-table-title">来源域名 Top 20</h4>
                <div class="typecho-table-wrap">
                    <table class="typecho-list-table mono-table">
                        <colgroup>
                            <col width="10%"/>
                            <col width="15%"/>
                            <col width="75%"/>
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Occurrences</th>
                                <th>Referrer Domain</th>
                            </tr>
                        </thead>
                        <tbody id="referer-domain-body">
                            <tr><td colspan="3" class="access-skeleton"><?php _e('加载中…'); ?></td></tr>
                        </tbody>
                    </table>
                </div>

               <h4 class="typecho-list-table-title">来源页面 Top 20</h4>
                <div class="typecho-table-wrap">
                    <table class="typecho-list-table mono-table">
                        <colgroup>
                            <col width="10%"/>
                            <col width="15%"/>
                            <col width="75%"/>
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Occurrences</th>
                                <th>Referrer URL</th>
                            </tr>
                        </thead>
                        <tbody id="referer-url-body">
                            <tr><td colspan="3" class="access-skeleton"><?php _e('加载中…'); ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <h4 class="typecho-list-table-title">今日图表</h4>
                <div class="typecho-table-wrap access-skeleton" id="chart-today" style="min-height:300px"></div>

                <h4 class="typecho-list-table-title">昨日图表</h4>
                <div class="typecho-table-wrap access-skeleton" id="chart-yesterday" style="min-height:300px"></div>

                <h4 class="typecho-list-table-title">当月图表</h4>
                <div class="typecho-table-wrap access-skeleton" id="chart-month" style="min-height:300px"></div>

                <h4 class="typecho-list-table-title">文章访问量</h4>
                <div class="typecho-table-wrap access-skeleton" id="chart-post-pie" style="min-height:360px"></div>
                </div>

            </div>
        </div>
    </div>
</main>

<?php
include 'copyright.php';
include 'common-js.php';
include 'table-js.php';
?>
<script src="<?php $options->pluginUrl('Access/sweetalert/sweetalert.min.js')?>"></script>
<script src="https://cdnjs.loli.net/ajax/libs/highcharts/11.3.0/highcharts.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://cdnjs.loli.net/ajax/libs/highcharts/11.3.0/modules/series-label.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://cdnjs.loli.net/ajax/libs/highcharts/11.3.0/modules/exporting.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://cdnjs.loli.net/ajax/libs/highcharts/11.3.0/modules/export-data.src.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://cdnjs.loli.net/ajax/libs/highcharts/11.3.0/modules/accessibility.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script type="text/javascript">
(function() {
    /* ==================== 常量 ==================== */
    let logsApiUrl     = '<?php echo rtrim(Helper::options()->index, '/') . '/access/logs/get.json'; ?>';
    let overviewApiUrl = '<?php echo rtrim(Helper::options()->index, '/') . '/access/overview.json'; ?>';
    let ipApiUrl       = '<?php echo rtrim(Helper::options()->index, '/') . '/access/geo.json'; ?>';
    let deleteApiUrl   = '<?php echo rtrim(Helper::options()->index, '/') . '/access/logs/delete.json'; ?>';
    let migrateApiUrl  = '<?php echo rtrim(Helper::options()->index, '/') . '/access/migrate.json'; ?>';
    let adminUrl       = '<?php echo rtrim(Helper::options()->adminUrl, '/'); ?>';
    let panelName      = '<?php echo AccessPlugin::$panel; ?>';
    let initAction     = '<?php echo $initAction; ?>';

    /* ==================== 工具函数 ==================== */
    /* 把接口返回的真实错误信息显示出来，而不是一句「加载失败」 */
    function describeError(res, xhr) {
        if (res && res.code !== 0 && res.data) {
            return '加载失败：' + String(res.data);
        }
        if (xhr && xhr.status === 403) {
            return '加载失败：没有权限，请确认已用管理员账号登录';
        }
        if (xhr && xhr.status === 404) {
            return '加载失败：接口未注册，请在插件管理中禁用并重新启用 Access 插件';
        }
        if (xhr && xhr.status) {
            return '加载失败：服务器返回 HTTP ' + xhr.status
                 + (xhr.status >= 500 ? '（多为 PHP 致命错误，请查看 PHP 错误日志）' : '');
        }
        return '加载失败：请求未能完成';
    }

    function escapeHtml(str) {
        let div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function formatDate(ts) {
        let d = new Date(ts * 1000);
        let pad = function(n) { return n < 10 ? '0' + n : n; };
        return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate())
             + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    }

    /* ==================== Tab 切换状态 ==================== */
    let currentTab = initAction;
    let overviewLoaded = false;
    let logsLoaded = false;
    let logsState = { page: 1, type: 1, filter: 'all', ip: '', cid: '', path: '' };

    function buildTabUrl(tab) {
        return adminUrl + '/extending.php?panel=' + encodeURIComponent(panelName) + '&action=' + tab;
    }

    function switchTab(tab) {
        if (tab === currentTab) return;
        currentTab = tab;

        $('#panel-logs, #panel-overview').hide();
        $('#panel-' + tab).show();
        $('#access-page-title').text(tab === 'overview' ? '访问概览' : '访问日志');

        $('#access-tabs li[data-tab]').removeClass('current');
        $('#access-tabs li[data-tab="' + tab + '"]').addClass('current');

        history.pushState({ tab: tab }, '', buildTabUrl(tab));

        if (tab === 'overview' && !overviewLoaded) loadOverview();
        if (tab === 'logs' && !logsLoaded) loadLogs(logsState);
    }

    $(window).on('popstate', function(e) {
        let state = e.originalEvent.state;
        if (state && state.tab) {
            currentTab = ''; // 强制 switchTab 执行
            switchTab(state.tab);
        }
    });

    /* ==================== 概览加载 ==================== */
    let printChart = function($el, data) {
        $el.highcharts({
            title:   { text: data.title, x: -20 },
            subtitle:{ text: data.sub_title, x: -20 },
            xAxis:   { categories: data.xAxis },
            yAxis:   { title: { text: '数量' }, plotLines: [{ value: 0, width: 1, color: '#808080' }] },
            tooltip: { valueSuffix: '' },
            plotOptions: { line: { dataLabels: { enabled: true }, enableMouseTracking: false } },
            series: [
                { name: 'PV（浏览）', data: data.pv.detail },
                { name: 'UV（访客）', data: data.uv.detail },
                { name: 'IP（地址）', data: data.ip.detail }
            ]
        });
    };

    let printPieChart = function($el, data) {
        if (!data || !data.length) {
            $el.html('<h6 class="typecho-list-table-title">暂无数据</h6>');
            return;
        }

        $el.highcharts({
            chart: { type: 'pie' },
            title: { text: '文章访问量占比' },
            subtitle: { text: 'Generate By AccessPlugin' },
            tooltip: {
                pointFormat: '<b>{point.y}</b> ({point.percentage:.1f}%)'
            },
            accessibility: {
                point: {
                    valueSuffix: '%'
                }
            },
            plotOptions: {
                pie: {
                    allowPointSelect: true,
                    cursor: 'pointer',
                    dataLabels: {
                        enabled: true,
                        format: '{point.name}: {point.y}'
                    }
                }
            },
            series: [{
                name: '访问量',
                colorByPoint: true,
                data: data
            }]
        });
    };

    /*
     * 概览分段加载
     *
     * 统计表很大时（几百万行）一次算完整个概览会超出 Web 服务器的超时时间，
     * 而首次加载失败又意味着缓存永远建不起来，于是每次都是「首次加载」。
     * 这里按 today / yesterday / total / referer / pie / month 顺序逐块请求：
     * 每块都能在超时之前跑完并写入缓存，页面也随之逐步填充。
     * 当月图表按天推进，一次跑不完就带着进度继续请求下一段。
     */
    let overviewSections = <?php echo json_encode(Core::overviewSections()); ?>;

    /* 每段对应的骨架屏元素，出错时只把这一段标红，其余照常加载 */
    let sectionTargets = {
        today:     '#ov-today-pv, #ov-today-uv, #ov-today-ip, #chart-today',
        yesterday: '#ov-yesterday-pv, #ov-yesterday-uv, #ov-yesterday-ip, #chart-yesterday',
        total:     '#ov-total-pv, #ov-total-uv, #ov-total-ip',
        referer:   '#referer-domain-body, #referer-url-body',
        pie:       '#chart-post-pie',
        month:     '#chart-month'
    };

    let overviewToken = 0;   // 刷新时自增，用来作废上一轮还在飞的请求

    function refererRows(list) {
        if (!list || !list.length) { return '<tr><td colspan="3">暂无数据</td></tr>'; }
        var html = '';
        $.each(list, function(i, v) {
            html += '<tr><td>' + (i+1) + '</td><td>' + escapeHtml(String(v.count))
                 + '</td><td>' + escapeHtml(String(v.value)) + '</td></tr>';
        });
        return html;
    }

    function sectionFailed(name, message) {
        var sel = sectionTargets[name];
        if (!sel) return;
        if (name === 'referer') {
            $('#referer-domain-body, #referer-url-body')
                .html('<tr><td colspan="3">' + escapeHtml(message) + '</td></tr>');
            return;
        }
        $(sel).each(function() {
            var $el = $(this);
            $el.removeClass('access-skeleton');
            if ($el.is('td')) { $el.text('!'); } else { $el.text(message); }
        });
    }

    function applySection(name, d) {
        switch (name) {
            case 'today':
                $('#ov-today-pv').text(d.today.pv.count).removeClass('access-skeleton');
                $('#ov-today-uv').text(d.today.uv.count).removeClass('access-skeleton');
                $('#ov-today-ip').text(d.today.ip.count).removeClass('access-skeleton');
                printChart($('#chart-today').removeClass('access-skeleton'), d.today);
                break;
            case 'yesterday':
                $('#ov-yesterday-pv').text(d.yesterday.pv.count).removeClass('access-skeleton');
                $('#ov-yesterday-uv').text(d.yesterday.uv.count).removeClass('access-skeleton');
                $('#ov-yesterday-ip').text(d.yesterday.ip.count).removeClass('access-skeleton');
                printChart($('#chart-yesterday').removeClass('access-skeleton'), d.yesterday);
                break;
            case 'total':
                $('#ov-total-pv').text(d.total.pv).removeClass('access-skeleton');
                $('#ov-total-uv').text(d.total.uv).removeClass('access-skeleton');
                $('#ov-total-ip').text(d.total.ip).removeClass('access-skeleton');
                break;
            case 'referer':
                $('#referer-domain-body').html(refererRows(d.referer && d.referer.domain));
                $('#referer-url-body').html(refererRows(d.referer && d.referer.url));
                break;
            case 'pie':
                printPieChart($('#chart-post-pie').removeClass('access-skeleton'), d.post_pie || []);
                break;
            case 'month':
                if (d.done) {
                    printChart($('#chart-month').removeClass('access-skeleton'), d.month);
                } else {
                    $('#chart-month').text('当月图表统计中… ' + (d.progress || 0) + ' / ' + (d.total_days || 0) + ' 天');
                }
                break;
        }
    }

    function requestSection(name, token, attempt, done) {
        $.ajax({
            url: overviewApiUrl, method: 'get', dataType: 'json', timeout: 120000,
            data: { section: name, seconds: 8 },
            success: function(res) {
                if (token !== overviewToken) return;          // 已被刷新作废
                if (!res || res.code !== 0) {
                    sectionFailed(name, describeError(res, null));
                    return done();
                }
                applySection(name, res.data);
                /* done === false 表示这一段还没算完（目前只有当月图表会这样），继续推进 */
                if (res.data && res.data.done === false && attempt < 60) {
                    return requestSection(name, token, attempt + 1, done);
                }
                if (res.data && res.data.done === false) {
                    sectionFailed(name, '当月图表统计耗时过长，请稍后刷新继续。');
                }
                done();
            },
            error: function(xhr) {
                if (token !== overviewToken) return;
                sectionFailed(name, describeError(null, xhr));
                done();
            }
        });
    }

    function loadOverview() {
        overviewLoaded = true;
        overviewToken++;
        var token = overviewToken;
        var queue = overviewSections.slice();

        (function next() {
            if (token !== overviewToken || !queue.length) return;
            requestSection(queue.shift(), token, 0, next);
        })();
    }

    /* ==================== 日志加载 ==================== */
    function loadLogs(state) {
        logsState = state;
        var params = { page: state.page, type: state.type, filter: state.filter };
        if (state.filter === 'ip')   params.ip   = state.ip;
        if (state.filter === 'post') params.cid  = state.cid;
        if (state.filter === 'path') params.path = state.path;

        $('#logs-table-body').html('<tr><td colspan="6" class="access-skeleton" style="height:300px">加载中…</td></tr>');
        $('#logs-pager').empty();
        syncFilterUI(state);

        $.ajax({
            url: logsApiUrl, method: 'get', dataType: 'json', data: params,
            success: function(res) {
                if (res.code !== 0) {
                    $('#logs-table-body').html('<tr><td colspan="6">' + escapeHtml(describeError(res, null)) + '</td></tr>');
                    return;
                }
                logsLoaded = true;
                var d = res.data;

                // 文章下拉
                var $cid = $('#cid-select').empty();
                if (d.cidList && d.cidList.length) {
                    $.each(d.cidList, function(i, c) {
                        var sel = (state.cid && state.cid == c.cid) ? ' selected' : '';
                        $cid.append('<option value="' + escapeHtml(String(c.cid)) + '"' + sel + '>' + escapeHtml(String(c.title)) + ' (' + c.count + ')</option>');
                    });
                }

                // 表格
                let html = '';
                if (d.list && d.list.length) {
                    $.each(d.list, function(i, log) {
                        let decodedUrl = log.url;
                        try { decodedUrl = decodeURIComponent(log.url.replace(/%23/g, '#')); } catch(e) {}
                        let ip = log.ip_display || log.ip;
                        html += '<tr data-id="' + log.id + '">';
                        html += '<td><label><input type="checkbox" data-id="' + log.id + '" value="' + log.id + '" name="id[]"/></label></td>';
                        html += '<td><a href="#" class="logs-filter-link" data-filter="path" data-value="' + escapeHtml(String(log.path)) + '">' + escapeHtml(decodedUrl) + '</a></td>';
                        html += '<td><a href="#" data-action="ua" title="' + escapeHtml(String(log.ua)) + '">' + escapeHtml(String(log.display_name)) + '</a></td>';
                        html += '<td><a href="#" data-action="ip" data-ip="' + escapeHtml(String(ip)) + '">' + escapeHtml(String(ip)) + '</a>';
                        if (state.filter !== 'ip') {
                            html += ' <a href="#" class="logs-filter-link right-aligned" data-filter="ip" data-value="' + escapeHtml(String(ip)) + '">[?]</a>';
                        }
                        html += '</td>';
                        html += '<td><a target="_blank" href="' + escapeHtml(String(log.referer)) + '">' + escapeHtml(String(log.referer)) + '</a></td>';
                        html += '<td>' + formatDate(log.time) + '</td>';
                        html += '</tr>';
                    });
                } else {
                    html = '<tr><td colspan="6"><h6 class="typecho-list-table-title">当前无日志</h6></td></tr>';
                }
                $('#logs-table-body').html(html);

                // 分页
                if (d.rows > 1 && d.page) { $('#logs-pager').html(d.page); }

                bindLogEvents();
                bindLogsInlineLinks();
            },
            error: function(xhr) { $('#logs-table-body').html('<tr><td colspan="6">' + escapeHtml(describeError(null, xhr)) + '</td></tr>'); }
        });
    }

    function syncFilterUI(state) {
        $('#logs-filter').val(state.filter);
        $('#logs-type').val(state.type);
        $('#logs-filter-ip, #logs-filter-post, #logs-filter-path').hide();
        if (state.filter === 'ip')   { $('#logs-filter-ip').show().find('input').val(state.ip); }
        if (state.filter === 'post') { $('#logs-filter-post').show(); }
        if (state.filter === 'path') { $('#logs-filter-path').show().find('input').val(state.path); }
        $('#logs-clear-filter').toggle(state.filter !== 'all');
    }

    function bindLogsInlineLinks() {
        // 快捷筛选
        $('#panel-logs .logs-filter-link').off('click').on('click', function() {
            var f = $(this).data('filter'), v = String($(this).data('value'));
            var s = $.extend({}, logsState, { page: 1, filter: f });
            if (f === 'ip')   s.ip   = v;
            if (f === 'path') s.path = v;
            loadLogs(s);
            return false;
        });
        // 分页
        $('#logs-pager').off('click', 'a').on('click', 'a', function(e) {
            e.preventDefault();
            var m = ($(this).attr('href') || '').match(/[?&]page=(\d+)/);
            if (m) loadLogs($.extend({}, logsState, { page: parseInt(m[1], 10) }));
        });
    }

    function bindLogEvents() {
        $('a[data-action="ua"]').off('click').on('click', function() {
            swal('User-Agent', $.trim($(this).attr('title')), 'info');
            return false;
        });
        $('a[data-action="ip"]').off('click').on('click', function() {
            swal('定位查询中...', '正在查询...', 'info');
            $.ajax({
                url: ipApiUrl, method: 'get', dataType: 'json',
                data: { ip: $(this).data('ip') },
                success: function(data) {
                    if (data.code === 0) {
                        let geoData = data.i18n && data.i18n.country !== null ? data.i18n : data.data;
                        let location = [geoData.country, geoData.region, geoData.city].filter(Boolean).join('\n');
                        if (location === '') {
                            swal({ title: '查询成功', text: data.msg || '暂无该 IP 的地理位置信息', icon: 'success' });
                        } else {
                            swal({ title: '查询成功', text: location, icon: 'success' });
                        }
                    } else { swal('查询失败', data.data, 'warning'); }
                },
                error: function() { swal('查询失败', '网络异常或 PHP 环境配置异常', 'error'); }
            });
            return false;
        });
    }

    /* ==================== DOM Ready ==================== */
    /* ==================== 历史数据迁移 ==================== */
    let migrateRunning = false;

    function formatNumber(n) {
        return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function migrateRender(d) {
        let total = d.total || 0;
        let migrated = d.migrated || 0;
        let percent = total > 0 ? Math.min(100, migrated / total * 100) : 0;
        $('#access-migrate-fill').css('width', percent.toFixed(1) + '%');
        $('#access-migrate-text').html(
            '检测到主库中有 <strong>' + formatNumber(total) + '</strong> 条历史统计数据需要迁移到独立数据库，'
            + '已完成 <strong>' + formatNumber(migrated) + '</strong> 条（' + percent.toFixed(1) + '%）。'
        );
    }

    function migrateFinish(message, type) {
        migrateRunning = false;
        $('#access-migrate-start').show().prop('disabled', false).text('开始迁移');
        $('#access-migrate-stop').hide();
        $('#access-migrate-hint').html(message);
        if (type) { swal(type === 'success' ? '迁移完成' : '迁移中断', message, type); }
    }

    function migrateStep() {
        if (!migrateRunning) return;
        $.ajax({
            url: migrateApiUrl, dataType: 'json', timeout: 120000,
            success: function(res) {
                if (!res || res.code !== 0) {
                    migrateFinish('迁移出错：' + ((res && res.data) || '未知错误') + '，可稍后重试或改用命令行脚本。', 'error');
                    return;
                }
                migrateRender(res.data);
                if (res.data.done) {
                    $('#access-migrate-fill').css('width', '100%');
                    migrateFinish('历史数据已全部迁移完成，主库中的旧数据未做改动。', 'success');
                    $('#access-migrate-start').hide();
                    return;
                }
                migrateStep();
            },
            error: function(xhr) {
                let tip = xhr && xhr.status === 404
                    ? '迁移接口未注册，请先在插件管理中禁用并重新启用 Access 插件，或改用命令行脚本。'
                    : '网络中断，已迁移的部分不会丢失，点击“开始迁移”可继续。';
                migrateFinish(tip, 'error');
            }
        });
    }

    function migrateProbe() {
        $.ajax({
            url: migrateApiUrl, dataType: 'json', data: { probe: 1 },
            success: function(res) {
                if (!res || res.code !== 0 || !res.data || !res.data.external) return;
                if (res.data.done || res.data.pending <= 0) return;
                migrateRender(res.data);
                $('#access-migrate').show();
            }
        });
    }

    $(document).ready(function() {

        // 历史数据迁移
        migrateProbe();
        $('#access-migrate-start').on('click', function() {
            migrateRunning = true;
            $(this).hide();
            $('#access-migrate-stop').show();
            $('#access-migrate-hint').text('正在迁移，请勿关闭本页面…');
            migrateStep();
        });
        $('#access-migrate-stop').on('click', function() {
            migrateRunning = false;
            $(this).hide();
            $('#access-migrate-start').show().text('继续迁移');
            $('#access-migrate-hint').text('已暂停，进度已保存，点击“继续迁移”可接着做。');
        });

        // 标签切换
        $('#access-tabs li[data-tab]').on('click', function(e) { e.preventDefault(); switchTab($(this).data('tab')); });

        // 刷新按钮
        $('#access-refresh li[data-tab="refresh"]').on('click', function(e) {
            e.preventDefault();
            if (currentTab === 'overview') {
                overviewLoaded = false;
                // 重置概览面板骨架屏
                $('#panel-overview .access-skeleton').addBack('.access-skeleton').each(function() {
                    $(this).addClass('access-skeleton');
                });
                $('#ov-today-pv, #ov-today-uv, #ov-today-ip, #ov-yesterday-pv, #ov-yesterday-uv, #ov-yesterday-ip, #ov-total-pv, #ov-total-uv, #ov-total-ip').text('--').addClass('access-skeleton');
                $('#referer-domain-body').html('<tr><td colspan="3" class="access-skeleton">加载中…</td></tr>');
                $('#referer-url-body').html('<tr><td colspan="3" class="access-skeleton">加载中…</td></tr>');
                $('#chart-today, #chart-yesterday, #chart-month, #chart-post-pie').empty().addClass('access-skeleton');
                loadOverview();
            } else if (currentTab === 'logs') {
                logsLoaded = false;
                loadLogs(logsState);
            }
        });

        // 初始加载
        if (initAction === 'overview') { loadOverview(); } else { loadLogs(logsState); }

        // 筛选类型切换
        $('#logs-filter').on('change', function() {
            $('#logs-filter-ip, #logs-filter-post, #logs-filter-path').hide();
            switch ($(this).val()) {
                case 'ip':   $('#logs-filter-ip').show();   break;
                case 'post': $('#logs-filter-post').show(); break;
                case 'path': $('#logs-filter-path').show(); break;
            }
        });

        // 筛选按钮
        $('#logs-search-btn').on('click', function() {
            var filter = $('#logs-filter').val();
            var ipRegex = /^(25[0-5]|2[0-4]\d|[01]?\d\d?)\.(25[0-5]|2[0-4]\d|[01]?\d\d?)\.(25[0-5]|2[0-4]\d|[01]?\d\d?)\.(25[0-5]|2[0-4]\d|[01]?\d\d?)$/;
            if (filter === 'ip' && !ipRegex.test($('#logs-filter-ip input').val())) {
                return swal('筛选条件错误', 'IP 地址不合法', 'warning');
            }
            var s = { page: 1, type: $('#logs-type').val(), filter: filter, ip: '', cid: '', path: '' };
            if (filter === 'ip')   s.ip   = $('#logs-filter-ip input').val();
            if (filter === 'post') s.cid  = $('#cid-select').val();
            if (filter === 'path') s.path = $('#logs-filter-path input').val();
            loadLogs(s);
        });

        // 取消筛选
        $('#logs-clear-filter').on('click', function(e) {
            e.preventDefault();
            loadLogs({ page: 1, type: logsState.type, filter: 'all', ip: '', cid: '', path: '' });
        });

        // 全选
        $(document).on('click', '.typecho-table-select-all', function() {
            var checked = $(this).prop('checked');
            $('.typecho-list-table input[type="checkbox"]').prop('checked', checked);
            $('.typecho-table-select-all').prop('checked', checked);
        });

        // 行点击
        $(document).on('click', '.typecho-list-table tr', function(e) {
            if ($(e.target).is('input[type="checkbox"]') || $(e.target).is('a') || $(e.target).is('button')) {
                return;
            }
            var $checkbox = $(this).find('input[type="checkbox"]');
            $checkbox.prop('checked', !$checkbox.prop('checked'));
        });

        // 删除
        $(document).on('click', 'button[data-action="delete"]', function() {
            swal({
                title: '确认操作', text: '是否删除选定的记录？', type: 'warning',
                showCancelButton: true, confirmButtonColor: '#DD6B55',
                confirmButtonText: '是', cancelButtonText: '否', closeOnConfirm: false
            }, function(isConfirm) {
                if (!isConfirm) return;
                var ids = [];
                $('.typecho-list-table input[type="checkbox"]').each(function() {
                    if (this.checked) ids.push($(this).data('id'));
                });
                if (!ids.length) return swal('错误', '并没有勾选任何内容', 'warning');
                $.ajax({
                    url: deleteApiUrl, method: 'post', dataType: 'json',
                    contentType: 'application/json', data: JSON.stringify(ids),
                    success: function(data) {
                        if (data.code === 0) {
                            swal('删除成功', '所选记录已删除', 'success');
                            $.each(ids, function(i, id) {
                                $('tr[data-id="' + id + '"]').fadeOut(500, function() { $(this).remove(); });
                            });
                        } else { swal('抱歉', '发生了错误', 'warning'); }
                    }
                });
            });
            $(this).parents('.dropdown-menu').hide().prev().removeClass('active');
        });
    });
})();
</script>
<?php include 'footer.php'; ?>
