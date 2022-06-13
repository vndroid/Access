<?php
include_once 'common.php';
include 'header.php';
include 'menu.php';
require_once __DIR__ . '/../Access_Bootstrap.php';
$access = new Access_Core();
?>
<link rel="stylesheet" href="<?php $options->pluginUrl('Access/css/style.css?v20200714')?>">
<link rel="stylesheet" href="<?php $options->pluginUrl('Access/sweetalert/sweetalert.css')?>">
<div class="main">
    <div class="body container">
        <div class="typecho-page-title">
           <h2><?php echo $access->title;?></h2>
        </div>
        <div class="row typecho-page-main" role="main">
             <div class="col-mb-12">
                <ul class="typecho-option-tabs fix-tabs clearfix">
                    <li <?=($access->action == 'overview' ? ' class="current"' : '')?>><a href="<?php $options->adminUrl('extending.php?panel=' . Access_Plugin::$panel . '&action=overview'); ?>"><?php _e('访问概览'); ?></a></li>
                    <li <?=($access->action == 'logs' ? ' class="current"' : '')?>><a href="<?php $options->adminUrl('extending.php?panel=' . Access_Plugin::$panel . '&action=logs'); ?>"><?php _e('访问日志'); ?></a></li>
                    <li><a href="<?php $options->adminUrl('options-plugin.php?config=Access') ?>"><?php _e('插件设置'); ?></a></li>
                </ul>
            </div>

            <?php if($access->action == 'logs'):?>

            <div class="col-mb-12 typecho-list">
                <div class="typecho-list-operate clearfix">

                    <div class="operate">
                        <label><i class="sr-only"><?php _e('全选'); ?></i><input type="checkbox" class="typecho-table-select-all" /></label>
                        <div class="btn-group btn-drop">
                            <button class="btn dropdown-toggle btn-s" type="button"><i class="sr-only"><?php _e('操作'); ?></i><?php _e('选中项'); ?> <i class="i-caret-down"></i></button>
                            <ul class="dropdown-menu">
                                <li><a data-action="delete" href="javascript:;"><?php _e('删除'); ?></a></li>
                            </ul>
                        </div>
                    </div>

                    <form method="get" class="search-form">
                        <div class="search" role="search">
                            <?php if ($request->get('filter', 'all') != 'all'): ?>
                            <a href="<?php $options->adminUrl('extending.php?panel=' . Access_Plugin::$panel . '&action=logs'); ?>"><?php _e('&laquo; 取消筛选'); ?></a>
                            <?php endif; ?>
                            <input type="hidden" value="<?php echo $request->get('panel'); ?>" name="panel" />
                            <?php if(isset($request->page)): ?>
                            <input type="hidden" value="<?php echo $request->get('page'); ?>" name="page" />
                            <?php endif; ?>
                            <select name="filter">
                                <option <?php if($request->filter == 'all'): ?> selected="true"<?php endif; ?>value="all"><?php _e('全部条件'); ?></option>
                                <option <?php if($request->filter == 'ip'): ?> selected="true"<?php endif; ?>value="ip"><?php _e('按IP'); ?></option>
                                <option <?php if($request->filter == 'post'): ?> selected="true"<?php endif; ?>value="post"><?php _e('按文章'); ?></option>
                                <option <?php if($request->filter == 'path'): ?> selected="true"<?php endif; ?>value="path"><?php _e('按路由'); ?></option>
                            </select>
                            <input style="<?php if($request->get('filter', 'all') != 'ip'): ?>display: none<?php endif; ?>" type="text" class="text-s" placeholder="" value="<?php echo htmlspecialchars($request->ip); ?>" name="ip" />
                            <select style="<?php if($request->get('filter', 'all') != 'post'): ?>display: none<?php endif; ?>" name="cid">
                                <?php foreach ($access->logs['cidList'] as $content):?>
                                <option <?php if($request->cid == $content['cid']): ?> selected="true"<?php endif; ?>value="<?php echo $content['cid'];?>"><?php echo $content['title'];?> (<?php echo $content['count'];?>)</option>
                                <?php endforeach;?>
                            </select>
                            <input style="<?php if($request->get('filter', 'all') != 'path'): ?>display: none<?php endif; ?>" type="text" class="text-s" placeholder="" value="<?php echo htmlspecialchars($request->path); ?>" name="path" />
                            <select name="type">
                                <option <?php if($request->type == 1): ?> selected="true"<?php endif; ?>value="1"><?php _e('默认（仅人类）'); ?></option>
                                <option <?php if($request->type == 2): ?> selected="true"<?php endif; ?>value="2"><?php _e('筛选（仅爬虫）'); ?></option>
                                <option <?php if($request->type == 3): ?> selected="true"<?php endif; ?>value="3"><?php _e('所有'); ?></option>
                            </select>
                                <input type="hidden" name="page" value="1">
                                <button type="button" class="btn btn-s"><?php _e('筛选'); ?></button>
                        </div>
                    </form>
                </div><!-- end .typecho-list-operate -->

                <form method="post" class="operate-form">
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
                                <th> </th>
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
                                <td><input type="checkbox" data-id="<?php echo $log['id']; ?>" value="<?php echo $log['id']; ?>" name="id[]"/></td>
                                <td><a target="_self" href="<?php $options->adminUrl('extending.php?panel=' . Access_Plugin::$panel . '&filter=path&path=' . $log['path'] . '&type='. $request->type); ?>"><?php echo urldecode(str_replace("%23", "#", $log['url'])); ?></a></td>
                                <td><a data-action="ua" href="#" title="<?php echo $log['ua'];?>"><?php echo $log['display_name']; ?></a></td>
                                <td><a data-action="ip" data-ip="<?php echo $access->long2ip($log['ip']); ?>" href="#"><?php echo $access->long2ip($log['ip']); ?></a><?php if($request->filter != 'ip'): ?> <a target="_self" href="<?php $options->adminUrl('extending.php?panel=' . Access_Plugin::$panel . '&filter=ip&ip=' . $access->long2ip($log['ip']) . '&type='. $request->type); ?>">[ ? ]</a><?php endif; ?></td>
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

                <div class="typecho-list-operate clearfix">
                    <form method="get">

                        <div class="operate">
                            <label><i class="sr-only"><?php _e('全选'); ?></i><input type="checkbox" class="typecho-table-select-all" /></label>
                            <div class="btn-group btn-drop">
                                <button class="btn dropdown-toggle btn-s" type="button"><i class="sr-only"><?php _e('操作'); ?></i><?php _e('选中项'); ?> <i class="i-caret-down"></i></button>
                                <ul class="dropdown-menu">
                                    <li><a data-action="delete" href="javascript:;"><?php _e('删除'); ?></a></li>
                                </ul>
                            </div>
                        </div>


                        <?php if($access->logs['rows'] > 1): ?>
                        <ul class="typecho-pager">
                            <?php echo $access->logs['page']; ?>
                        </ul>
                        <?php endif; ?>
                    </form>
                </div><!-- end .typecho-list-operate -->
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
                                <td><?php echo $access->overview['today']['pv']['count'];?></td>
                                <td><?php echo $access->overview['today']['uv']['count'];?></td>
                                <td><?php echo $access->overview['today']['ip']['count'];?></td>
                            </tr>
                            <tr>
                                <td>昨日</td>
                                <td><?php echo $access->overview['yesterday']['pv']['count'];?></td>
                                <td><?php echo $access->overview['yesterday']['uv']['count'];?></td>
                                <td><?php echo $access->overview['yesterday']['ip']['count'];?></td>
                            </tr>
                            <tr>
                                <td>总计</td>
                                <td><?php echo $access->overview['total']['pv'];?></td>
                                <td><?php echo $access->overview['total']['uv'];?></td>
                                <td><?php echo $access->overview['total']['ip'];?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

               <h4 class="typecho-list-table-title">来源域名</h4>

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
                                <th>来源域名</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($access->referer['domain'] as $key => $value):?>
                            <tr>
                                <td><?php echo $key +1 ?></td>
                                <td><?php echo $value['count']?></td>
                                <td><?php echo $value['value']?></td>
                            </tr>
                            <?php endforeach;?>
                        </tbody>
                    </table>
                </div>

               <h4 class="typecho-list-table-title">来源页</h4>

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
                                <th>来源URL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($access->referer['url'] as $key => $value):?>
                            <tr>
                                <td><?php echo $key +1 ?></td>
                                <td><?php echo $value['count']?></td>
                                <td><?php echo $value['value']?></td>
                            </tr>
                            <?php endforeach;?>
                        </tbody>
                    </table>
                </div>
                <h4 class="typecho-list-table-title">今日图表</h4>
                <div class="typecho-table-wrap" id="chart-today"></div>

                <h4 class="typecho-list-table-title">昨日图表</h4>
                <div class="typecho-table-wrap" id="chart-yesterday"></div>

                <h4 class="typecho-list-table-title">当月图表</h4>
                <div class="typecho-table-wrap" id="chart-month"></div>
            </div><!-- end .typecho-list -->


            <?php endif;?>

        </div><!-- end .typecho-page-main -->
    </div>
