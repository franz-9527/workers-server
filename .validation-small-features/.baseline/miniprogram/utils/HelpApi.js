const ApiEnvironment = require('./ApiEnvironment.js')
const OrderApi = require('./OrderApi.js')

const CACHE_KEY = 'help_center_cache_v240'
const CACHE_MAX_AGE = 2 * 60 * 60 * 1000


const BUILTIN_CATEGORIES = [
  { id: -2401, code: 'find', name: '找师傅', sort_order: 10, homepage_featured: false },
  { id: -2402, code: 'order', name: '下单', sort_order: 20, homepage_featured: false },
  { id: -2403, code: 'payment', name: '报价付款', sort_order: 30, homepage_featured: false },
  { id: -2404, code: 'construction', name: '施工', sort_order: 40, homepage_featured: false },
  { id: -2405, code: 'measurement', name: '测量', sort_order: 50, homepage_featured: false },
  { id: -2406, code: 'refund', name: '退款', sort_order: 60, homepage_featured: false },
  { id: -2407, code: 'aftersales', name: '售后', sort_order: 70, homepage_featured: false },
  { id: -2408, code: 'account', name: '账号安全', sort_order: 80, homepage_featured: false },
  { id: -2409, code: 'finance', name: '余额结算', sort_order: 90, homepage_featured: false }
]

const BUILTIN_ITEMS = [
  {
    faq_id: 'Z9001',
    category_code: 'account',
    category_name: '账号安全',
    question: '为什么当前只显示少量帮助内容？',
    answer: '当前未连接到帮助中心服务，并且本机没有可用的最近缓存，所以先显示小程序内置的基础帮助。网络恢复后下拉刷新即可读取后台已发布内容。\n\n内置问答只用于断网兜底和前端界面预览，不包含价格、费用、会员权益、退款时限等可能变化的业务规则。具体业务信息请以联网后帮助中心、订单详情和实际页面为准。',
    aliases: ['网络异常', '加载失败', '离线帮助'],
    keywords: ['网络', '加载', '离线', '刷新'],
    roles: ['all'],
    is_featured: true,
    related_faq_ids: ['Z9002'],
    updated_at: '',
    business_entry: null
  },
  {
    faq_id: 'Z9002',
    category_code: 'find',
    category_name: '找师傅',
    question: '如何使用帮助中心？',
    answer: '联网后可以直接搜索关键词，也可以先按身份筛选常见问题，再从下方按业务场景浏览。点击问题即可原位展开答案。\n\n帮助中心会优先读取后台已经发布的最新内容；网络暂时不可用时会先尝试最近 2 小时的已发布缓存，再使用这两条基础问答保证页面可以正常打开和预览。',
    aliases: ['怎么使用', '搜索问题', '联系客服'],
    keywords: ['搜索', '分类', '客服', '帮助中心'],
    roles: ['all'],
    is_featured: true,
    related_faq_ids: ['Z9001'],
    updated_at: '',
    business_entry: null
  }
]

function builtinFallbackData() {
  return {
    categories: BUILTIN_CATEGORIES.map(item => Object.assign({}, item)),
    items: BUILTIN_ITEMS.map(item => Object.assign({}, item)),
    content_updated_at: ''
  }
}

function now() {
  return Date.now()
}

function saveCache(data) {
  if (!data || !Array.isArray(data.items)) return
  try {
    wx.setStorageSync(ApiEnvironment.storageKey(CACHE_KEY), {
      savedAt: now(),
      data
    })
  } catch (error) {
    console.warn('[帮助中心] 缓存写入失败', error)
  }
}

function getCache() {
  try {
    const cached = wx.getStorageSync(ApiEnvironment.storageKey(CACHE_KEY))
    if (!cached || !cached.savedAt || !cached.data) return null
    const age = now() - Number(cached.savedAt)
    if (age < 0 || age > CACHE_MAX_AGE) {
      wx.removeStorageSync(CACHE_KEY)
      return null
    }
    return {
      data: cached.data,
      age
    }
  } catch (error) {
    return null
  }
}

function bootstrap(role) {
  return OrderApi.request('help.bootstrap', 'GET', null, { role: role || 'all' })
    .then(result => {
      const data = result.data || {}
      saveCache(data)
      return { data, cached: false }
    })
    .catch(error => {
      if (error.code === 'API_ENV_CHANGED' || error.code === 'API_ENV_FORBIDDEN') throw error
      const cached = getCache()
      if (cached) {
        return {
          data: cached.data,
          cached: true,
          builtIn: false,
          cacheAge: cached.age,
          error
        }
      }
      return {
        data: builtinFallbackData(),
        cached: false,
        builtIn: true,
        error
      }
    })
}

function normalizeText(value) {
  return String(value || '').toLowerCase().replace(/\s+/g, '')
}

function localSearch(query, role, items) {
  const needle = normalizeText(query)
  if (!needle) return []
  const activeRole = role || 'all'
  return (items || []).map(item => {
    const question = normalizeText(item.question)
    const answer = normalizeText(item.answer)
    const category = normalizeText(item.category_name)
    const aliases = normalizeText(Array.isArray(item.aliases) ? item.aliases.join(' ') : item.aliases)
    const keywords = normalizeText(Array.isArray(item.keywords) ? item.keywords.join(' ') : item.keywords)
    let score = 0
    if (question === needle) score += 100
    else if (question.indexOf(needle) === 0) score += 90
    else if (question.indexOf(needle) >= 0) score += 80
    if (aliases.indexOf(needle) >= 0) score += 65
    if (keywords.indexOf(needle) >= 0) score += 55
    if (category.indexOf(needle) >= 0) score += 40
    if (answer.indexOf(needle) >= 0) score += 30
    const roles = Array.isArray(item.roles) ? item.roles : []
    if (activeRole !== 'all' && (roles.indexOf('all') >= 0 || roles.indexOf(activeRole) >= 0)) score += 15
    return { item, score }
  }).filter(row => row.score > 0)
    .sort((a, b) => b.score - a.score)
    .slice(0, 50)
    .map(row => row.item)
}

function search(query, role, fallbackItems) {
  const q = String(query || '').trim()
  if (!q) return Promise.resolve({ items: [], cached: false })
  return OrderApi.request('help.search', 'GET', null, { q, role: role || 'all' })
    .then(result => ({
      items: (result.data && result.data.items) || [],
      resultCount: Number(result.data && result.data.result_count) || 0,
      cached: false
    }))
    .catch(error => {
      const items = localSearch(q, role, fallbackItems || [])
      if (!items.length) throw error
      return {
        items,
        resultCount: items.length,
        cached: true,
        error
      }
    })
}

function detail(faqId) {
  const id = String(faqId || '').toUpperCase()
  if (!/^[A-Z][0-9]{2,5}$/.test(id)) return Promise.reject(new Error('问题编号无效'))
  return OrderApi.request('help.detail', 'GET', null, { faq_id: id }).then(result => result.data || null)
}

function recordClick(faqId, query) {
  if (!faqId || !query) return Promise.resolve()
  return OrderApi.request('help.search-click', 'POST', {
    faq_id: faqId,
    query: query
  }).catch(() => {})
}

module.exports = {
  bootstrap,
  search,
  detail,
  recordClick
}
