(function () {
  'use strict';

  const SECURE_PATH = window.settings.secure_path;
  const API_FETCH = '/lufei/' + SECURE_PATH + '/config/fetchDomainRewriteRules';
  const API_SAVE = '/lufei/' + SECURE_PATH + '/config/saveDomainRewriteRules';
  const API_NW_FETCH = '/lufei/' + SECURE_PATH + '/config/fetchSubscribeNodeWhitelistRules';
  const API_NW_SAVE = '/lufei/' + SECURE_PATH + '/config/saveSubscribeNodeWhitelistRules';
  const API_NODES = '/lufei/' + SECURE_PATH + '/server/manage/getNodes';

  let rules = [];
  let injected = false;
  let saving = false;

  // node whitelist state
  let nwRules = [];
  let nwInjected = false;
  let nwSaving = false;
  let nodeList = [];        // [{id, type, name, ...}]
  let nodesLoaded = false;
  let openDropdownIndex = -1;

  // ========== API ==========
  function getAuthHeader() {
    return { authorization: localStorage.getItem('authorization') || '' };
  }

  function fetchRules() {
    return fetch(API_FETCH, { headers: getAuthHeader() })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        rules = res.data || [];
        return rules;
      });
  }

  function saveRules() {
    if (saving) return Promise.resolve();
    saving = true;
    updateSaveBtn();
    return fetch(API_SAVE, {
      method: 'POST',
      headers: Object.assign({ 'Content-Type': 'application/json' }, getAuthHeader()),
      body: JSON.stringify({ rules: rules })
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        saving = false;
        updateSaveBtn();
        if (res.data === true) {
          showToast('success');
        } else {
          showToast('error', res.message || '保存失败');
        }
      })
      .catch(function (err) {
        saving = false;
        updateSaveBtn();
        showToast('error', err.message || '请求失败');
      });
  }

  // ========== Toast ==========
  function showToast(type, msg) {
    var toast = document.createElement('div');
    toast.textContent = type === 'success' ? '保存成功' : (msg || '操作失败');
    toast.style.cssText = 'position:fixed;top:24px;right:24px;z-index:99999;padding:10px 24px;border-radius:4px;color:#fff;font-size:14px;' +
      (type === 'success' ? 'background:#52c41a;' : 'background:#ff4d4f;');
    document.body.appendChild(toast);
    setTimeout(function () { toast.remove(); }, 2500);
  }

  // ========== Render ==========
  function updateSaveBtn() {
    var btn = document.getElementById('drr-save-btn');
    if (btn) {
      btn.disabled = saving;
      btn.textContent = saving ? '保存中...' : '保存';
    }
  }

  function renderPanel() {
    var container = document.getElementById('drr-container');
    if (!container) return;

    var html = '<table style="width:100%;border-collapse:collapse;margin-bottom:12px;">' +
      '<thead><tr style="background:#fafafa;text-align:left;">' +
      '<th style="padding:8px 12px;border-bottom:1px solid #e8e8e8;width:22%;">UA 关键词</th>' +
      '<th style="padding:8px 12px;border-bottom:1px solid #e8e8e8;width:22%;">匹配域名</th>' +
      '<th style="padding:8px 12px;border-bottom:1px solid #e8e8e8;width:22%;">替换 IP</th>' +
      '<th style="padding:8px 12px;border-bottom:1px solid #e8e8e8;width:22%;">备注</th>' +
      '<th style="padding:8px 12px;border-bottom:1px solid #e8e8e8;width:12%;text-align:center;">操作</th>' +
      '</tr></thead><tbody>';

    if (rules.length === 0) {
      html += '<tr><td colspan="5" style="padding:16px;text-align:center;color:#999;border-bottom:1px solid #e8e8e8;">暂无规则，点击下方按钮添加</td></tr>';
    } else {
      rules.forEach(function (rule, i) {
        html += '<tr data-index="' + i + '">' +
          '<td style="padding:6px 12px;border-bottom:1px solid #e8e8e8;"><input type="text" class="drr-input" data-field="ua" data-index="' + i + '" value="' + escapeAttr(rule.ua) + '" placeholder="如 Atlas/1.0" /></td>' +
          '<td style="padding:6px 12px;border-bottom:1px solid #e8e8e8;"><input type="text" class="drr-input" data-field="domain" data-index="' + i + '" value="' + escapeAttr(rule.domain) + '" placeholder="如 example.com" /></td>' +
          '<td style="padding:6px 12px;border-bottom:1px solid #e8e8e8;"><input type="text" class="drr-input" data-field="ip" data-index="' + i + '" value="' + escapeAttr(rule.ip) + '" placeholder="如 1.2.3.4" /></td>' +
          '<td style="padding:6px 12px;border-bottom:1px solid #e8e8e8;"><input type="text" class="drr-input" data-field="remark" data-index="' + i + '" value="' + escapeAttr(rule.remark || '') + '" placeholder="可选" /></td>' +
          '<td style="padding:6px 12px;border-bottom:1px solid #e8e8e8;text-align:center;"><button class="drr-del-btn" data-index="' + i + '" style="color:#ff4d4f;background:none;border:1px solid #ff4d4f;border-radius:4px;padding:2px 12px;cursor:pointer;font-size:13px;">删除</button></td>' +
          '</tr>';
      });
    }

    html += '</tbody></table>';
    html += '<div style="display:flex;gap:8px;">' +
      '<button id="drr-add-btn" style="background:#1890ff;color:#fff;border:none;border-radius:4px;padding:6px 20px;cursor:pointer;font-size:14px;">+ 添加规则</button>' +
      '<button id="drr-save-btn" style="background:#52c41a;color:#fff;border:none;border-radius:4px;padding:6px 20px;cursor:pointer;font-size:14px;">' + (saving ? '保存中...' : '保存') + '</button>' +
      '</div>';

    container.innerHTML = html;
    bindEvents();
  }

  function escapeAttr(str) {
    return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function bindEvents() {
    // input change - use both input and change to ensure values sync before save
    var inputs = document.querySelectorAll('.drr-input');
    inputs.forEach(function (input) {
      input.addEventListener('input', function () {
        var idx = parseInt(this.getAttribute('data-index'));
        var field = this.getAttribute('data-field');
        rules[idx][field] = this.value;
      });
    });

    // delete
    var delBtns = document.querySelectorAll('.drr-del-btn');
    delBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var idx = parseInt(this.getAttribute('data-index'));
        rules.splice(idx, 1);
        renderPanel();
      });
    });

    // add
    var addBtn = document.getElementById('drr-add-btn');
    if (addBtn) {
      addBtn.addEventListener('click', function () {
        rules.push({ ua: '', domain: '', ip: '', remark: '' });
        renderPanel();
        // focus the new row's first input
        var newInputs = document.querySelectorAll('.drr-input[data-index="' + (rules.length - 1) + '"]');
        if (newInputs.length) newInputs[0].focus();
      });
    }

    // save
    var saveBtn = document.getElementById('drr-save-btn');
    if (saveBtn) {
      saveBtn.addEventListener('click', function () {
        // validate
        for (var i = 0; i < rules.length; i++) {
          if (!rules[i].ua || !rules[i].domain || !rules[i].ip) {
            showToast('error', '第 ' + (i + 1) + ' 条规则的 UA、域名、IP 为必填项');
            return;
          }
        }
        saveRules();
      });
    }
  }

  // ========== Node Whitelist API ==========
  function fetchNwRules() {
    return fetch(API_NW_FETCH, { headers: getAuthHeader() })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        nwRules = res.data || [];
        return nwRules;
      });
  }

  function fetchNodes() {
    if (nodesLoaded) return Promise.resolve(nodeList);
    return fetch(API_NODES, { headers: getAuthHeader() })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        nodeList = res.data || [];
        nodesLoaded = true;
        return nodeList;
      });
  }

  function saveNwRules() {
    if (nwSaving) return Promise.resolve();
    nwSaving = true;
    updateNwSaveBtn();
    return fetch(API_NW_SAVE, {
      method: 'POST',
      headers: Object.assign({ 'Content-Type': 'application/json' }, getAuthHeader()),
      body: JSON.stringify({ rules: nwRules })
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        nwSaving = false;
        updateNwSaveBtn();
        if (res.data === true) {
          showToast('success');
        } else {
          showToast('error', res.message || '保存失败');
        }
      })
      .catch(function (err) {
        nwSaving = false;
        updateNwSaveBtn();
        showToast('error', err.message || '请求失败');
      });
  }

  // ========== Node Whitelist Render ==========
  function updateNwSaveBtn() {
    var btn = document.getElementById('nw-save-btn');
    if (btn) {
      btn.disabled = nwSaving;
      btn.textContent = nwSaving ? '保存中...' : '保存';
    }
  }

  function nodeKey(node) { return node.type + ':' + node.id; }

  function nodeTypeBadge(type) {
    var map = { shadowsocks: 'SS', vmess: 'VMess', vless: 'VLESS', trojan: 'Trojan', tuic: 'TUIC', hysteria: 'Hysteria', anytls: 'AnyTLS', v2node: 'V2Node' };
    return map[type] || type;
  }

  function renderNwPanel() {
    var container = document.getElementById('nw-container');
    if (!container) return;
    closeNwPopup();   // clear any stale popup before re-rendering the panel

    var html = '<table style="width:100%;border-collapse:collapse;margin-bottom:12px;table-layout:fixed;">' +
      '<thead><tr style="background:#fafafa;text-align:left;">' +
      '<th style="padding:8px 12px;border-bottom:1px solid #e8e8e8;width:22%;">UA 关键词</th>' +
      '<th style="padding:8px 12px;border-bottom:1px solid #e8e8e8;width:42%;">允许下发的节点（可多选）</th>' +
      '<th style="padding:8px 12px;border-bottom:1px solid #e8e8e8;width:22%;">备注</th>' +
      '<th style="padding:8px 12px;border-bottom:1px solid #e8e8e8;width:14%;text-align:center;">操作</th>' +
      '</tr></thead><tbody>';

    if (nwRules.length === 0) {
      html += '<tr><td colspan="4" style="padding:16px;text-align:center;color:#999;border-bottom:1px solid #e8e8e8;">暂无规则，点击下方按钮添加</td></tr>';
    } else {
      nwRules.forEach(function (rule, i) {
        var nodes = Array.isArray(rule.nodes) ? rule.nodes : [];
        var pickerLabel = nodes.length === 0 ? '点击选择节点' : ('已选 ' + nodes.length + ' 个');
        html += '<tr data-index="' + i + '" style="vertical-align:top;">' +
          '<td style="padding:6px 12px;border-bottom:1px solid #e8e8e8;"><input type="text" class="nw-input" data-field="ua" data-index="' + i + '" value="' + escapeAttr(rule.ua) + '" placeholder="如 Stash/2." /></td>' +
          '<td style="padding:6px 12px;border-bottom:1px solid #e8e8e8;">' +
          '<div class="nw-picker" data-index="' + i + '" style="border:1px solid #d9d9d9;border-radius:4px;padding:4px 8px;cursor:pointer;background:#fff;font-size:13px;min-height:24px;display:flex;align-items:center;justify-content:space-between;">' +
          '<span style="color:' + (nodes.length === 0 ? '#bfbfbf' : '#262626') + ';">' + escapeAttr(pickerLabel) + '</span>' +
          '<span style="color:#bfbfbf;font-size:10px;">▼</span>' +
          '</div>' +
          renderNwTags(i, nodes) +
          '</td>' +
          '<td style="padding:6px 12px;border-bottom:1px solid #e8e8e8;"><input type="text" class="nw-input" data-field="remark" data-index="' + i + '" value="' + escapeAttr(rule.remark || '') + '" placeholder="可选" /></td>' +
          '<td style="padding:6px 12px;border-bottom:1px solid #e8e8e8;text-align:center;"><button class="nw-del-btn" data-index="' + i + '" style="color:#ff4d4f;background:none;border:1px solid #ff4d4f;border-radius:4px;padding:2px 12px;cursor:pointer;font-size:13px;">删除</button></td>' +
          '</tr>';
      });
    }

    html += '</tbody></table>';
    html += '<div style="display:flex;gap:8px;">' +
      '<button id="nw-add-btn" style="background:#1890ff;color:#fff;border:none;border-radius:4px;padding:6px 20px;cursor:pointer;font-size:14px;">+ 添加规则</button>' +
      '<button id="nw-save-btn" style="background:#52c41a;color:#fff;border:none;border-radius:4px;padding:6px 20px;cursor:pointer;font-size:14px;">' + (nwSaving ? '保存中...' : '保存') + '</button>' +
      '</div>';

    container.innerHTML = html;
    bindNwEvents();
    if (openDropdownIndex >= 0) {
      showNwPopup(openDropdownIndex);
    }
  }

  function renderNwTags(ruleIndex, nodes) {
    if (!nodes.length) return '';
    var html = '<div class="nw-tags-wrap" style="margin-top:6px;display:flex;flex-wrap:wrap;gap:4px;">';
    nodes.forEach(function (n) {
      var name = lookupNodeName(n);
      var isStale = nodesLoaded && name === null;
      var label = nodeTypeBadge(n.type) + ' · ' + (name || ('#' + n.id)) + (isStale ? '（已删除）' : '');
      var tagStyle = isStale
        ? 'background:#fff1f0;border:1px solid #ffa39e;color:#ff4d4f;'
        : 'background:#e6f7ff;border:1px solid #91d5ff;color:#1890ff;';
      var xColor = isStale ? '#ff4d4f' : '#999';
      var titleAttr = isStale ? ' title="该节点已被删除，请点 × 移除并保存"' : '';
      html += '<span class="nw-tag" data-rule="' + ruleIndex + '" data-key="' + escapeAttr(nodeKey(n)) + '"' + titleAttr + ' style="display:inline-flex;align-items:center;gap:4px;padding:1px 6px;border-radius:3px;font-size:12px;' + tagStyle + '">' +
        escapeAttr(label) +
        '<span class="nw-tag-x" data-rule="' + ruleIndex + '" data-key="' + escapeAttr(nodeKey(n)) + '" style="cursor:pointer;color:' + xColor + ';font-weight:bold;">×</span>' +
        '</span>';
    });
    html += '</div>';
    return html;
  }

  function lookupNodeName(node) {
    for (var i = 0; i < nodeList.length; i++) {
      if (nodeList[i].type === node.type && Number(nodeList[i].id) === Number(node.id)) {
        return nodeList[i].name;
      }
    }
    return null;
  }

  function buildPopupHTML(ruleIndex) {
    var html = '<div style="padding:8px;border-bottom:1px solid #f0f0f0;">' +
      '<input type="text" class="nw-search" placeholder="搜索节点名称或类型" />' +
      '</div>';
    html += '<div class="nw-options" style="overflow-y:auto;max-height:240px;">';
    html += buildOptionsHTML(ruleIndex);
    html += '</div>';
    html += '<div style="padding:6px 12px;border-top:1px solid #f0f0f0;text-align:right;">' +
      '<button class="nw-close" style="background:#1890ff;color:#fff;border:none;border-radius:4px;padding:4px 14px;cursor:pointer;font-size:12px;">完成</button>' +
      '</div>';
    return html;
  }

  function showNwPopup(ruleIndex) {
    closeNwPopup();
    var picker = document.querySelector('.nw-picker[data-index="' + ruleIndex + '"]');
    if (!picker) return;
    var rect = picker.getBoundingClientRect();
    var minWidth = Math.max(rect.width, 320);
    var maxPopupHeight = 360;
    var gap = 4;
    var margin = 8;

    var left = rect.left;
    if (left + minWidth > window.innerWidth - margin) {
      left = Math.max(margin, window.innerWidth - minWidth - margin);
    }

    var spaceBelow = window.innerHeight - rect.bottom - gap - margin;
    var spaceAbove = rect.top - gap - margin;
    var openUpward = spaceBelow < 220 && spaceAbove > spaceBelow;

    var availableHeight = openUpward ? spaceAbove : spaceBelow;
    var popupHeight = Math.min(maxPopupHeight, Math.max(160, availableHeight));

    var popup = document.createElement('div');
    popup.id = 'nw-dropdown-popup';
    popup.dataset.rule = String(ruleIndex);
    popup.dataset.placement = openUpward ? 'top' : 'bottom';
    var top = openUpward ? (rect.top - gap - popupHeight) : (rect.bottom + gap);
    var shadow = openUpward ? '0 -2px 8px rgba(0,0,0,.15)' : '0 2px 8px rgba(0,0,0,.15)';
    popup.style.cssText = 'position:fixed;top:' + top + 'px;left:' + left + 'px;width:' + minWidth + 'px;height:' + popupHeight + 'px;z-index:9999;background:#fff;border:1px solid #d9d9d9;border-radius:4px;box-shadow:' + shadow + ';display:flex;flex-direction:column;';
    popup.innerHTML = buildPopupHTML(ruleIndex);
    document.body.appendChild(popup);

    // make the options list fill remaining space inside the fixed-height popup
    var optionsDiv = popup.querySelector('.nw-options');
    if (optionsDiv) {
      optionsDiv.style.flex = '1 1 auto';
      optionsDiv.style.maxHeight = 'none';
    }

    bindPopupEvents(popup, ruleIndex);
  }

  function closeNwPopup() {
    var existing = document.getElementById('nw-dropdown-popup');
    if (existing) existing.remove();
  }

  function buildOptionsHTML(ruleIndex) {
    var selectedKeys = {};
    (nwRules[ruleIndex].nodes || []).forEach(function (n) { selectedKeys[nodeKey(n)] = true; });
    if (!nodesLoaded) return '<div style="padding:12px;text-align:center;color:#999;font-size:13px;">节点列表加载中...</div>';
    if (nodeList.length === 0) return '<div style="padding:12px;text-align:center;color:#999;font-size:13px;">暂无节点</div>';
    var html = '';
    nodeList.forEach(function (node) {
      var key = nodeKey(node);
      var checked = !!selectedKeys[key];
      html += '<label class="nw-option" data-key="' + escapeAttr(key) + '" data-search="' + escapeAttr((node.name + ' ' + nodeTypeBadge(node.type)).toLowerCase()) + '" style="display:flex;align-items:center;gap:8px;padding:6px 12px;cursor:pointer;font-size:13px;' + (checked ? 'background:#f0f9ff;' : '') + '">' +
        '<input type="checkbox" ' + (checked ? 'checked' : '') + ' style="margin:0;cursor:pointer;pointer-events:none;" />' +
        '<span style="display:inline-block;min-width:54px;padding:1px 6px;background:#f0f0f0;color:#595959;border-radius:3px;font-size:11px;text-align:center;">' + escapeAttr(nodeTypeBadge(node.type)) + '</span>' +
        '<span style="flex:1;color:#262626;">' + escapeAttr(node.name || ('#' + node.id)) + '</span>' +
        '</label>';
    });
    return html;
  }

  function rebuildPopupOptions(popup, ruleIndex) {
    var optionsDiv = popup.querySelector('.nw-options');
    if (!optionsDiv) return;
    optionsDiv.innerHTML = buildOptionsHTML(ruleIndex);
    bindPopupOptions(optionsDiv, popup, ruleIndex);
    var search = popup.querySelector('.nw-search');
    if (search && search.value) {
      var keyword = search.value.toLowerCase().trim();
      optionsDiv.querySelectorAll('.nw-option').forEach(function (opt) {
        var s = opt.getAttribute('data-search') || '';
        opt.style.display = (!keyword || s.indexOf(keyword) !== -1) ? 'flex' : 'none';
      });
    }
  }

  function bindPopupOptions(scope, popup, ruleIndex) {
    scope.querySelectorAll('.nw-option').forEach(function (opt) {
      opt.addEventListener('click', function (e) {
        e.preventDefault();
        var key = this.getAttribute('data-key');
        var parts = key.split(':');
        var type = parts[0];
        var id = parseInt(parts[1]);
        var existing = (nwRules[ruleIndex].nodes || []).slice();
        var has = existing.some(function (n) { return nodeKey(n) === key; });
        if (has) {
          existing = existing.filter(function (n) { return nodeKey(n) !== key; });
        } else {
          existing.push({ type: type, id: id });
        }
        nwRules[ruleIndex].nodes = existing;
        rebuildPopupOptions(popup, ruleIndex);
        refreshPickerCell(ruleIndex);
      });
    });
  }

  function refreshPickerCell(ruleIndex) {
    var picker = document.querySelector('.nw-picker[data-index="' + ruleIndex + '"]');
    if (!picker) return;
    var nodes = nwRules[ruleIndex].nodes || [];
    var labelSpan = picker.querySelector('span');
    if (labelSpan) {
      labelSpan.textContent = nodes.length === 0 ? '点击选择节点' : ('已选 ' + nodes.length + ' 个');
      labelSpan.style.color = nodes.length === 0 ? '#bfbfbf' : '#262626';
    }
    var td = picker.parentElement;
    // Remove ALL existing tag containers (initial render + previously appended)
    td.querySelectorAll('.nw-tags-wrap').forEach(function (el) { el.remove(); });
    if (nodes.length > 0) {
      var temp = document.createElement('div');
      temp.innerHTML = renderNwTags(ruleIndex, nodes);
      var newWrap = temp.firstChild;
      td.appendChild(newWrap);
      newWrap.querySelectorAll('.nw-tag-x').forEach(function (x) {
        x.addEventListener('click', function (e) {
          e.stopPropagation();
          var key = this.getAttribute('data-key');
          nwRules[ruleIndex].nodes = nwRules[ruleIndex].nodes.filter(function (n) {
            return nodeKey(n) !== key;
          });
          refreshPickerCell(ruleIndex);
          var popup = document.getElementById('nw-dropdown-popup');
          if (popup && popup.dataset.rule === String(ruleIndex)) {
            rebuildPopupOptions(popup, ruleIndex);
          }
        });
      });
    }
  }

  function bindPopupEvents(popup, ruleIndex) {
    popup.addEventListener('mousedown', function (e) { e.stopPropagation(); });
    popup.addEventListener('click', function (e) { e.stopPropagation(); });

    var optionsDiv = popup.querySelector('.nw-options');
    if (optionsDiv) bindPopupOptions(optionsDiv, popup, ruleIndex);

    var search = popup.querySelector('.nw-search');
    if (search) {
      search.addEventListener('input', function () {
        var keyword = this.value.toLowerCase().trim();
        popup.querySelectorAll('.nw-option').forEach(function (opt) {
          var s = opt.getAttribute('data-search') || '';
          opt.style.display = (!keyword || s.indexOf(keyword) !== -1) ? 'flex' : 'none';
        });
      });
    }

    var closeBtn = popup.querySelector('.nw-close');
    if (closeBtn) {
      closeBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        openDropdownIndex = -1;
        closeNwPopup();
      });
    }
  }

  function bindNwEvents() {
    document.querySelectorAll('.nw-input').forEach(function (input) {
      input.addEventListener('input', function () {
        var idx = parseInt(this.getAttribute('data-index'));
        var field = this.getAttribute('data-field');
        nwRules[idx][field] = this.value;
      });
    });

    document.querySelectorAll('.nw-del-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var idx = parseInt(this.getAttribute('data-index'));
        nwRules.splice(idx, 1);
        if (openDropdownIndex === idx) {
          openDropdownIndex = -1;
        } else if (openDropdownIndex > idx) {
          openDropdownIndex--;
        }
        renderNwPanel();
      });
    });

    var addBtn = document.getElementById('nw-add-btn');
    if (addBtn) {
      addBtn.addEventListener('click', function () {
        nwRules.push({ ua: '', nodes: [], remark: '' });
        openDropdownIndex = -1;
        renderNwPanel();
        var newInputs = document.querySelectorAll('.nw-input[data-index="' + (nwRules.length - 1) + '"]');
        if (newInputs.length) newInputs[0].focus();
      });
    }

    var saveBtn = document.getElementById('nw-save-btn');
    if (saveBtn) {
      saveBtn.addEventListener('click', function () {
        for (var i = 0; i < nwRules.length; i++) {
          if (!String(nwRules[i].ua || '').trim()) {
            showToast('error', '第 ' + (i + 1) + ' 条规则的 UA 关键词不能为空');
            return;
          }
          if (!nwRules[i].nodes || nwRules[i].nodes.length === 0) {
            showToast('error', '第 ' + (i + 1) + ' 条规则未选择任何节点');
            return;
          }
        }
        saveNwRules();
      });
    }

    document.querySelectorAll('.nw-picker').forEach(function (picker) {
      picker.addEventListener('click', function (e) {
        e.stopPropagation();
        var idx = parseInt(this.getAttribute('data-index'));
        if (openDropdownIndex === idx) {
          openDropdownIndex = -1;
          closeNwPopup();
          return;
        }
        openDropdownIndex = idx;
        if (!nodesLoaded) {
          showNwPopup(idx); // show with loading state
          fetchNodes().then(function () {
            if (openDropdownIndex === idx) {
              showNwPopup(idx); // re-render with full list
            }
          }).catch(function () {
            showToast('error', '节点列表加载失败');
          });
        } else {
          showNwPopup(idx);
        }
      });
    });

    document.querySelectorAll('.nw-tag-x').forEach(function (x) {
      x.addEventListener('click', function (e) {
        e.stopPropagation();
        var ruleIdx = parseInt(this.getAttribute('data-rule'));
        var key = this.getAttribute('data-key');
        nwRules[ruleIdx].nodes = nwRules[ruleIdx].nodes.filter(function (n) {
          return nodeKey(n) !== key;
        });
        refreshPickerCell(ruleIdx);
        var popup = document.getElementById('nw-dropdown-popup');
        if (popup && popup.dataset.rule === String(ruleIdx)) {
          rebuildPopupOptions(popup, ruleIdx);
        }
      });
    });
  }

  // close popup on outside click / page scroll / resize
  document.addEventListener('mousedown', function (e) {
    if (openDropdownIndex === -1) return;
    var popup = document.getElementById('nw-dropdown-popup');
    if (popup && popup.contains(e.target)) return;
    if (e.target.closest && e.target.closest('.nw-picker')) return;
    openDropdownIndex = -1;
    closeNwPopup();
  });
  window.addEventListener('scroll', function (e) {
    if (openDropdownIndex === -1) return;
    var popup = document.getElementById('nw-dropdown-popup');
    if (popup && e.target && (popup === e.target || popup.contains(e.target))) return;
    openDropdownIndex = -1;
    closeNwPopup();
  }, true);
  window.addEventListener('resize', function () {
    if (openDropdownIndex !== -1) {
      openDropdownIndex = -1;
      closeNwPopup();
    }
  });

  // ========== Inject ==========
  function injectPanel() {
    if (injected) return;
    // Find subscribe tab content - look for the active tab panel in settings page
    var tabPanels = document.querySelectorAll('.ant-tabs-tabpane-active, .ant-tabs-tabpane.ant-tabs-tabpane-active');
    if (!tabPanels.length) return;

    var panel = tabPanels[tabPanels.length - 1];
    // Check if we're on the subscribe tab by searching for known text rendered by React components
    var panelText = panel.textContent || '';
    var isSubscribeTab = panelText.indexOf('允许用户更改订阅') !== -1 ||
      panelText.indexOf('月流量重置方式') !== -1 ||
      panelText.indexOf('开启折抵方案') !== -1;

    if (!isSubscribeTab) return;
    if (document.getElementById('drr-section')) return;

    injected = true;

    var section = document.createElement('div');
    section.id = 'drr-section';
    section.style.cssText = 'margin-top:24px;padding:20px;background:#fff;border:1px solid #e8e8e8;border-radius:4px;';
    section.innerHTML = '<h3 style="margin:0 0 4px 0;font-size:16px;font-weight:600;">订阅域名重写规则</h3>' +
      '<p style="margin:0 0 16px 0;color:#999;font-size:13px;">当客户端 UA 包含指定关键词时，将节点列表中匹配的域名替换为指定 IP 下发</p>' +
      '<div id="drr-container"><div style="text-align:center;padding:20px;color:#999;">加载中...</div></div>';

    panel.appendChild(section);

    fetchRules().then(function () {
      renderPanel();
    }).catch(function () {
      var c = document.getElementById('drr-container');
      if (c) c.innerHTML = '<div style="color:#ff4d4f;padding:12px;">加载规则失败，请刷新页面重试</div>';
    });
  }

  function injectNwPanel() {
    if (nwInjected) return;
    var tabPanels = document.querySelectorAll('.ant-tabs-tabpane-active, .ant-tabs-tabpane.ant-tabs-tabpane-active');
    if (!tabPanels.length) return;
    var panel = tabPanels[tabPanels.length - 1];
    var panelText = panel.textContent || '';
    var isSubscribeTab = panelText.indexOf('允许用户更改订阅') !== -1 ||
      panelText.indexOf('月流量重置方式') !== -1 ||
      panelText.indexOf('开启折抵方案') !== -1;
    if (!isSubscribeTab) return;
    if (document.getElementById('nw-section')) return;

    nwInjected = true;

    var section = document.createElement('div');
    section.id = 'nw-section';
    section.style.cssText = 'margin-top:24px;padding:20px;background:#fff;border:1px solid #e8e8e8;border-radius:4px;';
    section.innerHTML = '<h3 style="margin:0 0 4px 0;font-size:16px;font-weight:600;">订阅节点 UA 白名单规则</h3>' +
      '<p style="margin:0 0 16px 0;color:#999;font-size:13px;">规则中出现过的节点变为"受限节点"，仅对 UA 命中对应关键词的客户端下发；未出现在任何规则中的节点对所有客户端正常下发</p>' +
      '<div id="nw-container"><div style="text-align:center;padding:20px;color:#999;">加载中...</div></div>';

    panel.appendChild(section);

    Promise.all([fetchNwRules(), fetchNodes().catch(function () { return []; })]).then(function () {
      renderNwPanel();
    }).catch(function () {
      var c = document.getElementById('nw-container');
      if (c) c.innerHTML = '<div style="color:#ff4d4f;padding:12px;">加载规则失败，请刷新页面重试</div>';
    });
  }

  // ========== Style ==========
  function injectStyle() {
    var style = document.createElement('style');
    style.textContent = '.drr-input,.nw-input,.nw-search{width:100%;padding:4px 8px;border:1px solid #d9d9d9;border-radius:4px;font-size:13px;box-sizing:border-box;outline:none;transition:border-color .2s;background:#fff;color:#262626;}' +
      '.drr-input:focus,.nw-input:focus,.nw-search:focus{border-color:#1890ff;box-shadow:0 0 0 2px rgba(24,144,255,.2);}' +
      '.drr-del-btn:hover,.nw-del-btn:hover{background:#fff1f0!important;color:#ff4d4f!important;}' +
      '#drr-add-btn:hover,#drr-save-btn:hover,#nw-add-btn:hover,#nw-save-btn:hover,.nw-close:hover{opacity:.85;}' +
      '#drr-save-btn:disabled,#nw-save-btn:disabled{opacity:.5;cursor:not-allowed;}' +
      '.nw-picker:hover{border-color:#40a9ff!important;}' +
      '.nw-option:hover{background:#f5f5f5;}';
    document.head.appendChild(style);
  }

  // ========== Observer ==========
  function startObserver() {
    injectStyle();
    // Check periodically for tab changes since the SPA re-renders content
    setInterval(function () {
      var section = document.getElementById('drr-section');
      if (!section) {
        injected = false;
      }
      if (!injected) {
        injectPanel();
      }
      var nwSection = document.getElementById('nw-section');
      if (!nwSection) {
        nwInjected = false;
        openDropdownIndex = -1;
        closeNwPopup();
      }
      if (!nwInjected) {
        injectNwPanel();
      }
    }, 800);
  }

  // Start when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startObserver);
  } else {
    startObserver();
  }
})();
