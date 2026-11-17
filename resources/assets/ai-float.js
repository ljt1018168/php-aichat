/*!
 * AI 漂浮小人 + 对话浮窗 —— 全局入口组件
 * 挂在主框架 index.html 上，任意后台 tab 页可见。
 * 对接 php-aichat 模块后端 /aichat/* 接口（宿主控制器 → ChatHandler）。
 * 零依赖：原生 DOM + fetch，不耦合 layui 加载顺序。
 *
 * 自定义配置：在引入本脚本前设置 window.AI_FLOAT_CONFIG 即可覆盖默认值。
 */
(function () {
    'use strict';

    var CFG = Object.assign({
        chatUrl:  '/aichat/chat',
        clearUrl: '/aichat/clear',
        toolsUrl: '/aichat/tools',
        panelUrl: '/aichat/panel',          // "在新页打开"跳转地址
        storageKey: 'ai_float_pos',
        quickQuestions: [
            { q: '你能查询哪些数据？', label: '使用帮助' }
        ],
        title:    'AI 数据助手',
        subtitle: '用自然语言查询业务数据'
    }, window.AI_FLOAT_CONFIG || {});

    var state = {
        open: false,
        sending: false,
        conversationId: 0,
        enabled: null,        // null=未检测 / true / false
        quickReady: false
    };

    var el = {};

    // 小人头像 SVG（简洁机器人）
    var ROBOT_SVG =
        '<svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">' +
        '<rect x="14" y="6" width="20" height="6" rx="3" fill="rgba(255,255,255,.9)"/>' +
        '<circle cx="24" cy="6" r="3" fill="#fff"/>' +
        '<rect x="8" y="14" width="32" height="26" rx="8" fill="rgba(255,255,255,.92)"/>' +
        '<circle cx="18" cy="26" r="3" fill="#1e88e5"/>' +
        '<circle cx="30" cy="26" r="3" fill="#1e88e5"/>' +
        '<path d="M18 33 Q24 37 30 33" stroke="#1e88e5" stroke-width="2" fill="none" stroke-linecap="round"/>' +
        '</svg>';

    // ==================== DOM 构建 ====================
    function build() {
        el.btn = document.createElement('div');
        el.btn.className = 'ai-float-btn';
        el.btn.title = 'AI 数据助手（可拖拽）';
        el.btn.innerHTML = '<span class="ai-float-badge"></span>' + ROBOT_SVG;

        el.panel = document.createElement('div');
        el.panel.className = 'ai-float-panel';
        el.panel.innerHTML =
            '<div class="ai-fp-header">' +
              '<div><div class="ai-fp-title">' + CFG.title + '</div>' +
              '<div class="ai-fp-sub">' + CFG.subtitle + '</div></div>' +
              '<div class="ai-fp-actions">' +
                '<button class="ai-fp-btn" id="aiFpOpenFull" title="在新页打开">⤢</button>' +
                '<button class="ai-fp-btn" id="aiFpClear" title="清空会话">↺</button>' +
                '<button class="ai-fp-btn" id="aiFpClose" title="关闭">×</button>' +
              '</div>' +
            '</div>' +
            '<div class="ai-fp-body" id="aiFpBody">' +
              '<div class="ai-fp-tips">直接输入你的问题，AI 会调用你有权限的数据查询工具并整理结果。</div>' +
            '</div>' +
            '<div class="ai-fp-footer">' +
              '<div class="ai-fp-quick" id="aiFpQuick"></div>' +
              '<div class="ai-fp-input-row">' +
                '<textarea id="aiFpInput" placeholder="输入问题，回车发送（Shift+回车换行）"></textarea>' +
                '<button class="ai-fp-send" id="aiFpSend">发送</button>' +
              '</div>' +
            '</div>';

        document.body.appendChild(el.btn);
        document.body.appendChild(el.panel);

        el.body   = el.panel.querySelector('#aiFpBody');
        el.input  = el.panel.querySelector('#aiFpInput');
        el.sendBtn = el.panel.querySelector('#aiFpSend');
        el.quick  = el.panel.querySelector('#aiFpQuick');
    }

    // ==================== 位置记忆 + 拖拽 ====================
    function restorePos() {
        try {
            var p = JSON.parse(localStorage.getItem(CFG.storageKey) || '');
            if (p && typeof p.x === 'number' && typeof p.y === 'number') {
                el.btn.style.left = p.x + 'px';
                el.btn.style.top  = p.y + 'px';
                el.btn.style.right  = 'auto';
                el.btn.style.bottom = 'auto';
            }
        } catch (e) {}
    }

    function savePos(x, y) {
        try {
            localStorage.setItem(CFG.storageKey, JSON.stringify({ x: x, y: y }));
        } catch (e) {}
    }

    function bindDrag() {
        var startX, startY, origX, origY, dragging = false, moved = false;

        function getPoint(e) {
            if (e.touches && e.touches[0]) {
                return { x: e.touches[0].clientX, y: e.touches[0].clientY };
            }
            return { x: e.clientX, y: e.clientY };
        }

        function down(e) {
            var p = getPoint(e);
            startX = p.x; startY = p.y;
            var rect = el.btn.getBoundingClientRect();
            origX = rect.left; origY = rect.top;
            dragging = true; moved = false;
            el.btn.classList.add('dragging');
            document.body.classList.add('ai-float-dragging');
            if (e.cancelable) e.preventDefault();
        }

        function move(e) {
            if (!dragging) return;
            var p = getPoint(e);
            var dx = p.x - startX, dy = p.y - startY;
            if (Math.abs(dx) > 4 || Math.abs(dy) > 4) moved = true;
            var nx = origX + dx, ny = origY + dy;
            var w = el.btn.offsetWidth, h = el.btn.offsetHeight;
            // 限制在视口内（留 4px 安全边距）
            nx = Math.max(4, Math.min(window.innerWidth  - w - 4, nx));
            ny = Math.max(4, Math.min(window.innerHeight - h - 4, ny));
            el.btn.style.left = nx + 'px';
            el.btn.style.top  = ny + 'px';
            el.btn.style.right  = 'auto';
            el.btn.style.bottom = 'auto';
        }

        function up() {
            if (!dragging) return;
            dragging = false;
            el.btn.classList.remove('dragging');
            document.body.classList.remove('ai-float-dragging');
            if (moved) {
                var rect = el.btn.getBoundingClientRect();
                savePos(rect.left, rect.top);
            } else {
                // 未移动 → 视为点击，切换面板
                togglePanel();
            }
        }

        el.btn.addEventListener('mousedown', down);
        document.addEventListener('mousemove', move);
        document.addEventListener('mouseup', up);
        el.btn.addEventListener('touchstart', down, { passive: false });
        document.addEventListener('touchmove', move, { passive: false });
        document.addEventListener('touchend', up);
    }

    // ==================== 展开 / 关闭 ====================
    function togglePanel() { state.open ? closePanel() : openPanel(); }

    function openPanel() {
        state.open = true;
        el.panel.classList.add('open');
        el.btn.classList.add('active');
        el.btn.classList.remove('has-badge');
        // 懒检测 + 懒加载快捷问题
        if (state.enabled === null) { checkEnabled(); }
        if (!state.quickReady) { renderQuick(); state.quickReady = true; }
        setTimeout(function () { el.input && el.input.focus(); }, 240);
    }

    function closePanel() {
        state.open = false;
        el.panel.classList.remove('open');
        el.btn.classList.remove('active');
    }

    // ==================== enable 检测 ====================
    // 调 tools 接口：能正常返回 = 已开启且已登录；
    // 返回 tools 为空 = 无任何数据权限。
    function checkEnabled() {
        fetch(CFG.toolsUrl, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                state.enabled = true;
                if (res.code === 0 && Array.isArray(res.data) && res.data.length === 0) {
                    var tip = el.body.querySelector('.ai-fp-tips');
                    if (tip) {
                        tip.innerHTML = '你当前没有可查询的数据权限，如需使用请联系管理员。';
                    }
                }
            })
            .catch(function () {
                state.enabled = false;
                disableUi('AI 对话功能未开启或登录已失效，请刷新页面后重试。');
            });
    }

    function disableUi(msg) {
        el.body.innerHTML = '<div class="ai-fp-disabled">' + (msg || '功能不可用') + '</div>';
        el.sendBtn.disabled = true;
        el.input.disabled = true;
    }

    function renderQuick() {
        var html = '';
        CFG.quickQuestions.forEach(function (it) {
            html += '<span data-q="' + escapeAttr(it.q) + '">' + escapeHtml(it.label) + '</span>';
        });
        el.quick.innerHTML = html;
    }

    // ==================== 对话 ====================
    function bindChat() {
        el.sendBtn.addEventListener('click', send);
        el.input.addEventListener('keydown', function (e) {
            if (e.keyCode === 13 && !e.shiftKey) { e.preventDefault(); send(); }
        });
        el.quick.addEventListener('click', function (e) {
            var t = e.target;
            if (t.tagName === 'SPAN') {
                el.input.value = t.getAttribute('data-q');
                send();
            }
        });
        el.panel.querySelector('#aiFpClose').addEventListener('click', closePanel);
        el.panel.querySelector('#aiFpClear').addEventListener('click', clearConv);
        el.panel.querySelector('#aiFpOpenFull').addEventListener('click', function () {
            window.open(CFG.panelUrl, '_blank');
        });
    }

    function send() {
        var q = (el.input.value || '').trim();
        if (!q || state.sending) return;

        appendMsg('me', escapeHtml(q));
        el.input.value = '';
        state.sending = true;
        el.sendBtn.disabled = true;

        var loadId = appendLoading();

        fetch(CFG.chatUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ question: q, conversation_id: state.conversationId })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            removeEl(loadId);
            if (res.code === 0) {
                state.conversationId = (res.data && res.data.conversation_id) || state.conversationId;
                appendMsg('ai', renderMarkdown((res.data && res.data.answer) || ''));
            } else if (res.code === 401) {
                appendMsg('ai', '<span style="color:#e57373;">登录已失效，请刷新页面重新登录。</span>');
            } else {
                appendMsg('ai', '<span style="color:#e57373;">' + escapeHtml(res.msg || '查询失败') + '</span>');
            }
        })
        .catch(function () {
            removeEl(loadId);
            appendMsg('ai', '<span style="color:#e57373;">请求失败，请稍后重试。</span>');
        })
        .then(function () {
            state.sending = false;
            el.sendBtn.disabled = false;
            scrollBottom();
        });
    }

    function clearConv() {
        if (!state.conversationId) {
            el.body.innerHTML = '<div class="ai-fp-tips">会话已清空，可重新提问。</div>';
            return;
        }
        fetch(CFG.clearUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'conversation_id=' + state.conversationId
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.code === 0) {
                state.conversationId = 0;
                el.body.innerHTML = '<div class="ai-fp-tips">已清空会话，可重新提问。</div>';
            }
        })
        .catch(function () {});
    }

    function appendMsg(who, html) {
        var avatar = who === 'me' ? '我' : 'AI';
        var cls = who === 'me' ? 'ai-fp-msg me' : 'ai-fp-msg';
        var div = document.createElement('div');
        div.className = cls;
        div.innerHTML =
            '<div class="ai-fp-avatar">' + avatar + '</div>' +
            '<div class="ai-fp-bubble">' + html + '</div>';
        el.body.appendChild(div);
        scrollBottom();
    }

    function appendLoading() {
        var id = 'ai-load-' + Date.now();
        var div = document.createElement('div');
        div.className = 'ai-fp-msg';
        div.id = id;
        div.innerHTML =
            '<div class="ai-fp-avatar">AI</div>' +
            '<div class="ai-fp-bubble ai-fp-loading">正在查询，请稍候…</div>';
        el.body.appendChild(div);
        scrollBottom();
        return id;
    }

    function removeEl(id) {
        var n = document.getElementById(id);
        if (n && n.parentNode) { n.parentNode.removeChild(n); }
    }

    function scrollBottom() {
        var b = el.body;
        b.scrollTop = b.scrollHeight;
    }

    // ==================== 工具函数（与 panel.html 保持一致）====================
    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function escapeAttr(s) { return escapeHtml(s); }

    /**
     * 极简 Markdown 渲染：表格 + 段落 + 简单列表
     * 与 panel.html 的 renderMarkdown 逻辑一致，保证两端结果统一。
     */
    function renderMarkdown(text) {
        if (!text) return '';
        var lines = String(text).split('\n');
        var html = '';
        var tableBuf = [];
        var inTable = false;

        function flushTable() {
            if (!tableBuf.length) return;
            var h = '<table>';
            for (var i = 0; i < tableBuf.length; i++) {
                var cells = tableBuf[i].split('|');
                cells = cells.filter(function (c, idx) {
                    return !(idx === 0 && c.trim() === '') &&
                           !(idx === cells.length - 1 && c.trim() === '');
                });
                if (cells.length && cells[0].trim().indexOf('---') === 0) continue;
                var isHead = (i === 0);
                h += '<tr>';
                for (var j = 0; j < cells.length; j++) {
                    h += isHead ? '<th>' : '<td>';
                    h += escapeHtml(cells[j].trim());
                    h += isHead ? '</th>' : '</td>';
                }
                h += '</tr>';
            }
            h += '</table>';
            html += h;
            tableBuf = [];
            inTable = false;
        }

        for (var k = 0; k < lines.length; k++) {
            var line = lines[k];
            if (line.indexOf('|') === 0 ||
                (line.indexOf('|') > -1 && /^\s*\|?[\s\S]*\|\s*$/.test(line))) {
                tableBuf.push(line);
                inTable = true;
                continue;
            }
            if (inTable) flushTable();
            // 简单无序列表
            if (/^\s*[-*]\s+/.test(line)) {
                html += '<p>• ' + escapeHtml(line.replace(/^\s*[-*]\s+/, '')) + '</p>';
                continue;
            }
            if (String(line).trim() === '') continue;
            html += '<p>' + escapeHtml(line) + '</p>';
        }
        if (inTable) flushTable();

        return html || escapeHtml(text);
    }

    // ==================== 启动 ====================
    function init() {
        build();
        restorePos();
        bindDrag();
        bindChat();
        // 首次进入给个红点提示，引导点击
        el.btn.classList.add('has-badge');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
