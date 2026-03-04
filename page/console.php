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
?>
<link rel="stylesheet" href="<?php $options->pluginUrl('Access/css/style.css?v=3.0.0')?>">
<link rel="stylesheet" href="<?php $options->pluginUrl('Access/sweetalert/sweetalert.css')?>">
<main class="main">
    <div class="body container">
        <div class="typecho-page-title">
           <h2><?php echo $access->title;?></h2>
        </div>
        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12 typecho-list">
                <div class="typecho-list-operate">
                    <ul class="typecho-option-tabs">
                        <li <?=($access->action == 'overview' ? ' class="current"' : '')?>><a href="<?php $options->adminUrl('extending.php?panel=' . AccessPlugin::$panel . '&action=overview'); ?>"><?php _e('访问概览'); ?></a></li>
                        <li <?=($access->action == 'logs' ? ' class="current"' : '')?>><a href="<?php $options->adminUrl('extending.php?panel=' . AccessPlugin::$panel . '&action=logs'); ?>"><?php _e('访问日志'); ?></a></li>
                        <li><a href="<?php $options->adminUrl('options-plugin.php?config=Access') ?>"><?php _e('插件设置'); ?></a></li>
                    </ul>
                </div>
                <?php if($access->action == 'logs'):?>
                <form class="typecho-list-operate search-form" method="get">
                    <div class="operate">
                        <label><i class="sr-only"><?php _e('全选'); ?></i><input class="typecho-table-select-all" type="checkbox" /></label>
                        <div class="btn-group btn-drop">
                            <button class="btn dropdown-toggle btn-s" type="button"><i class="sr-only"><?php _e('操作'); ?></i><?php _e('选中项'); ?> <i class="i-caret-down"></i></button>
                            <ul class="dropdown-menu">
                                <li><a data-action="delete" href="javascript:"><?php _e('删除'); ?></a></li>
                            </ul>
                        </div>
                    </div>
                    <div class="search" role="search">
                        <?php if ($request->get('filter', 'all') != 'all'): ?>
                            <a href="<?php $options->adminUrl('extending.php?panel=' . AccessPlugin::$panel . '&action=logs'); ?>"><?php _e('&laquo; 取消筛选'); ?></a>
                        <?php endif; ?>
                        <input type="hidden" value="<?php echo $request->get('panel'); ?>" name="panel" />
                        <?php if(isset($request->page)): ?>
                            <input type="hidden" value="<?php echo $request->get('page'); ?>" name="page" />
                        <?php endif; ?>
                        <label>
                            <select name="filter">
                                <option <?php if($request->filter == 'all'): ?> selected <?php endif; ?>value="all"><?php _e('全部条件'); ?></option>
                                <option <?php if($request->filter == 'ip'): ?> selected <?php endif; ?>value="ip"><?php _e('按IP'); ?></option>
                                <option <?php if($request->filter == 'post'): ?> selected <?php endif; ?>value="post"><?php _e('按文章'); ?></option>
                                <option <?php if($request->filter == 'path'): ?> selected <?php endif; ?>value="path"><?php _e('按路由'); ?></option>
                            </select>
                        </label>
                        <label style="<?php if($request->get('filter', 'all') != 'ip'): ?>display: none<?php endif; ?>">
                            <input type="text" class="text-s" placeholder="" value="<?php echo htmlspecialchars($request->ip); ?>" name="ip" />
                        </label>
                        <label style="<?php if($request->get('filter', 'all') != 'post'): ?>display: none<?php endif; ?>">
                            <select name="cid">
                                <?php foreach ($access->logs['cidList'] as $content):?>
                                    <option <?php if($request->cid == $content['cid']): ?> selected <?php endif; ?>value="<?php echo $content['cid'];?>"><?php echo $content['title'];?> (<?php echo $content['count'];?>)</option>
                                <?php endforeach;?>
                            </select>
                        </label>
                        <label style="<?php if($request->get('filter', 'all') != 'path'): ?>display: none<?php endif; ?>">
                            <input type="text" class="text-s" placeholder="" value="<?php echo htmlspecialchars($request->path); ?>" name="path" />
                        </label>
                        <label>
                            <select name="type">
                                <option <?php if($request->type == 1): ?> selected <?php endif; ?>value="1"><?php _e('默认（仅人类）'); ?></option>
                                <option <?php if($request->type == 2): ?> selected <?php endif; ?>value="2"><?php _e('筛选（仅爬虫）'); ?></option>
                                <option <?php if($request->type == 3): ?> selected <?php endif; ?>value="3"><?php _e('所有'); ?></option>
                            </select>
                        </label>
                        <input type="hidden" name="page" value="1">
                        <button type="button" class="btn btn-s"><?php _e('筛选'); ?></button>
                    </div>
                </form>
                <form class="operate-form" method="post">
                    <div class="typecho-table-wrap">
                        <table class="typecho-list-table">
                            <colgroup>
                                <col width="5%"/>
                                <col width="20%"/>
                                <col width="25%"/>
                                <col width="18%"/>
                                <col width="20%"/>
                                <col width="15%"/>
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
                            <tbody>
                            <?php if(!empty($access->logs['list'])): ?>
                                <?php foreach ($access->logs['list'] as $log): ?>
                                    <tr id="<?php echo $log['id']; ?>" data-id="<?php echo $log['id']; ?>">
                                        <td><label><input type="checkbox" data-id="<?php echo $log['id']; ?>" value="<?php echo $log['id']; ?>" name="id[]"/></label></td>
                                        <td><a target="_self" href="<?php $options->adminUrl('extending.php?panel=' . AccessPlugin::$panel . '&filter=path&path=' . $log['path'] . '&type='. $request->type); ?>"><?php echo urldecode(str_replace("%23", "#", $log['url'])); ?></a></td>
                                        <td><a data-action="ua" href="#" title="<?php echo $log['ua'];?>"><?php echo $log['display_name']; ?></a></td>
                                        <td><a data-action="ip" data-ip="<?php echo $access->long2ip($log['ip']); ?>" href="#"><?php echo $access->long2ip($log['ip']); ?></a><?php if($request->filter != 'ip'): ?> <a target="_self" href="<?php $options->adminUrl('extending.php?panel=' . AccessPlugin::$panel . '&filter=ip&ip=' . $access->long2ip($log['ip']) . '&type='. $request->type); ?>">[ ? ]</a><?php endif; ?></td>
                                        <td><a target="_blank" data-action="referer" href="<?php echo $log['referer']; ?>"><?php echo $log['referer']; ?></a></td>
                                        <td><?php echo date('Y-m-d H:i:s',$log['time']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6"><h6 class="typecho-list-table-title"><?php _e('当前无日志'); ?></h6></td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form><!-- end .operate-form -->
                <form class="typecho-list-operate" method="get">
                    <div class="operate">
                        <label><i class="sr-only"><?php _e('全选'); ?></i><input type="checkbox" class="typecho-table-select-all" /></label>
                        <div class="btn-group btn-drop">
                            <button class="btn dropdown-toggle btn-s" type="button"><i class="sr-only"><?php _e('操作'); ?></i><?php _e('选中项'); ?> <i class="i-caret-down"></i></button>
                            <ul class="dropdown-menu">
                                <li><a data-action="delete" href="javascript:"><?php _e('删除'); ?></a></li>
                            </ul>
                        </div>
                    </div>
                    <?php if($access->logs['rows'] > 1): ?>
                        <ul class="typecho-pager">
                            <?php echo $access->logs['page']; ?>
                        </ul>
                    <?php endif; ?>
                </form>
            </div><!-- end .typecho-list -->

            <?php elseif($access->action == 'overview'):?>

            <div class="col-mb-12 typecho-list">

               <h4 class="typecho-list-table-title">访问数据总览</h4>

                <div class="typecho-table-wrap">
                    <table class="typecho-list-table">
                        <colgroup>
                            <col width="10%"/>
                            <col width="30%"/>
                            <col width="25%"/>
                            <col width=""/>
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
                                <td>今日</td>
                                <td id="ov-today-pv" class="access-skeleton">--</td>
                                <td id="ov-today-uv" class="access-skeleton">--</td>
                                <td id="ov-today-ip" class="access-skeleton">--</td>
                            </tr>
                            <tr>
                                <td>昨日</td>
                                <td id="ov-yesterday-pv" class="access-skeleton">--</td>
                                <td id="ov-yesterday-uv" class="access-skeleton">--</td>
                                <td id="ov-yesterday-ip" class="access-skeleton">--</td>
                            </tr>
                            <tr>
                                <td>总计</td>
                                <td id="ov-total-pv" class="access-skeleton">--</td>
                                <td id="ov-total-uv" class="access-skeleton">--</td>
                                <td id="ov-total-ip" class="access-skeleton">--</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

               <h4 class="typecho-list-table-title">来源域名 Top 20</h4>

                <div class="typecho-table-wrap">
                    <table class="typecho-list-table">
                        <colgroup>
                            <col width="10%"/>
                            <col width="10%"/>
                            <col width="80%"/>
                        </colgroup>
                        <thead>
                            <tr>
                                <th>排名</th>
                                <th>次数</th>
                                <th>来源 DOMAIN</th>
                            </tr>
                        </thead>
                        <tbody id="referer-domain-body">
                            <tr><td colspan="3" class="access-skeleton"><?php _e('加载中…'); ?></td></tr>
                        </tbody>
                    </table>
                </div>

               <h4 class="typecho-list-table-title">来源页面 Top 20</h4>

                <div class="typecho-table-wrap">
                    <table class="typecho-list-table">
                        <colgroup>
                            <col width="10%"/>
                            <col width="10%"/>
                            <col width="80%"/>
                        </colgroup>
                        <thead>
                            <tr>
                                <th>排名</th>
                                <th>次数</th>
                                <th>来源 URL</th>
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
            </div><!-- end .typecho-list -->
            <?php endif;?>
        </div><!-- end .typecho-page-main -->
    </div>
</main>

<?php
include 'copyright.php';
include 'common-js.php';
include 'table-js.php';
?>
<script type="text/javascript">
$(document).ready(function() {
    $('a[data-action="ua"]').click(function() {
        swal('User-Agent', $.trim($(this).attr('title')), 'info');
        return false;
    });

    $('a[data-action="ip"]').click(function() {
        swal('IP 查询中...', '正在查询...', 'info');
        $.ajax({
            url: '<?php echo rtrim(Helper::options()->index, '/').'/access/ip.json';?>',
            method: 'get',
            dataType: 'json',
            data: {ip: $(this).data('ip')},
            success: function(data) {
                if (data.code === 0) {
                    swal({
                        title: "IP 查询成功",
                        text: "[" + data.data.country + "]" + "[" + data.data.regionName + "]",
                        icon: "success",
                    });
                } else {
                    swal('IP 查询失败', data.data, 'warning');
                }
            },
            error: function() {
                swal('IP查询失败', '网络异常或 PHP 环境配置异常', 'error');
            }
        });
        return false;
    });

    $('.dropdown-menu a[data-action="delete"]').click(function() {
        swal({
          title: '确认操作',
          text: '是否删除选定的记录？',
          type: 'warning',
          showCancelButton: true,
          confirmButtonColor: '#DD6B55',
          confirmButtonText: '是',
          cancelButtonText: '否',
          closeOnConfirm: false
        }, function() {
            const ids = [];
            $('.typecho-list-table input[type="checkbox"]').each(function(index, elem) {
                    if (elem.checked) {
                        ids.push($(elem).data('id'));
                    }
                });

                if (ids.length == 0) {
                    return swal('错误', '并没有勾选任何内容', 'warning');
                }
                $.ajax({
                    url: '<?php echo rtrim(Helper::options()->index, '/').'/access/log/delete.json';?>',
                    method: 'post',
                    dataType: 'json',
                    contentType: 'application/json',
                    data: JSON.stringify(ids),
                    success: function(data) {
                        if (data.code == 0) {
                            swal('删除成功', '所选记录已删除', 'success');
                            $.each(ids, function(index, elem) {
                                $('.typecho-list-table tbody tr[data-id="' + elem + '"]').fadeOut(500).remove();
                            });
                        } else {
                            swal('抱歉', '发生了错误', 'warning');
                        }
                    }
                });
        });
        const $this = $(this);
        $this.parents('.dropdown-menu').hide().prev().removeClass('active');
    });

    const $form = $('form.search-form');
    const $ipInput = $form.find('input[name="ip"]');
    const $cidSelect = $form.find('select[name="cid"]');
    const $pathInput = $form.find('input[name="path"]');
    const $filterSelect = $form.find('select[name="filter"]');

    $filterSelect.on('change', function() {
        $ipInput.removeAttr('placeholder').val('').parent('label').hide();
        $cidSelect.parent('label').hide();
        $pathInput.removeAttr('placeholder').val('').parent('label').hide();

        switch ($filterSelect.val()) {
            case 'ip':
                $ipInput.attr('placeholder', '输入IP').parent('label').show();
                break;
            case 'post':
                $cidSelect.parent('label').show();
                break;
            case 'path':
                $pathInput.attr('placeholder', '输入路由').parent('label').show();
                break;
        }
    });

    $form.find('button[type="button"]').on('click', function() {
        const ipRegex = /^(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;

        if ($filterSelect.val() == 'ip' && !ipRegex.test($ipInput.val())) {
            return swal('筛选条件错误', 'IP 地址不合法', 'warning');
        }

        $form.submit();
    });
});
</script>
<script src="<?php $options->pluginUrl('Access/sweetalert/sweetalert.min.js')?>"></script>
<?php if($access->action == 'overview'):?>
<script src="https://cdnjs.loli.net/ajax/libs/highcharts/11.0.1/highcharts.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://cdnjs.loli.net/ajax/libs/highcharts/11.0.1/modules/series-label.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://cdnjs.loli.net/ajax/libs/highcharts/11.0.1/modules/exporting.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://cdnjs.loli.net/ajax/libs/highcharts/11.0.1/modules/export-data.src.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script type="text/javascript">
    var printChart = function(target, data) {
        target.highcharts({
            title: {text: data['title'], x: -20},
            subtitle: {text: data['sub_title'], x: -20},
            xAxis: {categories: data['xAxis']},
            yAxis: {title: {text: '数量'},plotLines: [{value: 0,width: 1,color: '#808080'}]},
            tooltip: {valueSuffix: ''},
            plotOptions: {line: {dataLabels: {enabled: true},enableMouseTracking: false}},
            series: [
                {name: 'PV（浏览）',data: data['pv']['detail']},
                {name: 'UV（访客）',data: data['uv']['detail']},
                {name: 'IP（地址）',data: data['ip']['detail']}
            ]
        });
    };

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    $(document).ready(function() {
        $.ajax({
            url: '<?php echo rtrim(Helper::options()->index, '/') . '/access/overview.json'; ?>',
            method: 'get',
            dataType: 'json',
            success: function(res) {
                if (res.code !== 0) {
                    $('.access-skeleton').text('加载失败');
                    return;
                }
                var d = res.data;

                // ── 数据总览表格 ──
                $('#ov-today-pv').text(d.overview.today.pv.count).removeClass('access-skeleton');
                $('#ov-today-uv').text(d.overview.today.uv.count).removeClass('access-skeleton');
                $('#ov-today-ip').text(d.overview.today.ip.count).removeClass('access-skeleton');
                $('#ov-yesterday-pv').text(d.overview.yesterday.pv.count).removeClass('access-skeleton');
                $('#ov-yesterday-uv').text(d.overview.yesterday.uv.count).removeClass('access-skeleton');
                $('#ov-yesterday-ip').text(d.overview.yesterday.ip.count).removeClass('access-skeleton');
                $('#ov-total-pv').text(d.overview.total.pv).removeClass('access-skeleton');
                $('#ov-total-uv').text(d.overview.total.uv).removeClass('access-skeleton');
                $('#ov-total-ip').text(d.overview.total.ip).removeClass('access-skeleton');

                // ── 来源域名 ──
                var domainHtml = '';
                if (d.referer.domain && d.referer.domain.length) {
                    $.each(d.referer.domain, function(i, v) {
                        domainHtml += '<tr><td>' + (i + 1) + '</td><td>' + escapeHtml(String(v.count)) + '</td><td>' + escapeHtml(String(v.value)) + '</td></tr>';
                    });
                } else {
                    domainHtml = '<tr><td colspan="3">暂无数据</td></tr>';
                }
                $('#referer-domain-body').html(domainHtml);

                // ── 来源 URL ──
                var urlHtml = '';
                if (d.referer.url && d.referer.url.length) {
                    $.each(d.referer.url, function(i, v) {
                        urlHtml += '<tr><td>' + (i + 1) + '</td><td>' + escapeHtml(String(v.count)) + '</td><td>' + escapeHtml(String(v.value)) + '</td></tr>';
                    });
                } else {
                    urlHtml = '<tr><td colspan="3">暂无数据</td></tr>';
                }
                $('#referer-url-body').html(urlHtml);

                // ── 图表 ──
                $('#chart-today').removeClass('access-skeleton');
                $('#chart-yesterday').removeClass('access-skeleton');
                $('#chart-month').removeClass('access-skeleton');
                printChart($('#chart-today'), d.chart_data['today']);
                printChart($('#chart-yesterday'), d.chart_data['yesterday']);
                printChart($('#chart-month'), d.chart_data['month']);
            },
            error: function() {
                $('.access-skeleton').text('加载失败');
            }
        });
    });
</script>
<?php endif;?>
<?php
include 'footer.php';
?>
