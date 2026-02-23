import htmx from "htmx.org";

export default class Page {

  tableButtons = {
    edit: `
      <button type="button" class="btn btn-tool table-btn" data-type="edit" data-bs-toggle="tooltip" data-bs-title="修改">
        <i class="fas fa-edit"></i>
      </button>
    `,
    delete: `
      <button type="button" class="btn btn-tool table-btn" data-type="delete" data-bs-toggle="tooltip" data-bs-title="删除">
        <i class="fas fa-trash-alt"></i>
      </button>
    `
  };

  constructor(name) {
    this.name = name;
    this.root = null;
    this.table = null;
    this._events = [];
    this._plugins = [];
  }

  mount(root) {
    this.root = root;
    this.init();
    this.initTooltips();
    this.initDataTable();
    // AdminLTE card collapse
    this.on('click', '[data-lte-toggle="card-collapse"]', this.onCardCollapse.bind(this));

    // DataTable 行操作
    this.on('click', '.dataTable button[data-type]', this.onTableAction.bind(this));
    console.log('Mount page ' + this.name);
  }

  unmount() {
    // 销毁 tooltip
    this.root?.querySelectorAll('[data-bs-toggle="tooltip"]')
      .forEach(el => {
        window.bootstrap.Tooltip.getInstance(el)?.dispose();
      });
    this.destroy();
    this._clearEvents();
    this._destroyPlugins();
    this.root = null;
    this.table = null;
    console.log('Unmounting page ' + this.name);
  }

  init() {
  }

  destroy() {
  }

  initDataTable() {
  }

  getTableButtons(buttons = []) {
    return buttons.map(name => this.tableButtons[name]).filter(Boolean).join('');
  }

  getTableRowData(id) {
    return this.table.instance.row(`#${id}`).data();
  }

  onCardCollapse(e) {
    e.preventDefault();
    const cardEl = e.currentTarget.closest('.card');
    if (!cardEl || !window.adminlte?.CardWidget) return;
    new window.adminlte.CardWidget(cardEl).toggle();
  }

  onTableAction(e) {
    const btn = e.currentTarget;
    const type = btn.dataset?.type;
    if (!type) return;

    const tr = btn.closest('tr');
    if (!tr) return;

    const row = this.getTableRowData?.(tr.id);
    if (!row) return;

    const handler = this.getTableActionHandler(type);
    handler?.call(this, btn, row);
  }

  getTableActionHandler(type) {
    const map = {
      edit: this.onEdit,
      delete: this.onDelete
    };
    return map[type];
  }

  onEdit(target, row) {
    if (!row?.edit) return;

    const edit = row.edit;
    const modal = edit?.modal ?? {};

    const path = typeof edit === 'string' ? edit : edit?.path;

    if (!path) return;

    target.dataset.modalName = modal.name ?? '';
    target.dataset.modalSize = modal.size ?? '';
    target.dataset.modalBackdrop = modal.backdrop ?? '';

    htmx.ajax('get', path, {source: target, target: 'body', swap: 'beforeend'}).then();
  }

  onDelete(target, row) {
    if (!row?.delete) return;

    const del = row.delete;
    const message = del?.message ?? '是否确认要删除';
    const path = typeof del === 'string' ? del : del?.path;

    if (!path) return;

    window.confirmDialog(message, () => {
      htmx.ajax('delete', path, {swap: 'none'}).then();
    });
  }


  $(selector) {
    return $(this.root).find(selector);
  }

  formatDateTime(data) {
    if (!data) return '<span class="text-muted">-</span>';
    const date = new Date(data * 1000);
    return date.toLocaleString('zh-CN', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: false
    }).replaceAll('/', '-');
  }

  formatFileSize(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    // 计算对数索引
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    // 返回格式化后的字符串，如 1.25 MB
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  }

  initTooltips(root = this.root) {
    root?.querySelectorAll('[data-bs-toggle="tooltip"]')
      .forEach(el => {
        if (!window.bootstrap.Tooltip.getInstance(el)) {
          new window.bootstrap.Tooltip(el);
        }
      });
  }

  on(event, selector, handler) {
    const namespaced = `${event}.${this.name}`;
    $(this.root).on(namespaced, selector, handler);
    this._events.push(namespaced);
  }

  use(plugin) {
    this._plugins.push(plugin);
  }

  _clearEvents() {
    this._events.forEach(e => $(this.root).off(e));
    this._events = [];
  }

  _destroyPlugins() {
    this._plugins.forEach(p => p?.destroy?.());
    this._plugins = [];
  }
}
