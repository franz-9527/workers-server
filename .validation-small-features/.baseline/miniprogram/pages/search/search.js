import { formatTime } from '../../utils/DateUtils'
const OrderApi = require('../../utils/OrderApi.js')
var app = getApp();
var currentPage = 0 // 当前第几页,0代表第一页 
var pageSize = 20 //每页显示多少数据 
var loadAll = false;
var timer = null;

Page({
  data: {
    dataList: [], //放置返回数据的数组  
    keyword:'',//搜索关键词
    Prompt:[],//提示说明
    skillOptions: ['全部', '热胶', '窗帘', '玻璃膜', '软硬包', '艺术漆', '油漆'],
    currentSkillIndex: 0, // 默认选中第一个技能
    selectedSkill: '', 
  },
  onLoad() {
    currentPage = 0;
    this.getData();
    this.getPrompt();//提示说明
  },
  onShow() {
    //实现底部导航 tab 选中态
    if (typeof this.getTabBar === 'function' && this.getTabBar()) {
      this.getTabBar().setData({
        selected: 1
      })
    }
    // 重新加载数据并应用筛选条件
    this.setData({}, () => {
      this.getData(true);
    });
  },
  getPrompt() {
    wx.showLoading({
      title: '加载中',
      mask: true,
    })
    wx.cloud.callFunction({
      name: 'record',
      data: {
        action: 'getPrompt',
        version:app.globalData.version
      },
      success: res => {
        this.setData({
          Prompt: res.result
        });
      },
      fail: err => {
        console.error('[云函数] [getPrompt] 调用失败', err);
        wx.showToast({
          icon: "none",
          title: '加载失败，请下拉刷新',
          duration: 2000,
        })
      },
      complete: () => {
        wx.hideLoading();
      }
    })
  },
  searchRecord(e) {
    let key = e.detail.value.toLowerCase();
    // 保留当前的技能筛选条件
    const currentSkillIndex = this.data.currentSkillIndex;
    const selectedSkill = this.data.selectedSkill;
    
    this.setData({
      keyword: key,
      // 保持技能筛选条件不变
      currentSkillIndex: currentSkillIndex,
      selectedSkill: selectedSkill
    })
    
    loadAll = false;
    if (timer) {
      clearTimeout(timer);// 防止不停地发请求
    }
    timer = setTimeout(() => {
      this.getData(true);// true表示重新加载数据
    }, 500);
  },
  // 技能筛选
  skillChange(e) {
    const selectedSkillIndex = e.detail.value;
    const selectedSkill = this.data.skillOptions[selectedSkillIndex]; 
    // 映射技能到其对应的过滤条件属性
    const skillToFilterProp = {
      '热胶': 'doEVA',
      '窗帘': 'doCurtain',
      '玻璃膜': 'doFilm',
      '油漆': 'doPaint',
      '艺术漆': 'dotArtpaint',
      '软硬包': 'doWallPaneling'
    };
    
    const skillValue = skillToFilterProp[selectedSkill];
    
    // 保留现有的关键词搜索
    const keyword = this.data.keyword;
    
    this.setData({
      selectedSkill,
      currentSkillIndex: selectedSkillIndex,
      // 确保关键词搜索条件保留
      keyword: keyword
    }, () => {
      // 在数据绑定完成后立即执行数据获取
      this.getData(true);
    });
  },
  //页面上拉触底事件的处理函数
  onReachBottom: function () {
    console.log("上拉触底事件")
    this.getData()
  },
  // V2.2.9：公开搜索统一经 PHP + 签名云函数，只返回展示白名单，不再读取 worker 原始文档。
  getData(reset = false) {
    if (reset) {
      currentPage = 0
      loadAll = false
    }
    if (loadAll) {
      wx.showToast({ title: '已经加载全部', duration: 1500, icon: 'none' })
      return Promise.resolve()
    }
    wx.showLoading({ title: '数据加载中', mask: true })
    return OrderApi.request('workers.search', 'GET', null, {
      page: currentPage + 1,
      page_size: pageSize,
      keyword: String(this.data.keyword || ''),
      skill: this.data.selectedSkill && this.data.selectedSkill !== '全部' ? this.data.selectedSkill : ''
    }).then(res => {
      const data = res.data || {}
      const rows = Array.isArray(data.items) ? data.items.map(item => {
        item.time = item.createTime ? formatTime(item.createTime) : ''
        return item
      }) : []
      const list = currentPage === 0 ? rows : this.data.dataList.concat(rows)
      currentPage = Math.max(currentPage + 1, Number(data.page) || 1)
      loadAll = data.has_more !== true
      this.setData({ dataList: list })
    }).catch(error => {
      console.error('[师傅搜索] 加载失败', error)
      wx.showToast({ title: error.message || '加载失败，请重试', icon: 'none' })
    }).finally(() => wx.hideLoading())
  },

  onPullDownRefresh() {
    currentPage = 0;
    loadAll = false;
    this.getData();// 这里默认是非搜索状态
    this.getPrompt();
    wx.stopPullDownRefresh();
    //reload
  },
  //跳转详情
  goDetail(e){
    let id = e.currentTarget.dataset.id;
    wx.navigateTo({
      url: '/pages/record/detail?id='+id
    })
  },
  // 将技能选项映射到对应的字段名
  getSkillField(skill) {
    const skillMap = {
      '热胶': 'doEVA',
      '窗帘': 'doCurtain',
      '玻璃膜': 'doFilm',
      '油漆': 'doPaint',
      '艺术漆': 'dotArtpaint',
      '软硬包': 'doWallPaneling'
    };
    return skillMap[skill] || '';
  },
})
