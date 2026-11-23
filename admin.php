<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}
$config = ['url' => $deleteUrl, 'remote' => $deleteRemote, 'attachments' => (object) $cloudAttachments];
?>
<style>
#tdg-delete-dialog{--tdg-ink:#303a34;--tdg-muted:#68716b;--tdg-danger:#b42318;box-sizing:border-box;width:min(460px,calc(100% - 32px));max-height:calc(100dvh - 32px);margin:auto;padding:24px;border:1px solid #e3e8e4;border-radius:12px;background:#fff;color:var(--tdg-ink);box-shadow:0 16px 64px #1f292324;font:14px/1.65 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}
#tdg-delete-dialog::backdrop{background:#1e26215c;backdrop-filter:blur(3px)}
#tdg-delete-dialog h2{display:flex;align-items:center;gap:10px;margin:0 0 16px;font-size:18px;line-height:1.5;color:var(--tdg-ink)}
#tdg-delete-dialog svg{flex:none;width:22px;height:22px;padding:9px;border-radius:10px;background:#fff1ee;color:var(--tdg-danger)}
#tdg-delete-dialog p{margin:12px 0;color:var(--tdg-muted)}
#tdg-delete-dialog .tdg-file{padding:10px 12px;border:1px solid #e6e7e9;border-radius:6px;background:#f6f7f8;color:var(--tdg-ink);font:13px/1.6 ui-monospace,SFMono-Regular,Consolas,monospace;overflow-wrap:anywhere;white-space:normal}
#tdg-delete-dialog .tdg-warning{color:var(--tdg-danger);font-weight:600}
#tdg-delete-dialog .tdg-actions{display:flex;justify-content:flex-end;flex-wrap:wrap;gap:10px;margin-top:24px}
#tdg-delete-dialog button{box-sizing:border-box;min-height:44px;padding:10px 16px;border:1px solid #d4d7dc;border-radius:6px;background:#fff;color:var(--tdg-ink);font:inherit;line-height:1.5;cursor:pointer}
#tdg-delete-dialog button:hover{background:#f5f6f7}
#tdg-delete-dialog .tdg-confirm{border-color:var(--tdg-danger);background:var(--tdg-danger);color:#fff}
#tdg-delete-dialog .tdg-confirm:hover{background:#912018;border-color:#912018}
#tdg-delete-dialog.tdg-info .tdg-confirm{background:var(--tdg-ink);border-color:var(--tdg-ink)}
#tdg-delete-dialog.tdg-info .tdg-confirm:hover{background:#465b4d;border-color:#465b4d}
#tdg-delete-dialog.tdg-info svg{color:#465b4d;background:#f0f4ef}
#tdg-delete-dialog[open]{animation:tdg-dialog-in .18s ease-out both}
html:has(#tdg-delete-dialog[open]){overflow:hidden}
@keyframes tdg-dialog-in{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
@media(prefers-reduced-motion:reduce){#tdg-delete-dialog[open]{animation:none}}
#tdg-delete-dialog button:focus-visible{outline:3px solid #276ca5;outline-offset:3px}
#tdg-delete-dialog button:disabled{opacity:.6;cursor:wait}
#tdg-delete-dialog .tdg-error{padding:10px 12px;border-left:3px solid var(--tdg-danger);background:#fff3f1;color:#9d2118}
#tdg-delete-dialog [hidden]{display:none!important}
@media(max-width:400px){#tdg-delete-dialog{padding:20px}#tdg-delete-dialog .tdg-actions button{flex:1;padding-inline:10px}}
</style>
<dialog id="tdg-delete-dialog" aria-labelledby="tdg-delete-title" aria-describedby="tdg-delete-description tdg-delete-warning">
    <h2 id="tdg-delete-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 8v5m0 3h.01M10.3 4.9 2.7 18a1.5 1.5 0 0 0 1.3 2h16a1.5 1.5 0 0 0 1.3-2L13.7 4.9a2 2 0 0 0-3.4 0Z" stroke-linecap="round" stroke-linejoin="round"/></svg><span id="tdg-delete-heading">确定要删除这个附件吗？</span></h2>
    <div class="tdg-file" id="tdg-delete-file"></div>
    <p id="tdg-delete-description"></p>
    <p class="tdg-warning" id="tdg-delete-warning"></p>
    <p class="tdg-error" id="tdg-delete-error" role="alert" hidden></p>
    <div class="tdg-actions">
        <button type="button" id="tdg-delete-cancel" autofocus>取消</button>
        <button type="button" id="tdg-delete-confirm" class="tdg-confirm">确认删除</button>
    </div>
</dialog>
<script>
(function () {
    'use strict';
    const config = <?php echo json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const dialog = document.getElementById('tdg-delete-dialog');
    const cancel = document.getElementById('tdg-delete-cancel');
    const confirm = document.getElementById('tdg-delete-confirm');
    const error = document.getElementById('tdg-delete-error');
    let item = null, trigger = null, busy = false, removesCloud = false, pendingAnswer = null, replaying = false;

    function ask(options) {
        if (dialog.open) return Promise.resolve(false);
        trigger = options.trigger || document.activeElement;
        item = null;
        dialog.classList.toggle('tdg-info', !options.danger);
        document.getElementById('tdg-delete-heading').textContent = options.title || '请确认操作';
        document.getElementById('tdg-delete-file').hidden = true;
        document.getElementById('tdg-delete-description').textContent = options.message;
        document.getElementById('tdg-delete-warning').textContent = options.warning || '';
        confirm.textContent = options.confirmText || '确定';
        error.hidden = true;
        const answer = new Promise(function (resolve) { pendingAnswer = resolve; });
        dialog.showModal();
        cancel.focus();
        return answer;
    }
    window.TypechoDialogs = {confirm: ask};

    // 已核对的原生确认入口：异步确认后重放同一操作，原有校验与提交逻辑继续执行。
    // 临时确认函数仅用于当前同步事件，始终恢复；不修改其他页面或未适配的行为。
    function bridge(event, control, expected, title, danger) {
        const nativeConfirm = window.confirm;
        let timer;
        function restore() { if (window.confirm === intercept) window.confirm = nativeConfirm; clearTimeout(timer); }
        function intercept(message) {
            restore();
            if (String(message) !== expected) return nativeConfirm.call(window, message);
            const cloud = config.remote && /文件|附件/.test(expected);
            ask({trigger: control, title: title, danger: danger, message: expected,
                warning: cloud ? '其中的多吉云附件会同时从存储桶删除，删除后无法恢复。' : '',
                confirmText: danger ? '确认操作' : '保存并预览'}).then(function (accepted) {
                if (!accepted || !control.isConnected) return;
                replaying = true;
                window.confirm = function (text) { return String(text) === expected ? true : nativeConfirm.call(window, text); };
                try {
                    if (event.type === 'submit') control.requestSubmit(event.submitter || undefined);
                    else control.click();
                } finally { window.confirm = nativeConfirm; replaying = false; }
            });
            return false;
        }
        window.confirm = intercept;
        timer = setTimeout(restore, 0);
    }
    document.addEventListener('click', function (event) {
        if (replaying) return;
        const control = event.target.closest('a[lang], button[lang], #custom-field .fields button.btn-xs, .edit-draft-notice a, #btn-preview, #tytts-podcast-panel .tytts-btn.is-danger');
        if (!control) return;
        let expected = control.getAttribute('lang');
        let title = '确认执行这项操作？', danger = true;
        if (control.matches('#custom-field .fields button.btn-xs')) { expected = '确认要删除此字段吗?'; title = '删除自定义字段？'; }
        else if (control.matches('.edit-draft-notice a')) { expected = '您确认要删除这份草稿吗?'; title = '删除这份草稿？'; }
        else if (control.matches('#btn-preview')) { expected = '修改后的内容需要保存后才能预览, 是否保存?'; title = '保存修改并预览？'; danger = false; }
        else if (control.matches('#tytts-podcast-panel .tytts-btn.is-danger')) { expected = '确定删除这篇已保存的播客稿吗？'; title = '删除已保存的播客稿？'; }
        if (expected && /[?？]|确认|确定|是否/.test(expected)) bridge(event, control, expected, title, danger);
    }, true);
    document.addEventListener('submit', function (event) {
        if (!replaying && event.target.matches('#backup-secondary form'))
            bridge(event, event.target, '恢复操作将清除所有现有数据, 是否继续?', '确认恢复备份？', true);
    }, true);

    function close() {
        if (!busy) dialog.close();
    }
    dialog.addEventListener('close', function () {
        if (pendingAnswer) { const resolve = pendingAnswer; pendingAnswer = null; resolve(false); }
        if (trigger && trigger.isConnected) trigger.focus();
        else document.querySelector('#file-list .delete, #upload-panel a, #tab-files-btn')?.focus();
        item = trigger = null;
    });
    dialog.addEventListener('cancel', function (event) {
        if (busy) event.preventDefault();
    });
    dialog.addEventListener('click', function (event) {
        if (event.target !== dialog) return;
        const rect = dialog.getBoundingClientRect();
        if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) close();
    });
    cancel.addEventListener('click', close);

    // 捕获阶段拦截，仅替换附件列表的删除动作，包括刚上传的附件。
    document.addEventListener('click', function (event) {
        const button = event.target.closest('#file-list .delete');
        if (!button) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (dialog.open) return;
        trigger = button;
        item = button.closest('li');
        removesCloud = config.remote && config.attachments[item.dataset.cid] !== false;
        dialog.classList.remove('tdg-info');
        document.getElementById('tdg-delete-heading').textContent = '确定要删除这个附件吗？';
        document.getElementById('tdg-delete-file').hidden = false;
        document.getElementById('tdg-delete-file').textContent = item.querySelector('.insert')?.textContent.trim() || '未命名附件';
        document.getElementById('tdg-delete-description').textContent = removesCloud
            ? '删除后，后台附件记录和多吉云存储桶中的对应文件将一并删除。'
            : '本次仅删除后台附件记录，存储中的文件将保留。';
        document.getElementById('tdg-delete-warning').textContent = removesCloud
            ? '文件删除后无法恢复，引用此文件的内容可能失效。'
            : '附件记录删除后无法恢复。';
        confirm.textContent = removesCloud ? '删除附件及云端文件' : '删除附件记录';
        error.hidden = true;
        dialog.showModal();
        cancel.focus();
    }, true);

    confirm.addEventListener('click', async function () {
        if (pendingAnswer) {
            const resolve = pendingAnswer; pendingAnswer = null;
            dialog.close(); resolve(true); return;
        }
        if (busy || !item) return;
        busy = true;
        cancel.disabled = confirm.disabled = true;
        dialog.setAttribute('aria-busy', 'true');
        confirm.textContent = '正在删除…';
        error.hidden = true;
        const controller = new AbortController();
        const timeout = setTimeout(function () { controller.abort(); }, 70000);
        try {
            const response = await fetch(config.url, {
                method: 'POST', credentials: 'same-origin', signal: controller.signal,
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest'},
                body: new URLSearchParams({'do': 'delete', cid: item.dataset.cid})
            });
            const data = await response.json();
            if (!response.ok || data.code !== 200) throw new Error(data.message || '删除失败，请刷新附件列表后重试。');
            item.remove();
            const tab = document.getElementById('tab-files-btn');
            const count = document.querySelectorAll('#file-list li .insert').length;
            let balloon = tab?.querySelector('.balloon');
            if (count && tab) {
                if (!balloon) { balloon = document.createElement('span'); balloon.className = 'balloon'; tab.append(' ', balloon); }
                balloon.textContent = count;
            } else balloon?.remove();
            dialog.close();
        } catch (failure) {
            error.textContent = failure instanceof SyntaxError || failure.name === 'AbortError' || failure instanceof TypeError
                ? '未能确认删除结果，请刷新附件列表后核对，再尝试删除。'
                : failure.message;
            error.hidden = false;
        } finally {
            clearTimeout(timeout);
            busy = false;
            cancel.disabled = confirm.disabled = false;
            dialog.removeAttribute('aria-busy');
            confirm.textContent = removesCloud ? '删除附件及云端文件' : '删除附件记录';
        }
    });
})();
</script>
