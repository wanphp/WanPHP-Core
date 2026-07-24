import htmx from "htmx.org";

export default class Page {

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

  getTableButtons(buttons = {}, btnType = 'tool') {
    // 使用 Object.entries 直接获取 key 和 value
    return Object.entries(buttons).map(([key, btn]) => {
      if (!btn.icon) {
        if (key === 'edit') btn.icon = 'fa-edit';
        else if (key === 'delete') btn.icon = 'fa-trash-alt';
      }
      if (!btn.name) {
        if (key === 'edit') btn.name = '修改';
        else if (key === 'delete') btn.name = '删除';
      }

      const content = btn.icon ? `<i class="fas ${btn.icon}"></i>` : (btn.name || '');

      return `
      <button 
        type="button" 
        class="btn btn-${btn.btnType || btnType}" 
        data-type="${key}" 
        data-bs-toggle="tooltip" 
        data-bs-title="${btn.name || ''}">
        ${content}
      </button>`.trim();
    }).join('');
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

    const action = row?.actions[type];
    if (!action) return;
    if (type === 'delete') this.onDelete(btn, action);

    const modal = action?.modal;
    if (modal) this.onModal(btn, action);
  }

  onModal(target, action) {
    const path = action?.path;
    if (!path) return;

    const modal = action.modal;
    target.dataset.modalName = modal.name ?? '';
    target.dataset.modalSize = modal.size ?? '';
    target.dataset.modalBackdrop = modal.backdrop ?? '';

    htmx.ajax('get', path, {source: target, target: 'body', swap: 'beforeend'}).then();
  }

  onDelete(target, action) {
    const message = action?.message ?? '是否确认要删除';
    const path = action?.path;

    if (!path) return;

    window.confirmDialog(message, () => {
      htmx.ajax('delete', path, {swap: 'none', target: target}).then();
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
