/* 2026-08-23：后台通用交互使用原生 JavaScript；视觉图标来自已下载到站点的 Remix Icon。 */
(function (window, document) {
  'use strict';

  var body;
  var lastFocusedElement = null;
  var activeModal = null;
  var confirmState = null;

  function each(nodes, callback) {
    Array.prototype.forEach.call(nodes || [], callback);
  }

  function matches(element, selector) {
    var matcher;
    if (!element || element.nodeType !== 1) {
      return false;
    }
    matcher = element.matches || element.msMatchesSelector || element.webkitMatchesSelector;
    return matcher ? matcher.call(element, selector) : false;
  }

  function closest(element, selector) {
    while (element && element.nodeType === 1) {
      if (matches(element, selector)) {
        return element;
      }
      element = element.parentElement;
    }
    return null;
  }

  function byDataValue(attribute, value) {
    var result = null;
    each(document.querySelectorAll('[' + attribute + ']'), function (element) {
      if (!result && element.getAttribute(attribute) === value) {
        result = element;
      }
    });
    return result;
  }

  function resolveElement(reference, dataAttribute) {
    var id;
    if (!reference) {
      return null;
    }
    if (reference.nodeType === 1) {
      return reference;
    }
    id = String(reference).replace(/^#/, '');
    return document.getElementById(id) || byDataValue(dataAttribute, String(reference));
  }

  function dispatch(name, detail) {
    var event;
    if (typeof window.CustomEvent === 'function') {
      event = new CustomEvent(name, { detail: detail || {} });
    } else {
      event = document.createEvent('CustomEvent');
      event.initCustomEvent(name, true, true, detail || {});
    }
    document.dispatchEvent(event);
  }

  function setSidebar(open) {
    var toggles = document.querySelectorAll('[data-sidebar-toggle]');
    if (!body) {
      return;
    }
    body.classList.toggle('is-sidebar-open', !!open);
    if (open) {
      body.classList.add('is-locked');
    } else if (!activeModal) {
      body.classList.remove('is-locked');
    }
    each(toggles, function (toggle) {
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  function modalFocusable(modal) {
    return modal.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
  }

  function openModal(reference, trigger) {
    var modal = resolveElement(reference, 'data-modal');
    var focusable;
    if (!modal) {
      return false;
    }
    if (activeModal && activeModal !== modal) {
      closeModal(activeModal, false);
    }
    lastFocusedElement = trigger || document.activeElement;
    activeModal = modal;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    body.classList.add('is-locked');
    focusable = modalFocusable(modal);
    window.setTimeout(function () {
      var preferred = modal.querySelector('[data-modal-focus]');
      if (preferred) {
        preferred.focus();
      } else if (focusable.length) {
        focusable[0].focus();
      }
    }, 20);
    dispatch('admin:modal-open', { modal: modal });
    return true;
  }

  function closeModal(reference, restoreFocus) {
    var modal = reference ? resolveElement(reference, 'data-modal') : activeModal;
    if (!modal) {
      return false;
    }
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    if (activeModal === modal) {
      activeModal = null;
    }
    if (!body.classList.contains('is-sidebar-open')) {
      body.classList.remove('is-locked');
    }
    if (restoreFocus !== false && lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
      lastFocusedElement.focus();
    }
    dispatch('admin:modal-close', { modal: modal });
    return true;
  }

  function ensureConfirmModal() {
    var modal = document.getElementById('admin-confirm-modal');
    if (modal) {
      return modal;
    }
    modal = document.createElement('div');
    modal.id = 'admin-confirm-modal';
    modal.className = 'admin-modal admin-modal--sm';
    modal.setAttribute('data-modal', 'admin-confirm');
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-hidden', 'true');
    modal.setAttribute('aria-labelledby', 'admin-confirm-title');
    modal.innerHTML = '' +
      '<div class="admin-modal__backdrop" data-modal-close></div>' +
      '<div class="admin-modal__dialog">' +
        '<div class="admin-modal__header">' +
          '<h3 id="admin-confirm-title">请确认操作</h3>' +
          '<button class="admin-modal__close" type="button" data-modal-close aria-label="关闭"><i class="ri-close-line"></i></button>' +
        '</div>' +
        '<div class="admin-modal__body"><p class="admin-mb-0" data-confirm-message></p></div>' +
        '<div class="admin-modal__footer">' +
          '<button class="admin-btn" type="button" data-modal-close>取消</button>' +
          '<button class="admin-btn admin-btn--danger" type="button" data-confirm-submit data-modal-focus>确认</button>' +
        '</div>' +
      '</div>';
    body.appendChild(modal);
    modal.querySelector('[data-confirm-submit]').addEventListener('click', function () {
      var callback = confirmState && confirmState.callback;
      closeModal(modal, false);
      confirmState = null;
      if (typeof callback === 'function') {
        callback();
      }
    });
    return modal;
  }

  function confirmAction(message, callback, options) {
    var modal = ensureConfirmModal();
    var title = modal.querySelector('#admin-confirm-title');
    var text = modal.querySelector('[data-confirm-message]');
    var submit = modal.querySelector('[data-confirm-submit]');
    options = options || {};
    title.textContent = options.title || '请确认操作';
    text.textContent = message || '此操作执行后可能无法撤销，是否继续？';
    submit.textContent = options.confirmText || '确认';
    submit.className = 'admin-btn ' + (options.type === 'primary' ? 'admin-btn--primary' : 'admin-btn--danger');
    confirmState = { callback: callback };
    openModal(modal, options.trigger || document.activeElement);
  }

  function toast(message, type, duration) {
    var region = document.querySelector('.admin-toast-region');
    var item;
    var timer;
    function remove() {
      if (!item || item.classList.contains('is-leaving')) {
        return;
      }
      item.classList.add('is-leaving');
      window.setTimeout(function () {
        if (item && item.parentNode) {
          item.parentNode.removeChild(item);
        }
      }, 190);
    }
    if (!message) {
      return null;
    }
    if (!region) {
      region = document.createElement('div');
      region.className = 'admin-toast-region';
      region.setAttribute('role', 'status');
      region.setAttribute('aria-live', 'polite');
      body.appendChild(region);
    }
    item = document.createElement('div');
    item.className = 'admin-toast admin-toast--' + (type || 'info');
    item.innerHTML = '<div class="admin-toast__content"></div><button class="admin-toast__close" type="button" aria-label="关闭"><i class="ri-close-line"></i></button>';
    item.querySelector('.admin-toast__content').textContent = String(message);
    item.querySelector('.admin-toast__close').addEventListener('click', remove);
    region.appendChild(item);
    timer = Number(duration);
    if (!isFinite(timer)) {
      timer = 3200;
    }
    if (timer > 0) {
      window.setTimeout(remove, timer);
    }
    return item;
  }

  function submitConfirmedForm(form, button) {
    var hidden;
    if (!form) {
      return;
    }
    form.setAttribute('data-confirmed', 'true');
    if (typeof form.requestSubmit === 'function') {
      if (button && button.type === 'submit') {
        form.requestSubmit(button);
      } else {
        form.requestSubmit();
      }
      return;
    }
    if (button && button.name) {
      hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = button.name;
      hidden.value = button.value;
      form.appendChild(hidden);
    }
    form.submit();
  }

  function handleConfirmTrigger(trigger) {
    var message = trigger.getAttribute('data-confirm');
    var options = {
      title: trigger.getAttribute('data-confirm-title') || '请确认操作',
      confirmText: trigger.getAttribute('data-confirm-text') || '确认',
      type: trigger.getAttribute('data-confirm-type') || 'danger',
      trigger: trigger
    };
    confirmAction(message, function () {
      var form = trigger.form || closest(trigger, 'form');
      var href = trigger.getAttribute('href');
      if (form && (trigger.type === 'submit' || trigger.tagName.toLowerCase() === 'button')) {
        submitConfirmedForm(form, trigger);
      } else if (href) {
        window.location.assign(href);
      } else {
        trigger.removeAttribute('data-confirm');
        trigger.click();
        trigger.setAttribute('data-confirm', message);
      }
    }, options);
  }

  function updateBulkGroup(group) {
    var items = document.querySelectorAll('[data-check-item="' + group + '"]');
    var master = document.querySelector('[data-check-all="' + group + '"]');
    var bar = document.querySelector('[data-bulk-bar="' + group + '"]');
    var selected = 0;
    each(items, function (item) {
      if (item.checked) {
        selected += 1;
      }
    });
    if (master) {
      master.checked = items.length > 0 && selected === items.length;
      master.indeterminate = selected > 0 && selected < items.length;
    }
    if (bar) {
      bar.classList.toggle('is-visible', selected > 0);
      each(bar.querySelectorAll('[data-bulk-count]'), function (counter) {
        counter.textContent = String(selected);
      });
    }
  }

  function copyText(value, trigger) {
    function done() {
      toast(trigger && trigger.getAttribute('data-copy-success') || '已复制', 'success');
    }
    function fallback() {
      var input = document.createElement('textarea');
      input.value = value;
      input.setAttribute('readonly', 'readonly');
      input.style.position = 'fixed';
      input.style.opacity = '0';
      body.appendChild(input);
      input.select();
      try {
        document.execCommand('copy');
        done();
      } catch (error) {
        toast('复制失败，请手动复制', 'danger');
      }
      body.removeChild(input);
    }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(value).then(done, fallback);
    } else {
      fallback();
    }
  }

  function activateTab(tab) {
    var group = closest(tab, '[data-tabs]') || tab.parentElement;
    var target = resolveElement(tab.getAttribute('data-tab-target'), 'data-tab-panel');
    if (!target) {
      return;
    }
    each(group.querySelectorAll('[data-tab-target]'), function (item) {
      var panel = resolveElement(item.getAttribute('data-tab-target'), 'data-tab-panel');
      var isCurrent = item === tab;
      item.classList.toggle('is-active', isCurrent);
      item.setAttribute('aria-selected', isCurrent ? 'true' : 'false');
      if (panel) {
        panel.classList.toggle('is-active', isCurrent);
        panel.hidden = !isCurrent;
      }
    });
    dispatch('admin:tab-change', { tab: tab, panel: target });
  }

  function initFlashMessages() {
    each(document.querySelectorAll('[data-toast]'), function (element) {
      var message = element.getAttribute('data-toast') || element.textContent;
      var type = element.getAttribute('data-toast-type') || 'info';
      var duration = element.getAttribute('data-toast-duration');
      toast(message.replace(/^\s+|\s+$/g, ''), type, duration === null ? undefined : Number(duration));
      if (element.parentNode) {
        element.parentNode.removeChild(element);
      }
    });
  }

  function initFormLoading() {
    each(document.querySelectorAll('form[data-loading]'), function (form) {
      form.addEventListener('submit', function () {
        window.setTimeout(function () {
          each(form.elements, function (button) {
            if (!matches(button, 'button[type="submit"], input[type="submit"]')) return;
            var loadingText = button.getAttribute('data-loading-text');
            if (loadingText) {
              if (button.tagName.toLowerCase() === 'input') {
                button.setAttribute('data-original-text', button.value);
                button.value = loadingText;
              } else {
                button.setAttribute('data-original-text', button.textContent);
                button.textContent = loadingText;
              }
            }
            button.classList.add('is-loading');
            button.disabled = true;
          });
        }, 0);
      });
    });
  }

  // 2026-08-23：记录左侧菜单滚动位置，跨页面操作后保持管理员当前视野不跳动。
  function initSidebarPersistence() {
    var nav = document.querySelector('.admin-nav');
    var key = 'worker-admin-sidebar-scroll-v1';
    var saved;
    if (!nav) {
      return;
    }
    try {
      saved = Number(window.sessionStorage.getItem(key));
      if (isFinite(saved) && saved > 0) {
        nav.scrollTop = saved;
      }
      nav.addEventListener('scroll', function () {
        window.sessionStorage.setItem(key, String(nav.scrollTop));
      });
      each(nav.querySelectorAll('a'), function (link) {
        link.addEventListener('click', function () {
          window.sessionStorage.setItem(key, String(nav.scrollTop));
        });
      });
    } catch (error) {
      /* 隐私模式禁用存储时不影响后台其它操作。 */
    }
  }

  function initialize() {
    body = document.body;
    if (!body) {
      return;
    }

    each(document.querySelectorAll('.admin-modal'), function (modal) {
      if (!modal.hasAttribute('aria-hidden')) {
        modal.setAttribute('aria-hidden', modal.classList.contains('is-open') ? 'false' : 'true');
      }
    });

    each(document.querySelectorAll('[data-check-item]'), function (item) {
      updateBulkGroup(item.getAttribute('data-check-item'));
    });

    initFlashMessages();
    initFormLoading();
    initSidebarPersistence();

    document.addEventListener('click', function (event) {
      var sidebarToggle = closest(event.target, '[data-sidebar-toggle]');
      var sidebarClose = closest(event.target, '[data-sidebar-close]');
      var modalOpen = closest(event.target, '[data-modal-open]');
      var modalClose = closest(event.target, '[data-modal-close]');
      var confirmTrigger = closest(event.target, '[data-confirm]');
      var dismiss = closest(event.target, '[data-dismiss]');
      var dropdownToggle = closest(event.target, '[data-dropdown-toggle]');
      var dropdown = closest(event.target, '.admin-dropdown');
      var tab = closest(event.target, '[data-tab-target]');
      var copy = closest(event.target, '[data-copy]');
      var passwordToggle = closest(event.target, '[data-password-toggle]');

      if (sidebarToggle) {
        event.preventDefault();
        setSidebar(!body.classList.contains('is-sidebar-open'));
        return;
      }
      if (sidebarClose) {
        event.preventDefault();
        setSidebar(false);
        return;
      }
      if (confirmTrigger && confirmTrigger.tagName.toLowerCase() !== 'form') {
        event.preventDefault();
        handleConfirmTrigger(confirmTrigger);
        return;
      }
      if (modalOpen) {
        event.preventDefault();
        var openedModal = resolveElement(modalOpen.getAttribute('data-modal-open'), 'data-modal');
        openModal(openedModal, modalOpen);
        var openTabName = modalOpen.getAttribute('data-open-tab');
        if (openedModal && openTabName) {
          var requestedTab = openedModal.querySelector('[data-tab-target="' + openTabName + '"]');
          if (requestedTab) {
            activateTab(requestedTab);
          }
        }
        return;
      }
      if (modalClose) {
        event.preventDefault();
        closeModal(closest(modalClose, '.admin-modal'));
        return;
      }
      if (dismiss) {
        event.preventDefault();
        var target = closest(dismiss, dismiss.getAttribute('data-dismiss') || '.admin-alert');
        if (target) {
          target.parentNode.removeChild(target);
        }
        return;
      }
      if (dropdownToggle) {
        event.preventDefault();
        var ownDropdown = closest(dropdownToggle, '.admin-dropdown');
        each(document.querySelectorAll('.admin-dropdown.is-open'), function (openDropdown) {
          if (openDropdown !== ownDropdown) {
            openDropdown.classList.remove('is-open');
          }
        });
        ownDropdown.classList.toggle('is-open');
        dropdownToggle.setAttribute('aria-expanded', ownDropdown.classList.contains('is-open') ? 'true' : 'false');
        return;
      }
      if (!dropdown) {
        each(document.querySelectorAll('.admin-dropdown.is-open'), function (openDropdown) {
          openDropdown.classList.remove('is-open');
          var toggle = openDropdown.querySelector('[data-dropdown-toggle]');
          if (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
          }
        });
      }
      if (tab) {
        event.preventDefault();
        activateTab(tab);
        return;
      }
      if (copy) {
        event.preventDefault();
        var copyValue = copy.getAttribute('data-copy');
        var copySource = resolveElement(copy.getAttribute('data-copy-target'), 'data-copy-source');
        if (copySource) {
          copyValue = typeof copySource.value !== 'undefined' ? copySource.value : copySource.textContent;
        }
        copyText(copyValue || '', copy);
        return;
      }
      if (passwordToggle) {
        event.preventDefault();
        var passwordWrap = closest(passwordToggle, '.admin-input-wrap');
        var passwordInput = resolveElement(passwordToggle.getAttribute('data-password-toggle'), 'data-password-input');
        if (!passwordInput && passwordWrap) {
          passwordInput = passwordWrap.querySelector('input');
        }
        if (passwordInput) {
          passwordInput.type = passwordInput.type === 'password' ? 'text' : 'password';
          passwordToggle.setAttribute('aria-pressed', passwordInput.type === 'text' ? 'true' : 'false');
          passwordToggle.setAttribute('aria-label', passwordInput.type === 'text' ? '隐藏密码' : '显示密码');
          passwordToggle.innerHTML = passwordInput.type === 'text' ? '<i class="ri-eye-off-line"></i>' : '<i class="ri-eye-line"></i>';
        }
      }
    });

    document.addEventListener('change', function (event) {
      var master = closest(event.target, '[data-check-all]');
      var item = closest(event.target, '[data-check-item]');
      var autoSubmit = closest(event.target, '[data-auto-submit]');
      var group;
      if (master) {
        group = master.getAttribute('data-check-all');
        each(document.querySelectorAll('[data-check-item="' + group + '"]'), function (checkbox) {
          checkbox.checked = master.checked;
        });
        updateBulkGroup(group);
      } else if (item) {
        updateBulkGroup(item.getAttribute('data-check-item'));
      }
      if (autoSubmit && autoSubmit.form) {
        if (typeof autoSubmit.form.requestSubmit === 'function') {
          autoSubmit.form.requestSubmit();
        } else {
          autoSubmit.form.submit();
        }
      }
    });

    document.addEventListener('submit', function (event) {
      var form = event.target;
      var message;
      if (!matches(form, 'form[data-confirm]')) {
        return;
      }
      if (form.getAttribute('data-confirmed') === 'true') {
        form.removeAttribute('data-confirmed');
        return;
      }
      event.preventDefault();
      message = form.getAttribute('data-confirm');
      confirmAction(message, function () {
        submitConfirmedForm(form, null);
      }, {
        title: form.getAttribute('data-confirm-title') || '请确认操作',
        confirmText: form.getAttribute('data-confirm-text') || '确认',
        type: form.getAttribute('data-confirm-type') || 'danger',
        trigger: document.activeElement
      });
    });

    document.addEventListener('keydown', function (event) {
      var focusable;
      var first;
      var last;
      if (event.key === 'Escape') {
        if (activeModal) {
          closeModal(activeModal);
        } else if (body.classList.contains('is-sidebar-open')) {
          setSidebar(false);
        }
        each(document.querySelectorAll('.admin-dropdown.is-open'), function (dropdown) {
          dropdown.classList.remove('is-open');
        });
      }
      if (event.key === 'Tab' && activeModal) {
        focusable = modalFocusable(activeModal);
        if (!focusable.length) {
          event.preventDefault();
          return;
        }
        first = focusable[0];
        last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }
    });

    window.addEventListener('resize', function () {
      if (window.innerWidth > 860 && body.classList.contains('is-sidebar-open')) {
        setSidebar(false);
      }
    });

    // 2026-08-23：后台写操作返回订单列表时自动恢复当前订单管理弹窗。
    var autoModal = document.querySelector('[data-auto-modal]');
    if (autoModal) {
      openModal(autoModal.getAttribute('data-auto-modal'), autoModal);
    }

    // 2026-08-24：设置页按卡片独立保存，修改后立即标记，离页前提醒未保存内容。
    var settingForms = document.querySelectorAll('form.admin-setting-group');
    each(settingForms, function (form) {
      form.addEventListener('input', function () { form.classList.add('is-dirty'); });
      form.addEventListener('change', function () { form.classList.add('is-dirty'); });
      form.addEventListener('submit', function () { form.classList.remove('is-dirty'); });
    });
    // 2026-08-30：随机值由 PHP 后端生成，前端只把候选值填入输入框并触发未保存提示。
    each(document.querySelectorAll('[data-worker-secret-generate]'), function (button) {
      button.addEventListener('click', function () {
        var target = document.querySelector(button.getAttribute('data-worker-secret-target') || '');
        var candidate = String(button.getAttribute('data-worker-secret-value') || '');
        if (!target || !/^[a-f0-9]{64}$/.test(candidate)) {
          toast('随机密钥生成失败，请刷新页面重试', 'danger');
          return;
        }
        if (target.value && target.value !== candidate && !window.confirm('生成新密钥会替换输入框中的当前值，确定继续？')) return;
        target.value = candidate;
        target.dispatchEvent(new Event('input', { bubbles: true }));
        target.focus();
        target.select();
        toast('已生成 64 位密钥，请先复制到云函数环境变量，再保存本项', 'success');
      });
    });
    window.addEventListener('beforeunload', function (event) {
      if (!document.querySelector('form.admin-setting-group.is-dirty')) return;
      event.preventDefault();
      event.returnValue = '';
    });

    // 2026-08-24：中文字段字典仅做本地过滤，不额外发起请求。
    each(document.querySelectorAll('[data-table-filter]'), function (input) {
      input.addEventListener('input', function () {
        var table = document.getElementById(input.getAttribute('data-table-filter'));
        var keyword = String(input.value || '').toLowerCase().trim();
        if (!table) return;
        each(table.querySelectorAll('tbody tr'), function (row) {
          row.style.display = !keyword || String(row.textContent || '').toLowerCase().indexOf(keyword) !== -1 ? '' : 'none';
        });
      });
    });

    // 2026-08-25：微信支付 v2/v3 二选一，只展示并提交当前协议需要的密钥与证书。
    each(document.querySelectorAll('[data-payment-settings]'), function (container) {
      var selector = container.querySelector('select[name="settings[wechat.pay.api_version]"]');
      if (!selector) return;
      var refreshPaymentFields = function () {
        each(container.querySelectorAll('[data-payment-version]'), function (field) {
          var visible = field.getAttribute('data-payment-version') === selector.value;
          field.hidden = !visible;
          each(field.querySelectorAll('input,textarea,select'), function (control) { control.disabled = !visible; });
        });
      };
      selector.addEventListener('change', refreshPaymentFields);
      refreshPaymentFields();
    });


    // 一级消息分页使用服务端链接，避免静态资源缓存导致按钮失效。

    // 订阅模板使用“场景列表 + 单个编辑器”。选中场景写入 URL 和隐藏字段，刷新/保存/绑定后仍回到原位置。
    each(document.querySelectorAll('[data-subscribe-manager]'), function (manager) {
      var tabs = manager.querySelectorAll('[data-subscribe-scene-tab]');
      var panels = manager.querySelectorAll('[data-subscribe-scene-panel]');
      var form = closest(manager, 'form');
      var activeInput = form ? form.querySelector('[data-active-notification-scene]') : null;
      var activateScene = function (scene, updateUrl) {
        each(tabs, function (tab) {
          var active = tab.getAttribute('data-subscribe-scene-tab') === scene;
          tab.classList.toggle('is-active', active);
          tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        each(panels, function (panel) { panel.hidden = panel.getAttribute('data-subscribe-scene-panel') !== scene; });
        if (activeInput) activeInput.value = scene;
        each(document.querySelectorAll('[data-small-active-notification-scene]'),function(input){input.value=scene;});
        if (updateUrl !== false && window.history && window.history.replaceState && window.URL) {
          try {
            var url = new URL(window.location.href);
            url.searchParams.set('scene', scene);
            url.hash = 'subscribe-scene-' + scene;
            window.history.replaceState({}, '', url.toString());
          } catch (error) {}
        }
      };
      each(tabs, function (tab) { tab.addEventListener('click', function () { activateScene(tab.getAttribute('data-subscribe-scene-tab'), true); }); });
      var selected = manager.querySelector('[data-subscribe-scene-tab].is-active');
      if (!selected && tabs.length) selected = tabs[0];
      if (selected) activateScene(selected.getAttribute('data-subscribe-scene-tab'), false);

      each(manager.querySelectorAll('[data-subscribe-scene-save]'), function (saveButton) {
        saveButton.addEventListener('click', function (event) {
          var ownScene = saveButton.getAttribute('data-subscribe-scene-save');
          var otherDirty = [];
          each(panels, function (panel) {
            if (panel.classList.contains('is-dirty') && panel.getAttribute('data-subscribe-scene-panel') !== ownScene) otherDirty.push(panel);
          });
          if (!otherDirty.length || saveButton.getAttribute('data-other-dirty-confirmed') === 'true') {
            saveButton.removeAttribute('data-other-dirty-confirmed');
            return;
          }
          event.preventDefault();
          confirmAction('还有 ' + otherDirty.length + ' 个其他通知存在未保存修改。保存当前通知会刷新页面，其他未保存修改不会一起保存。建议先逐个保存；仍要只保存当前通知吗？', function () {
            saveButton.setAttribute('data-other-dirty-confirmed', 'true');
            submitConfirmedForm(saveButton.form || form, saveButton);
          }, { type: 'primary', confirmText: '只保存当前', trigger: saveButton });
        });
      });
    });

    // 2026-09-22：订阅消息编辑器实时回写模板 ID 与 field_map；仍沿用分组独立保存和后端严格校验。
    each(document.querySelectorAll('[data-notification-json]'), function (target) {
      var form = target.closest('form');
      if (!form) return;
      var syncNotificationJson = function () {
        var value = {};
        try { value = JSON.parse(target.value || '{}') || {}; } catch (error) { value = {}; }
        each(form.querySelectorAll('[data-notification-template]'), function (input) {
          var key = input.getAttribute('data-notification-template');
          if (!value[key] || typeof value[key] !== 'object') value[key] = { name: key, keywords: [] };
          // 微信保存 template_id，阿里云短信卡片保存 template_code，避免两类模板字段互相覆盖。
          var field = input.getAttribute('data-template-field') || 'template_id';
          value[key][field] = String(input.value || '').trim();
        });
        // 每次同步都从当前控件重建字段来源，避免切换“业务字段 / 固定文字”后残留旧映射。
        each(form.querySelectorAll('[data-subscribe-scene-panel]'), function (panel) {
          var key = panel.getAttribute('data-subscribe-scene-panel');
          if (!key) return;
          if (!value[key] || typeof value[key] !== 'object') value[key] = { name: key, keywords: [] };
          value[key].field_map = {};
          value[key].field_defaults = {};
        });
        each(form.querySelectorAll('[data-notification-field-map]'), function (input) {
          var key = input.getAttribute('data-notification-field-map');
          var semantic = input.getAttribute('data-notification-semantic');
          if (!key || !semantic) return;
          if (!value[key] || typeof value[key] !== 'object') value[key] = { name: key, keywords: [] };
          if (!value[key].field_map || typeof value[key].field_map !== 'object' || Array.isArray(value[key].field_map)) value[key].field_map = {};
          var mapped = String(input.value || '').trim();
          if (mapped) value[key].field_map[semantic] = mapped;
          else delete value[key].field_map[semantic];
        });
        each(form.querySelectorAll('[data-notification-business-source]'), function (select) {
          var key = select.getAttribute('data-scene');
          var field = select.getAttribute('data-field');
          var semantic = String(select.value || '').trim();
          if (!key || !field || !semantic) return;
          if (!value[key] || typeof value[key] !== 'object') value[key] = { name: key, keywords: [] };
          if (!value[key].field_map || typeof value[key].field_map !== 'object' || Array.isArray(value[key].field_map)) value[key].field_map = {};
          value[key].field_map[semantic] = field;
        });
        each(form.querySelectorAll('[data-notification-field-default]'), function (input) {
          var key = input.getAttribute('data-notification-field-default');
          var field = input.getAttribute('data-notification-wechat-field');
          if (!key || !field) return;
          if (!value[key] || typeof value[key] !== 'object') value[key] = { name: key, keywords: [] };
          if (!value[key].field_defaults || typeof value[key].field_defaults !== 'object' || Array.isArray(value[key].field_defaults)) value[key].field_defaults = {};
          var fixedValue = String(input.value || '').trim();
          if (fixedValue) value[key].field_defaults[field] = fixedValue;
          else delete value[key].field_defaults[field];
        });
        target.value = JSON.stringify(value);
      };
      var markSceneDirty = function (input) {
        var panel = closest(input, '[data-subscribe-scene-panel]');
        if (!panel) return;
        panel.classList.add('is-dirty');
        var dirty = panel.querySelector('[data-subscribe-scene-dirty]');
        if (dirty) dirty.hidden = false;
        var scene = panel.getAttribute('data-subscribe-scene-panel');
        var tab = byDataValue('data-subscribe-scene-tab', scene);
        if (tab) tab.classList.add('is-dirty');
      };
      each(form.querySelectorAll('[data-notification-template],[data-notification-field-map],[data-notification-business-source],[data-notification-field-default]'), function (input) {
        input.addEventListener('input', function () { syncNotificationJson(); markSceneDirty(input); });
        input.addEventListener('change', function () { syncNotificationJson(); markSceneDirty(input); });
      });
      var compactScene = function (scene) {
        syncNotificationJson();
        var source = JSON.parse(target.value || '{}'), row = source[scene] || {}, value = {};
        ['template_id','template_title','template_fields','template_field_labels','synced_template_id','subscription_type','field_map','field_defaults'].forEach(function (key) { if (row[key] !== undefined) value[key] = row[key]; });
        var output = {}; output[scene] = value; return JSON.stringify(output);
      };
      each(form.querySelectorAll('[data-subscribe-scene-save]'), function (button) {
        var ownForm = button.form, scene = button.getAttribute('data-subscribe-scene-save');
        if (!ownForm) return;
        var syncScene = function () { var input=ownForm.querySelector('[name="settings[wechat.subscribe_template_ids]"]'); if(input) input.value=compactScene(scene); };
        button.addEventListener('click', syncScene);
        ownForm.addEventListener('submit', syncScene);
      });
      form.addEventListener('submit', function () {
        syncNotificationJson();
        if (target.name === 'settings[sms.template_codes]') {
          var rows=JSON.parse(target.value || '{}'), codes={};
          Object.keys(rows).forEach(function (key) { codes[key]={template_code:String(rows[key].template_code || '')}; });
          target.value=JSON.stringify(codes);
        }
      });
    });

    // 未映射模板字段优先直接选择当前场景允许的业务字段；固定文字输入仍作为兜底。
    each(document.querySelectorAll('[data-notification-business-source]'), function (select) {
      select.addEventListener('change', function () {
        var scene = select.getAttribute('data-scene') || '';
        var field = select.getAttribute('data-field') || '';
        var semantic = String(select.value || '').trim();
        var panel = closest(select, '[data-subscribe-scene-panel]');
        if (!scene || !field || !semantic || !panel) return;
        each(panel.querySelectorAll('[data-notification-business-source]'), function (other) {
          if (other !== select && String(other.value || '') === semantic) other.value = '';
        });
        var mappingInput = null;
        each(panel.querySelectorAll('[data-notification-field-map]'), function (input) {
          if (mappingInput) return;
          if (input.getAttribute('data-notification-field-map') === scene && input.getAttribute('data-notification-semantic') === semantic) mappingInput = input;
        });
        var targetId = select.getAttribute('data-target-id') || '';
        var fixedInput = targetId ? document.getElementById(targetId) : null;
        if (fixedInput && String(fixedInput.value || '').trim()) {
          fixedInput.value = '';
          fixedInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (mappingInput) {
          mappingInput.value = field;
          mappingInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });
    });

    // 手工固定文字与业务字段来源互斥，避免同一个微信字段同时写入 field_map / field_defaults。
    each(document.querySelectorAll('[data-notification-field-default]'), function (input) {
      input.addEventListener('input', function () {
        if (!String(input.value || '').trim()) return;
        var scene = input.getAttribute('data-notification-field-default') || '';
        var field = input.getAttribute('data-notification-wechat-field') || '';
        var panel = closest(input, '[data-subscribe-scene-panel]');
        if (!scene || !field || !panel) return;
        each(panel.querySelectorAll('[data-notification-business-source]'), function (select) {
          if (select.getAttribute('data-target-id') !== input.id || !select.value) return;
          var semantic = String(select.value || '');
          select.value = '';
          select.dispatchEvent(new Event('change', { bubbles: true }));
          each(panel.querySelectorAll('[data-notification-field-map]'), function (mappingInput) {
            if (mappingInput.getAttribute('data-notification-field-map') === scene &&
                mappingInput.getAttribute('data-notification-semantic') === semantic &&
                String(mappingInput.value || '') === field) {
              mappingInput.value = '';
              mappingInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
          });
        });
      });
    });

    // 上方业务字段映射发生变化时，同步清理对应固定文字，并让下方来源选择保持一致。
    each(document.querySelectorAll('[data-notification-field-map]'), function (mappingInput) {
      mappingInput.addEventListener('change', function () {
        var scene = mappingInput.getAttribute('data-notification-field-map') || '';
        var semantic = mappingInput.getAttribute('data-notification-semantic') || '';
        var field = String(mappingInput.value || '').trim();
        var panel = closest(mappingInput, '[data-subscribe-scene-panel]');
        if (!scene || !semantic || !panel) return;
        each(panel.querySelectorAll('[data-notification-business-source]'), function (select) {
          if (String(select.value || '') === semantic && select.getAttribute('data-field') !== field) select.value = '';
          if (field && select.getAttribute('data-field') === field) select.value = semantic;
        });
        if (!field) return;
        each(panel.querySelectorAll('[data-notification-field-default]'), function (fixedInput) {
          if (fixedInput.getAttribute('data-notification-field-default') !== scene ||
              fixedInput.getAttribute('data-notification-wechat-field') !== field ||
              !String(fixedInput.value || '').trim()) return;
          fixedInput.value = '';
          fixedInput.dispatchEvent(new Event('input', { bubbles: true }));
        });
      });
    });

    // 微信订阅模板库：直接在后台浏览公共模板、选择 2～5 个关键词并绑定当前业务场景。
    each(document.querySelectorAll('[data-subscribe-library-open]'), function (button) {
      var scenePanel = closest(button, '[data-subscribe-scene-panel]');
      var form = closest(button, 'form');
      if (!scenePanel || !form) return;
      var scene = button.getAttribute('data-scene') || '';
      var endpoint = button.getAttribute('data-url') || '';
      var panel = scenePanel.querySelector('[data-subscribe-library-panel]');
      if (!panel || !scene || !endpoint) return;
      var categorySelect = panel.querySelector('[data-subscribe-library-category]');
      var searchInput = panel.querySelector('[data-subscribe-library-search]');
      var searchButton = panel.querySelector('[data-subscribe-library-search-button]');
      var status = panel.querySelector('[data-subscribe-library-status]');
      var results = panel.querySelector('[data-subscribe-library-results]');
      var keywordPanel = panel.querySelector('[data-subscribe-library-keywords]');
      var moreButton = panel.querySelector('[data-subscribe-library-more]');
      var categoriesLoaded = false;

      function request(params) {
        var query = [];
        Object.keys(params || {}).forEach(function (key) {
          if (params[key] !== undefined && params[key] !== null && String(params[key]) !== '') {
            query.push(encodeURIComponent(key) + '=' + encodeURIComponent(String(params[key])));
          }
        });
        var url = endpoint + (endpoint.indexOf('?') >= 0 ? '&' : '?') + query.join('&');
        return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then(function (response) {
            return response.text().then(function (body) {
              var raw = String(body || '').trim();
              if (!raw) {
                throw new Error(response.ok
                  ? '后台搜索返回空响应，可能是请求超时；请重试。'
                  : ('后台搜索请求失败（HTTP ' + response.status + '），服务器未返回错误详情。'));
              }
              var json = null;
              try { json = JSON.parse(raw); }
              catch (error) { throw new Error('后台返回了非 JSON 数据（HTTP ' + response.status + '），请刷新后重试。'); }
              if (!response.ok || !json || !json.ok) throw new Error((json && json.message) || '微信接口请求失败');
              return json.data || {};
            });
          });
      }

      function clearChildren(node) { while (node && node.firstChild) node.removeChild(node.firstChild); }

      function syncCategories(categories, selectedId) {
        if (!categorySelect) return;
        var current = selectedId !== undefined && selectedId !== null ? String(selectedId) : String(categorySelect.value || '0');
        clearChildren(categorySelect);
        var all = document.createElement('option'); all.value = '0'; all.textContent = '请选择微信类目'; categorySelect.appendChild(all);
        (categories || []).forEach(function (row) {
          var option = document.createElement('option');
          option.value = String(row.id || '');
          option.textContent = row.name || ('类目 ' + row.id);
          categorySelect.appendChild(option);
        });
        if (current && categorySelect.querySelector('option[value="' + current.replace(/"/g, '') + '"]')) categorySelect.value = current;
        else categorySelect.value = '0';
      }

      function loadKeywords(template) {
        if (!keywordPanel) return;
        results.hidden = true;
        keywordPanel.hidden = false;
        status.textContent = '已选择：' + (template.title || ('模板 ' + template.tid));
        clearChildren(keywordPanel);
        var loading = document.createElement('p'); loading.className = 'admin-help'; loading.textContent = '正在读取模板关键词…'; keywordPanel.appendChild(loading);
        request({ scene: scene, tid: template.tid }).then(function (data) {
          clearChildren(keywordPanel);
          var back = document.createElement('button'); back.type = 'button'; back.className = 'admin-btn admin-btn--sm admin-mb-2'; back.innerHTML = '<i class="ri-arrow-left-line"></i> 重新选择模板';
          back.addEventListener('click', function () { keywordPanel.hidden = true; results.hidden = false; status.textContent = '请选择一个模板继续选择关键词。'; });
          keywordPanel.appendChild(back);
          var title = document.createElement('div'); title.className = 'admin-subscribe-editor__section-title';
          var strong = document.createElement('strong'); strong.textContent = template.title || ('模板 ' + template.tid);
          var small = document.createElement('small'); small.textContent = '请选择 2～5 项；系统已按当前业务场景预选推荐项';
          title.appendChild(strong); title.appendChild(small); keywordPanel.appendChild(title);
          var recommended = data.recommended_kids || [];
          var selectedCount = 0;
          (data.keywords || []).forEach(function (row, index) {
            var label = document.createElement('label'); label.className = 'admin-subscribe-field-row';
            var left = document.createElement('span');
            var name = document.createElement('strong'); name.textContent = row.name || ('关键词 ' + row.kid);
            var hint = document.createElement('small'); hint.textContent = row.example || row.rule || ('kid ' + row.kid);
            left.appendChild(name); left.appendChild(hint);
            var input = document.createElement('input'); input.type = 'checkbox'; input.value = String(row.kid); input.setAttribute('data-subscribe-library-kid', '');
            var shouldCheck = recommended.indexOf(Number(row.kid)) >= 0 && selectedCount < 5;
            if (!shouldCheck && selectedCount < 2 && index < 2) shouldCheck = true;
            if (shouldCheck) { input.checked = true; selectedCount += 1; }
            label.appendChild(left); label.appendChild(input); keywordPanel.appendChild(label);
          });
          var action = document.createElement('div'); action.className = 'admin-inline-actions admin-mt-2';
          var add = document.createElement('button'); add.type = 'button'; add.className = 'admin-btn admin-btn--primary admin-btn--sm'; add.textContent = '添加并绑定';
          action.appendChild(add); keywordPanel.appendChild(action);
          add.addEventListener('click', function () {
            var kids = [];
            each(keywordPanel.querySelectorAll('[data-subscribe-library-kid]'), function (input) { if (input.checked) kids.push(input.value); });
            if (kids.length < 2 || kids.length > 5) { toast('请选择 2～5 个关键词', 'warning'); return; }
            confirmAction('将把该公共模板添加到当前小程序“我的模板”，并自动绑定到“' + (template.title || scene) + '”。确认继续？', function () {
              var sceneInput = form.querySelector('[data-subscribe-library-scene]');
              var tidInput = form.querySelector('[data-subscribe-library-tid]');
              var kidsInput = form.querySelector('[data-subscribe-library-kids]');
              var submit = form.querySelector('[data-subscribe-library-submit]');
              if (!sceneInput || !tidInput || !kidsInput || !submit) return;
              sceneInput.value = scene; tidInput.value = template.tid; kidsInput.value = kids.join(',');
              if (typeof form.requestSubmit === 'function') submit.form.requestSubmit(submit); else submit.click();
            }, { type: 'primary', confirmText: '添加并绑定', trigger: add });
          });
        }).catch(function (error) {
          clearChildren(keywordPanel);
          var message = document.createElement('p'); message.className = 'admin-help is-danger'; message.textContent = error.message || '读取模板关键词失败'; keywordPanel.appendChild(message);
        });
      }

      var templateLoadSerial = 0;
      var searchState = null;

      function normalizeTemplateTitle(value) {
        return String(value || '').toLowerCase().replace(/[\s.,，。:：;；!?！？'"“”‘’()（）\-_—/\\]+/g, '');
      }

      function renderTemplateResults(collected, queryText, total, scanned, hasMore, truncated, meta) {
        var templates = Object.keys(collected).map(function (key) { return collected[key]; });
        var normalizedQuery = normalizeTemplateTitle(queryText);
        templates.sort(function (a, b) {
          var at = normalizeTemplateTitle(a.title);
          var bt = normalizeTemplateTitle(b.title);
          var ar = at === normalizedQuery ? 0 : (at.indexOf(normalizedQuery) === 0 ? 1 : 2);
          var br = bt === normalizedQuery ? 0 : (bt.indexOf(normalizedQuery) === 0 ? 1 : 2);
          if (ar !== br) return ar - br;
          return String(a.title || '').localeCompare(String(b.title || ''), 'zh-CN');
        });

        clearChildren(results);
        var scanText = total ? ('已检查 ' + Math.min(Number(scanned || 0), Number(total || 0)) + ' / ' + total + ' 条') : ('已检查 ' + Number(scanned || 0) + ' 条');
        var cacheText = meta && Number(meta.liveRequests || 0) === 0 && Number(meta.cachedPages || 0) > 0 ? ' · 使用缓存' : '';
        if (templates.length) {
          status.textContent = '找到 ' + templates.length + ' 个匹配模板 · ' + scanText + cacheText
            + (hasMore ? '。如未看到需要的模板，可点“继续搜索”。' : '。点击模板继续选择关键词。');
        } else {
          status.textContent = '暂未找到匹配模板 · ' + scanText + cacheText
            + (hasMore ? '。可点“继续搜索”查后面的模板。' : '。可调整关键词后重新搜索。');
        }
        if (truncated) status.textContent += ' 已达到本次安全搜索范围。';

        templates.forEach(function (row) {
          var item = document.createElement('button'); item.type = 'button'; item.className = 'admin-subscribe-library-item';
          var strong = document.createElement('strong'); strong.textContent = row.title || ('模板 ' + row.tid);
          var small = document.createElement('small'); small.textContent = (row.category_name ? (row.category_name + ' · ') : '') + '公共模板 #' + row.tid + (String(row.type || '') === '3' ? ' · 长期订阅' : ' · 一次性订阅');
          var icon = document.createElement('i'); icon.className = 'ri-arrow-right-s-line';
          item.appendChild(strong); item.appendChild(small); item.appendChild(icon);
          item.addEventListener('click', function () {
            each(results.querySelectorAll('.admin-subscribe-library-item'), function (other) { other.classList.remove('is-active'); });
            item.classList.add('is-active');
            loadKeywords(row);
          });
          results.appendChild(item);
        });
        if (moreButton) moreButton.hidden = !hasMore;
      }

      function resetTemplateSearch(message) {
        templateLoadSerial += 1;
        searchState = null;
        clearChildren(results);
        if (keywordPanel) { clearChildren(keywordPanel); keywordPanel.hidden = true; }
        if (moreButton) moreButton.hidden = true;
        if (message) status.textContent = message;
      }

      function loadCategories() {
        if (categoriesLoaded) return Promise.resolve();
        status.textContent = '正在读取当前小程序可用类目…';
        return request({ scene: scene, mode: 'categories' }).then(function (data) {
          syncCategories(data.categories || [], categorySelect ? categorySelect.value : '0');
          categoriesLoaded = true;
          status.textContent = '请先选择微信类目，输入模板名称关键词，再点击“搜索微信模板”。';
        }).catch(function (error) {
          status.textContent = error.message || '读取微信订阅类目失败';
          throw error;
        });
      }

      function loadTemplates(continueSearch) {
        var queryText = searchInput ? String(searchInput.value || '').trim() : '';
        var categoryValue = categorySelect ? String(categorySelect.value || '0') : '0';
        if (!categoryValue || categoryValue === '0') {
          status.textContent = '请先选择微信类目。';
          if (categorySelect) categorySelect.focus();
          return;
        }
        if (!queryText) {
          status.textContent = '请输入模板名称关键词后再搜索。';
          if (searchInput) searchInput.focus();
          return;
        }

        var key = categoryValue + '|' + queryText;
        if (!continueSearch || !searchState || searchState.key !== key) {
          searchState = { key: key, nextStart: 0, total: 0, scanned: 0, collected: {} };
          clearChildren(results);
          if (moreButton) moreButton.hidden = true;
        }
        var loadSerial = ++templateLoadSerial;
        var scanStart = continueSearch ? Number(searchState.nextStart || 0) : 0;
        status.textContent = continueSearch ? '正在继续搜索后面的模板…' : '正在搜索微信公共模板…';
        results.hidden = false;
        if (keywordPanel) { clearChildren(keywordPanel); keywordPanel.hidden = true; }

        request({ scene: scene, q: queryText, category: categoryValue, scan_start: scanStart }).then(function (data) {
          if (loadSerial !== templateLoadSerial || !searchState || searchState.key !== key) return;
          syncCategories(data.categories || [], data.category_id || categoryValue);
          searchState.total = Math.max(Number(searchState.total || 0), Number(data.total || 0));
          searchState.scanned += Math.max(0, Number(data.scanned || 0));
          searchState.nextStart = Number(data.next_start || 0);
          (data.templates || []).forEach(function (row) {
            var tid = String(row.tid || '');
            if (tid) searchState.collected[tid] = row;
          });
          renderTemplateResults(
            searchState.collected, queryText, searchState.total, searchState.scanned,
            !!data.has_more, !!data.truncated,
            { cachedPages: data.cached_pages, liveRequests: data.live_requests }
          );
          if (data.interrupted) {
            if (moreButton) moreButton.hidden = true;
            status.textContent = '搜索已停止：' + data.interrupted
              + (Object.keys(searchState.collected).length ? '；已保留当前已找到的模板。' : '');
          }
        }).catch(function (error) {
          if (loadSerial !== templateLoadSerial) return;
          if (moreButton) moreButton.hidden = true;
          status.textContent = error.message || '读取微信公共模板失败';
        });
      }

      button.addEventListener('click', function () {
        panel.hidden = !panel.hidden;
        if (!panel.hidden && !categoriesLoaded) loadCategories();
      });
      if (searchButton) searchButton.addEventListener('click', function () { loadTemplates(false); });
      if (moreButton) moreButton.addEventListener('click', function () { loadTemplates(true); });
      if (categorySelect) categorySelect.addEventListener('change', function () {
        resetTemplateSearch('已选择类目，请输入模板名称关键词后点击“搜索微信模板”。');
      });
      if (searchInput) {
        searchInput.addEventListener('input', function () {
          if (searchState) resetTemplateSearch('关键词已修改，请点击“搜索微信模板”。');
        });
        searchInput.addEventListener('keydown', function (event) {
          if (event.key === 'Enter') { event.preventDefault(); loadTemplates(false); }
        });
      }
    });

    // 消息开关统一只分一层，保存时继续保留 enabled + priority 兼容历史结构。
    each(document.querySelectorAll('[data-event-switch-json]'), function (target) {
      var form = target.closest('form');
      if (!form) return;
      var existing = {};
      try { existing = JSON.parse(target.value || '{}') || {}; } catch (error) { existing = {}; }
      var updateEventSwitchCounts = function () {
        each(form.querySelectorAll('[data-event-priority-group]'), function (group) {
          var inputs = group.querySelectorAll('[data-event-switch]');
          var enabled = 0;
          each(inputs, function (input) { if (input.checked) enabled += 1; });
          var counter = group.querySelector('[data-event-switch-count]');
          if (counter) counter.textContent = '已启用 ' + enabled + ' / ' + inputs.length;
        });
      };
      var syncEventSwitches = function () {
        var value = {};
        each(form.querySelectorAll('[data-event-switch]'), function (input) {
          var key = input.getAttribute('data-event-switch');
          value[key] = !!input.checked;
        });
        existing = value;
        target.value = JSON.stringify(value);
        updateEventSwitchCounts();
      };
      each(form.querySelectorAll('[data-event-switch]'), function (input) { input.addEventListener('change', syncEventSwitches); });
      each(form.querySelectorAll('[data-event-switch-bulk]'), function (button) {
        button.addEventListener('click', function () {
          var group = button.closest('[data-event-priority-group]');
          if (!group) return;
          var checked = button.getAttribute('data-event-switch-bulk') === 'all';
          each(group.querySelectorAll('[data-event-switch]'), function (input) { input.checked = checked; });
          syncEventSwitches();
        });
      });
      updateEventSwitchCounts();
      form.addEventListener('submit', syncEventSwitches);
    });

    // 2026-09-02：接口状态在当前页顶栏下方展示，避免跳出后台打断正在进行的管理操作。
    each(document.querySelectorAll('[data-api-status-toggle]'), function (button) {
      var panel = button.parentNode.querySelector('[data-api-status-panel]');
      if (!panel) return;
      button.addEventListener('click', function () {
        var opening = !!panel.hidden;
        panel.hidden = !opening;
        button.setAttribute('aria-expanded', opening ? 'true' : 'false');
        if (!opening) return;
        panel.innerHTML = '<strong>正在检查接口…</strong><small>请稍候</small>';
        fetch(button.getAttribute('data-api-status-url'), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then(function (response) { return response.text().then(function (body) { return { ok: response.ok, status: response.status, body: body }; }); })
          .then(function (result) {
            var parsed = null; try { parsed = JSON.parse(result.body); } catch (error) {}
            var healthy = result.ok && (!parsed || parsed.code === 1 || parsed.status === 'ok' || parsed.ok === true);
            var message = parsed && (parsed.message || parsed.msg) ? (parsed.message || parsed.msg) : (healthy ? '后台接口可正常访问' : '接口返回异常');
            panel.innerHTML = '<strong class="' + (healthy ? 'is-success' : 'is-danger') + '">' + (healthy ? '接口运行正常' : '接口状态异常') + '</strong><small>HTTP ' + result.status + ' · ' + String(message).replace(/[<>]/g, '') + '</small>';
          }).catch(function () { panel.innerHTML = '<strong class="is-danger">接口连接失败</strong><small>请检查域名、PHP 或服务器状态</small>'; });
      });
      document.addEventListener('click', function (event) { if (!panel.hidden && !button.parentNode.contains(event.target)) { panel.hidden = true; button.setAttribute('aria-expanded', 'false'); } });
    });

    // 2026-09-15：动态下单表单图片张数预览与最少/最多控件保持同步。
    var imageMinimumInput = document.getElementById('image-min');
    var imageMaximumInput = document.getElementById('image-max');
    var imageRulePreview = document.querySelector('[data-image-rule-preview]');
    var imageRuleSettings = document.querySelector('[data-image-rule-settings]');
    var componentTypeSelect = document.getElementById('component-type');
    if (imageRuleSettings && componentTypeSelect) {
      var toggleImageRuleSettings = function () {
        imageRuleSettings.hidden = componentTypeSelect.value !== 'image' && componentTypeSelect.value !== 'image_upload';
      };
      componentTypeSelect.addEventListener('change', toggleImageRuleSettings);
      toggleImageRuleSettings();
    }
    if (imageMinimumInput && imageMaximumInput && imageRulePreview) {
      var updateImageRulePreview = function () {
        var minimum = Number(imageMinimumInput.value);
        var maximum = Number(imageMaximumInput.value);
        if (!Number.isInteger(minimum) || !Number.isInteger(maximum) || minimum < 0 || maximum < 1 || minimum > maximum || maximum > 20) {
          imageRulePreview.textContent = '图片规则预览：范围无效，请设置 0～20 张且最少张数不大于最多张数。';
          imageRulePreview.classList.add('admin-alert--warning');
          imageRulePreview.classList.remove('admin-alert--info');
          return;
        }
        imageRulePreview.textContent = '图片规则预览：至少 ' + minimum + ' 张，最多 ' + maximum + ' 张；小程序和上传接口会按此限制。';
        imageRulePreview.classList.add('admin-alert--info');
        imageRulePreview.classList.remove('admin-alert--warning');
      };
      imageMinimumInput.addEventListener('input', updateImageRulePreview);
      imageMaximumInput.addEventListener('input', updateImageRulePreview);
      updateImageRulePreview();
    }

    dispatch('admin:ready');
  }

  window.AdminUI = {
    toast: toast,
    confirm: confirmAction,
    openModal: openModal,
    closeModal: closeModal,
    setSidebar: setSidebar,
    activateTab: activateTab,
    updateBulkGroup: updateBulkGroup
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize);
  } else {
    initialize();
  }
}(window, document));
