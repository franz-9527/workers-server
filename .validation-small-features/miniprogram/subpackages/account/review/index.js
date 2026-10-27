import { formatTime } from '../../../utils/DateUtils';
const OrderApi = require('../../../utils/OrderApi.js');

// 2026-08-08：每个审核标签独立按页读取单一集合，避免师傅与门店数据混合分页。
const PAGE_SIZE = 20;

function callWorker(action, data) {
  return new Promise((resolve, reject) => {
    wx.cloud.callFunction({
      name: 'worker',
      data: Object.assign({ action }, data || {}),
      success: res => {
        const result = res && res.result || {}
        if (Number(result.code) !== 1) return reject(new Error(result.msg || '云端处理失败'))
        resolve(result.data || {})
      },
      fail: reject
    })
  })
}

// 2026-08-08：状态码仅在内部用于匹配中文含义，页面不再展示状态码或 ErrorStatus 英文字样。
const NOTICE_STATUS_MEANING = {
  0: '发送成功',
  40003: '接收者账号不正确',
  40037: '订阅消息模板不正确',
  41030: '消息跳转页面不正确',
  43101: '用户未订阅或订阅次数已用完',
  47003: '订阅消息内容不符合要求',
  NOT_SUBSCRIBED: '未开启通知',
  USER_NOT_FOUND: '未找到申请人账号',
  PHONE_MISSING: '申请人手机号缺失',
  SERVER_OUTBOX_QUEUED: '等待后台发送',
  SERVER_OUTBOX_RETRY: '发送失败，等待自动重试',
  SERVER_OUTBOX_FAILED: '后台通知发送失败',
  SERVER_OUTBOX_SKIPPED: '未进入微信通知队列',
  UNKNOWN: '推送状态未知',
};

// 2026-08-08：兼容新旧通知字段，并将历史 success 文案统一转换为简洁中文。
function getReviewNoticeMeaning(notice) {
  const statusCode = notice.statusCode !== undefined && notice.statusCode !== ''
    ? notice.statusCode
    : notice.errorCode;
  if (NOTICE_STATUS_MEANING[String(statusCode)]) return NOTICE_STATUS_MEANING[String(statusCode)];

  const savedMeaning = String(notice.errorStatus || notice.message || '').trim();
  if (!savedMeaning) return notice.status === 'sent' ? '发送成功' : NOTICE_STATUS_MEANING.UNKNOWN;
  if (savedMeaning === 'success' || savedMeaning.indexOf('success（') === 0) return '发送成功';
  return savedMeaning.replace(/^ErrorStatus\s*[:：]\s*/i, '');
}

function normalizedReviewStatus(item) {
  const rawStatus = String(item.reviewStatus || item.auditStatus || item.status || '').toLowerCase();
  const statusAliases = { paused: 'suspended', published: 'approved', '已通过': 'approved', '已驳回': 'rejected', '驳回': 'rejected', '已暂停': 'suspended' };
  const normalizedStatus = statusAliases[rawStatus] || rawStatus;
  return ['approved', 'pending', 'rejected', 'suspended'].indexOf(normalizedStatus) >= 0
    ? normalizedStatus
    : (item.published ? 'approved' : 'pending');
}

function needsServerNoticeFallback(item) {
  if (normalizedReviewStatus(item) === 'pending') return false;
  const notice = item && item.reviewNotice || {};
  return ['sent', 'failed', 'skipped', 'queued'].indexOf(String(notice.status || '')) < 0;
}

function mergeServerNoticeFallback(data, fallback) {
  const primaryItems = Array.isArray(data && data.items) ? data.items : [];
  const fallbackItems = Array.isArray(fallback && fallback.items) ? fallback.items : [];
  if (!fallbackItems.length) return data;
  const noticeById = {};
  fallbackItems.forEach(item => {
    const id = String(item && item._id || '');
    const notice = item && item.reviewNotice || {};
    if (id && notice.status) noticeById[id] = notice;
  });
  if (!Object.keys(noticeById).length) return data;
  return Object.assign({}, data, {
    items: primaryItems.map(item => {
      if (!needsServerNoticeFallback(item)) return item;
      const notice = noticeById[String(item && item._id || '')];
      return notice ? Object.assign({}, item, { reviewNotice: notice }) : item;
    })
  });
}

// 2026-08-08：审核记录只附加一行中文推送结果，减少右侧信息拥挤。
function decorateRecord(item, source) {
  const notice = item.reviewNotice || {};
  const reviewStatus = normalizedReviewStatus(item);
  const statusLabels = { approved: '已通过', pending: '待审核', rejected: '已驳回', suspended: '已暂停' };
  const statusClasses = { approved: 'text-green', pending: 'text-orange', rejected: 'text-red', suspended: 'text-red' };
  let reviewNoticeText = source === 'worker' && reviewStatus === 'pending' ? '待审核' : '通知状态未同步';

  // 2026-10-12：小程序直审读取云端 reviewNotice；Web 后台审核缺少云端结果时由 PHP 发件箱状态补齐。
  if (source === 'worker' && reviewStatus !== 'pending' && ['sent', 'failed', 'skipped', 'queued'].indexOf(String(notice.status || '')) >= 0) {
    reviewNoticeText = getReviewNoticeMeaning(notice);
  }

  // 师傅审核列表展示资料最新时间：本人改过资料用 updatedTime，否则使用首次 createTime；publishTime 仅是审核通过时间。
  const recordTime = source === 'worker'
    ? (item.updatedTime || item.createTime)
    : (item.updatedTime || item.updateTime || item.publishTime || item.createTime);
  const displayTime = source === 'worker' ? (timestampValue(recordTime) || recordTime) : recordTime;
  return Object.assign({}, item, {
    time: displayTime ? formatTime(displayTime) : '',
    reviewStatusText: statusLabels[reviewStatus],
    reviewStatusClass: statusClasses[reviewStatus],
    reviewNoticeText,
  });
}

