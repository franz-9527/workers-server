/* 2026-10-06：基础设置 / 开发设置按业务域分页，减少长页面滚动；保存后保持当前分页。 */
(function (window, document) {
  'use strict';

  var PAGE_CONFIG = {
    basic: [
      { id: 'base', label: '站点与小程序', groups: ['general', 'site', 'wechat', 'feature'] },
      { id: 'business', label: '订单与履约', groups: ['order', 'quote', 'measurement', 'worker', 'hall', 'automation'] },
      { id: 'finance', label: '支付与资金', groups: ['payment', 'commission', 'finance', 'wallet'] }
    ],
    developer: [
      { id: 'storage', label: '存储与图片', groups: ['storage', 'media'] },
      { id: 'runtime', label: '运行与安全', groups: ['security', 'system', 'server', 'development'] },
      { id: 'database', label: '数据库字段', groups: [] }
    ]
  };

  var TESTING_LABELS = {
    'testing.qiniu_path_prefix': '测试七牛根目录',
    'testing.sms_enabled': '允许测试环境发送真实短信',
    'testing.allowed_sms_mobiles': '测试短信手机号白名单',
    'testing.subscribe_enabled': '允许测试环境发送微信订阅消息',
    'testing.allowed_wechat_openids': '测试订阅消息 OpenID 白名单',
    'testing.allow_wecom': '允许测试环境发送企业微信通知',
    'testing.allow_wechat_account_writes': '允许修改小程序账号级配置',
    'testing.allow_cloud_writes': '允许写入微信云开发数据'
  };

  function each(nodes, callback) {
    Array.prototype.forEach.call(nodes || [], callback);
  }

  function safeStorageGet(key) {
    try {
      return window.sessionStorage.getItem(key) || '';
    } catch (error) {
      return '';
    }
  }

  function safeStorageSet(key, value) {
    try {
      window.sessionStorage.setItem(key, value);
    } catch (error) {
      /* 隐私模式禁用 sessionStorage 时不影响分页本身。 */
    }
  }

  function queryValue(name) {
    try {
      return new window.URL(window.location.href).searchParams.get(name) || '';
    } catch (error) {
      return '';
    }
  }

  function updateUrl(pageId) {
    var url;
    try {
      url = new window.URL(window.location.href);
      url.searchParams.set('settings_tab', pageId);
      window.history.replaceState(null, '', url.pathname + url.search + url.hash);
    } catch (error) {
      /* URL / History API 不可用时，仅跳过地址栏同步。 */
    }
  }

  function findScope(forms) {
    var input;
    if (!forms.length) {
      return '';
    }
    input = forms[0].querySelector('input[name="_settings_scope"]');
    return input ? String(input.value || '') : '';
  }

  function customizeTestingSettings(scope) {
    var form;
    var title;
    var description;
    if (scope !== 'developer') {
      return;
    }
    form = document.querySelector('#settings-group-development');
    if (!form) {
      return;
    }
    title = form.querySelector('.admin-card__title h3');
    description = form.querySelector('.admin-card__title p');
    if (title) {
      title.innerHTML = '<i class="ri-terminal-box-line"></i> 开发接口与测试环境';
    }
    if (description) {
      description.textContent = '测试环境隔离开关、白名单与内部接口参数；是否进入 testing 仍只由服务器 app.env 决定';
    }
    each(form.querySelectorAll('.admin-setting-key'), function (keyNode) {
      var key = String(keyNode.textContent || '').split('·')[0].trim();
      var label = keyNode.previousElementSibling;
      if (TESTING_LABELS[key] && label && label.classList.contains('admin-label')) {
        label.textContent = TESTING_LABELS[key];
      }
    });
  }

  function pageExists(pages, pageId) {
    var found = false;
    each(pages, function (page) {
      if (page.id === pageId) {
        found = true;
      }
    });
    return found;
  }

  function pageForGroup(pages, groupId) {
    var matched = '';
    each(pages, function (page) {
      if (!matched && page.groups.indexOf(groupId) !== -1) {
        matched = page.id;
      }
    });
    return matched;
  }

  function buildNavigation(pages, beforeNode) {
    var nav = document.createElement('nav');
    nav.className = 'admin-message-pages admin-mb-3';
    nav.setAttribute('data-settings-pagination', '');
    nav.setAttribute('role', 'tablist');
    nav.setAttribute('aria-label', '设置分类');

    each(pages, function (page) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'admin-message-page-tab';
      button.textContent = page.label;
      button.setAttribute('data-settings-page', page.id);
      button.setAttribute('role', 'tab');
      button.setAttribute('aria-selected', 'false');
      nav.appendChild(button);
    });

    beforeNode.parentNode.insertBefore(nav, beforeNode);
    return nav;
  }

  function initialize() {
    var forms = document.querySelectorAll('form.admin-setting-group');
    var scope = findScope(forms);
    var configuredPages = PAGE_CONFIG[scope];
    var pages;
    var firstForm;
    var dictionary;
    var unmappedForms = [];
    var nav;
    var buttons;
    var storageKey;
    var activePage;

    if (!configuredPages || !forms.length) {
      return;
    }

    customizeTestingSettings(scope);
    pages = configuredPages.slice();
    firstForm = forms[0];
    dictionary = scope === 'developer' ? document.querySelector('#database-field-dictionary') : null;

    each(forms, function (form) {
      var groupId = String(form.id || '').replace(/^settings-group-/, '');
      var pageId = pageForGroup(pages, groupId);
      if (pageId) {
        form.setAttribute('data-settings-page-panel', pageId);
      } else {
        unmappedForms.push(form);
      }
    });

    if (dictionary) {
      dictionary.setAttribute('data-settings-page-panel', 'database');
    }

    /* 以后新增设置分组未同步分页配置时，不让它散落在所有分页，而是自动进入“其他设置”。 */
    if (unmappedForms.length) {
      pages.push({ id: 'other', label: '其他设置', groups: [] });
      each(unmappedForms, function (form) {
        form.setAttribute('data-settings-page-panel', 'other');
      });
    }

    pages = pages.filter(function (page) {
      return !!document.querySelector('[data-settings-page-panel="' + page.id + '"]');
    });

    if (pages.length < 2) {
      return;
    }

    nav = buildNavigation(pages, firstForm);
    buttons = nav.querySelectorAll('[data-settings-page]');
    storageKey = 'worker-admin-settings-tab-v2:' + scope;
    activePage = queryValue('settings_tab');

    if (!pageExists(pages, activePage)) {
      activePage = safeStorageGet(storageKey);
    }
    if (!pageExists(pages, activePage)) {
      activePage = pages[0].id;
    }

    function activate(pageId, syncUrl) {
      if (!pageExists(pages, pageId)) {
        return;
      }

      activePage = pageId;
      safeStorageSet(storageKey, pageId);

      each(buttons, function (button) {
        var isActive = button.getAttribute('data-settings-page') === pageId;
        button.classList.toggle('is-active', isActive);
        button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        button.tabIndex = isActive ? 0 : -1;
        if (isActive) {
          button.setAttribute('aria-current', 'page');
        } else {
          button.removeAttribute('aria-current');
        }
      });

      each(document.querySelectorAll('[data-settings-page-panel]'), function (panel) {
        panel.hidden = panel.getAttribute('data-settings-page-panel') !== pageId;
      });

      if (syncUrl) {
        updateUrl(pageId);
      }
    }

    each(buttons, function (button, index) {
      button.addEventListener('click', function () {
        activate(button.getAttribute('data-settings-page'), true);
      });
      button.addEventListener('keydown', function (event) {
        var nextIndex;
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
          return;
        }
        event.preventDefault();
        nextIndex = event.key === 'ArrowRight' ? index + 1 : index - 1;
        if (nextIndex >= buttons.length) {
          nextIndex = 0;
        }
        if (nextIndex < 0) {
          nextIndex = buttons.length - 1;
        }
        buttons[nextIndex].focus();
        buttons[nextIndex].click();
      });
    });

    each(forms, function (form) {
      form.addEventListener('submit', function () {
        safeStorageSet(storageKey, activePage);
      });
    });

    window.addEventListener('popstate', function () {
      var pageId = queryValue('settings_tab');
      if (pageExists(pages, pageId)) {
        activate(pageId, false);
      }
    });

    activate(activePage, false);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize);
  } else {
    initialize();
  }
})(window, document);
