// 验证实际弹窗脚本的取消、确认重放和临时确认函数恢复，不操作浏览器或真实数据。
const assert = require('node:assert/strict');
const vm = require('node:vm');
const {execFileSync} = require('node:child_process');
const path = require('node:path');

const template = path.join(__dirname, '..', 'admin.php');
const html = execFileSync('php', ['-r',
    'define("__TYPECHO_ROOT_DIR__",getcwd()); $deleteUrl="/unused"; $deleteRemote=true; $cloudAttachments=[]; include $argv[1];', template], {encoding: 'utf8'});
const script = html.match(/<script>([\s\S]*?)<\/script>/)[1];
const nodes = new Map();
let document;
class Element {
    constructor(id) {
        this.id = id; this.listeners = {}; this.isConnected = true; this.open = false;
        this.classList = {toggle() {}, remove() {}};
    }
    addEventListener(type, callback) { (this.listeners[type] ||= []).push(callback); }
    emit(type, event = {}) { for (const callback of this.listeners[type] || []) callback({target: this, ...event}); }
    focus() { document.activeElement = this; }
    showModal() { this.open = true; }
    close() { this.open = false; this.emit('close'); }
    closest(selector) { return selector.includes('a[lang]') ? this : null; }
    matches() { return false; }
    getAttribute(name) { return name === 'lang' ? this.message : null; }
    click() {
        const event = {type: 'click', target: this};
        for (const callback of document.listeners.click || []) callback(event);
        this.emit('click', event);
        this.original?.();
    }
}
document = {
    listeners: {}, activeElement: null,
    getElementById(id) { if (!nodes.has(id)) nodes.set(id, new Element(id)); return nodes.get(id); },
    addEventListener(type, callback) { (this.listeners[type] ||= []).push(callback); },
    querySelector() { return null; }, querySelectorAll() { return []; }
};
let nativeCalls = 0, deleted = 0;
const nativeConfirm = () => { nativeCalls++; return false; };
const window = {confirm: nativeConfirm};
vm.runInNewContext(script, {document, window, setTimeout, clearTimeout, Promise, URLSearchParams, AbortController});
const dialog = document.getElementById('tdg-delete-dialog');
const cancel = document.getElementById('tdg-delete-cancel');
const confirm = document.getElementById('tdg-delete-confirm');
const control = new Element('comment-delete');
control.message = '你确认要删除这条评论吗?';
control.original = () => { if (window.confirm(control.message)) deleted++; };
const settle = () => new Promise(resolve => setTimeout(resolve, 5));

(async () => {
    control.click();
    assert.equal(dialog.open, true);
    assert.equal(deleted, 0);
    assert.equal(window.confirm, nativeConfirm);
    assert.equal(document.activeElement, cancel);
    cancel.click();
    await settle();
    assert.equal(deleted, 0, '取消不能重放删除操作');
    assert.equal(dialog.open, false);

    control.click();
    confirm.click();
    await settle();
    assert.equal(deleted, 1, '确认后应只重放一次原操作');
    assert.equal(nativeCalls, 0, '已适配的确认不应显示浏览器原生弹窗');
    assert.equal(window.confirm, nativeConfirm);

    control.original = () => {};
    control.click(); // 原流程不需要确认时，不应额外打开弹窗。
    await settle();
    assert.equal(dialog.open, false);
    assert.equal(window.confirm, nativeConfirm);

    control.original = () => window.confirm('另一个未适配的确认');
    control.click();
    await settle();
    assert.equal(nativeCalls, 1, '未适配的确认不能被自动同意');
    assert.equal(window.confirm, nativeConfirm);
    console.log('检查通过：取消无操作、确认仅重放一次、不需要确认时不弹窗、原生函数恢复和未知操作隔离。');
})().catch(error => { console.error(error); process.exitCode = 1; });
