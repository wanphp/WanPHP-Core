export const PageRegistry = Object.create(null);

export function registerPage(name, def) {
  PageRegistry[name] = def;
}

export const ModalRegistry = Object.create(null);

export function registerModal(name, def) {
  ModalRegistry[name] = def;
}

export const DataTableRegistry = Object.create(null);

export function registerDataTable(name, def) {
  if (DataTableRegistry[name]) {
    console.warn(`DataTable ${name} already registered`);
  }
  DataTableRegistry[name] = def;
  console.log('DataTableRegistry ' + name, DataTableRegistry);
  return {
    destroy() {
      DataTableRegistry[name]?.destroy();
      delete DataTableRegistry[name];
      console.log('DataTableRegistry destroyed ' + name, DataTableRegistry);
    }
  }
}

export const DataTableFactory = (function () {

  const defaults = {
    serverSide: true,
    processing: true,//是否显示处理状态(排序的时候，数据很多耗费时间长的话，也会显示这个)
    lengthChange: true,//是否允许用户改变表格每页显示的记录数
    orderMulti: false,  //启用多列排序
    ordering: false,//使用排序
    bStateSave: true,//记录cookie
    paging: true,//是否分页
    pagingType: "full_numbers",//除首页、上一页、下一页、末页四个按钮还有页数按钮
    searching: true,//是否开始本地搜索
    stateSave: true,//刷新时是否保存状态
    autoWidth: true,//自动计算宽度
    deferRender: true,//延迟渲染
    search: {return: true},
    responsive: true,//自适应
    columnDefs: [
      {responsivePriority: 1, targets: 1}, // 第一列优先显示
      {responsivePriority: 2, targets: -1} // 最后一列次优先显示
    ],
    language: {
      "decimal": "",//小数的小数位符号  比如“，”作为数字的小数位符号
      "emptyTable": "暂无数据",//没有数据时要显示的字符串
      "info": "当前 _START_ 条到 _END_ 条 共 _TOTAL_ 条",//左下角的信息，变量可以自定义，到官网详细查看
      "infoEmpty": "无记录",//当没有数据时，左下角的信息
      "infoFiltered": "(从 _MAX_ 条记录过滤)",//当表格过滤的时候，将此字符串附加到主要信息
      "infoPostFix": "",//在摘要信息后继续追加的字符串
      "thousands": ",",//千分位分隔符
      "lengthMenu": "每页 _MENU_ 条记录",//用来描述分页长度选项的字符串
      "loadingRecords": "加载中...",//用来描述数据在加载中等待的提示字符串 - 当异步读取数据的时候显示
      "processing": "处理中...",//用来描述加载进度的字符串
      "search": "表格中搜索",//用来描述搜索输入框的字符串
      "zeroRecords": "没有找到",//当没有搜索到结果时，显示
      "paginate": {
        "first": "首页",
        "previous": "上一页",
        "next": "下一页",
        "last": "尾页"
      }
    }
  };

  function create($el, options = {}) {
    // 防止重复初始化
    if ($.fn.DataTable.isDataTable($el)) {
      $el.DataTable().destroy(true);
    }
    $.fn.dataTable.ext.errMode = 'none';
    const table = $el.DataTable($.extend(true, {}, defaults, options));
    table.on('xhr.dt', function (e, settings) {
      const xhr = settings.jqXHR;
      if (!xhr) return;
      if (xhr.status >= 400) {
        let msg = '请求失败';
        if (xhr.responseText) {
          try {
            msg = JSON.parse(xhr.responseText)?.message || msg;
          } catch (e) {
          }
        }
        Toast.fire({icon: 'error', title: msg});
      }
    });
    return {
      reload() {
        table.ajax.reload(null, false);
      },
      destroy() {
        table.off('xhr.dt')
        table.destroy(true);
      },
      removeRow(id) {
        table.row('#' + id).remove().draw(false);
      },
      instance: table
    };
  }

  return {create};
})();