// 2026-09-15：兼容历史记录中的毫秒/秒时间戳、yyyyMMddHHmm 和日期字符串。
function timestampValue(value) {
  if (value instanceof Date) return value.getTime();
  if (typeof value === 'number' && Number.isFinite(value)) return value > 0 && value < 100000000000 ? value * 1000 : value;
  const text = String(value || '').trim();
  if (!text) return 0;
  if (/^(?:19|20)\d{10}$/.test(text)) {
    const parts = text.match(/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})$/);
    return parts ? new Date(Number(parts[1]), Number(parts[2]) - 1, Number(parts[3]), Number(parts[4]), Number(parts[5])).getTime() : 0;
  }
  if (/^\d{10,13}$/.test(text)) {
    const numeric = Number(text);
    return text.length === 10 ? numeric * 1000 : numeric;
  }
  const parsed = Date.parse(text);
  return Number.isFinite(parsed) ? parsed : 0;
}


Page({
  data: {
    activeSource: 'worker',
    dataList: [],
    keyword: '',
    page: 1,
    loadAll: false,
    loading: false,
    isAdmin: false,
  },

  onLoad(options) {
    const source = String((options && options.source) || 'worker')
    if (source === 'store') this.setData({ activeSource: 'store' })
  },

  onShow() {
    this.verifyAdminAndLoad()
  },

  verifyAdminAndLoad() {
    if (this.data.loading) return
    this.setData({ loading: true })
    callWorker('miniAdmin.identity').then(identity => {
      if (identity.is_admin !== true) throw new Error(identity.config_ready === false ? '后台管理员名单尚未同步到云端' : '仅管理员可查看审核列表')
      this.setData({ isAdmin: true, loading: false })
      return this.resetAndLoad()
    }).catch(error => {
      this.setData({ isAdmin: false, loading: false, dataList: [] })
      wx.showModal({
        title: '无管理员权限',
        content: error.message || '仅管理员可查看审核列表',
        showCancel: false,
        success: () => wx.navigateBack({ delta: 1 })
      })
    })
  },

  resetAndLoad() {
    this.setData({ dataList: [], page: 1, loadAll: false })
    return this.loadData(false)
  },

  switchSource(e) {
    const activeSource = String(e.currentTarget.dataset.source || '')
    if (!activeSource || activeSource === this.data.activeSource) return
    this.setData({ activeSource, keyword: '' }, () => this.resetAndLoad())
  },

  searchRecord(e) {
    this.setData({ keyword: String(e.detail.value || '').trim() }, () => this.resetAndLoad())
  },

  loadWorkerReviewPage(page, keyword) {
    return callWorker('miniAdmin.reviewList', { page, page_size: PAGE_SIZE, keyword }).then(data => {
      const items = Array.isArray(data.items) ? data.items : [];
      if (!items.some(needsServerNoticeFallback)) return data;
      // 师傅资料仍以云函数为事实源；这里只补查 PHP 可靠发件箱的发送状态，不用 PHP 覆盖云端审核资料。
      return OrderApi.request('admin.review.records', 'GET', null, {
        source: 'worker', page, page_size: PAGE_SIZE, keyword
      }).then(res => mergeServerNoticeFallback(data, res.data || {})).catch(error => {
        console.warn('[审核列表] 后台通知状态补查失败，继续显示云端审核资料', error)
        return data
      })
    })
  },

  loadData(append = true) {
    if (!this.data.isAdmin || this.data.loading || (append && this.data.loadAll)) return Promise.resolve()
    const page = append ? this.data.page : 1
    const keyword = String(this.data.keyword || '').trim()
    this.setData({ loading: true })
    wx.showLoading({ title: '数据加载中', mask: true })

    const request = this.data.activeSource === 'worker'
      ? this.loadWorkerReviewPage(page, keyword)
      : OrderApi.request('admin.review.records', 'GET', null, {
          source: this.data.activeSource,
          page,
          page_size: PAGE_SIZE,
          keyword
        }).then(res => res.data || {})

    return request.then(data => {
      const rows = (Array.isArray(data.items) ? data.items : []).map(item => decorateRecord(item, this.data.activeSource))
      this.setData({
        dataList: append && page > 1 ? this.data.dataList.concat(rows) : rows,
        page: page + 1,
        loadAll: data.has_more !== true,
      })
    }).catch(error => {
      console.error('[审核列表] 加载失败', error)
      wx.showToast({ icon: 'none', title: error.message || '加载失败，请重试' })
    }).finally(() => {
      this.setData({ loading: false })
      wx.hideLoading()
    })
  },

  goDetail(e) {
    const id = String(e.currentTarget.dataset.id || '')
    if (!id) return
    wx.navigateTo({
      url: this.data.activeSource === 'worker'
        ? '/pages/record/detail?id=' + encodeURIComponent(id)
        : '/subpackages/store/record/detail?id=' + encodeURIComponent(id),
    })
  },

  onReachBottom() {
    this.loadData(true)
  },

  onPullDownRefresh() {
    // 搜索后的下拉刷新定义为“重新加载当前审核分类全部数据”，不保留搜索条件。
    this.setData({ keyword: '' }, () => {
      Promise.resolve(this.resetAndLoad()).finally(() => wx.stopPullDownRefresh())
    })
  },
})