</div>

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
                if (data.code == 0) {
                    swal('IP 查询成功', data.data, 'success');
                } else {
                    swal('IP 查询失败', data.data, 'warning');
                }
            },
            error: function() {
                swal('IP查询失败', '网络异常或 PHP 环境配置异常', 'warning');
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
        $ipInput.removeAttr('placeholder').val('').hide();
        $cidSelect.hide();
        $pathInput.removeAttr('placeholder').val('').hide();

        switch ($filterSelect.val()) {
            case 'ip':
                $ipInput.attr('placeholder', '输入IP').show();
                break;
            case 'post':
                $cidSelect.show();
                break;
            case 'path':
                $pathInput.attr('placeholder', '输入路由').show();
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
<script src="<?php $options->pluginUrl('Access/highcharts/highcharts.js')?>"></script>
<script src="<?php $options->pluginUrl('Access/highcharts/modules/series-label.js')?>"></script>
<script src="<?php $options->pluginUrl('Access/highcharts/modules/exporting.js')?>"></script>
<script src="<?php $options->pluginUrl('Access/highcharts/modules/export-data.js')?>"></script>
<script type="text/javascript">
    chartData = <?php echo $access->overview['chart_data'] ?>;
    printChart = function(target, data) {
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
    }
    $(document).ready(function() {
        printChart($('#chart-today'), chartData['today']);
        printChart($('#chart-yesterday'), chartData['yesterday']);
        printChart($('#chart-month'), chartData['month']);
    });

</script>
<?php endif;?>
<?php
include 'footer.php';
?>
