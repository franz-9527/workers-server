// pages/test/test.js
const OrderApi = require('../../utils/OrderApi.js')
Page({
  /**
   * 页面的初始数据
   */
  data: {

  },
  /**
   * 生命周期函数--监听页面加载
   */
  onLoad(options) {

  },

  /**
   * 生命周期函数--监听页面初次渲染完成
   */
  onReady() {

  },

  /**
   * 生命周期函数--监听页面显示
   */
  onShow() {

  },

  /**
   * 生命周期函数--监听页面隐藏
   */
  onHide() {

  },

  /**
   * 生命周期函数--监听页面卸载
   */
  onUnload() {

  },

  //搜索
  showInput: function () {
    this.setData({
      inputShowed: true
    });
  },
  // 隐藏搜索框样式
  hideInput: function () {
    this.setData({
      inputVal: "",
      inputShowed: false
    });
  },
  // 清除搜索框值
  clearInput: function () {
    this.setData({
      inputVal: ""
    });
  },
  // 键盘抬起事件
  inputTyping: function (e) {
    console.log(e.detail.value)
    var that = this;
    if (e.detail.value == '') {
      return;
    }
    that.setData({
      viewShowed: false,
      inputVal: e.detail.value
    });
      // V2.2.9：实验页也只使用脱敏后的师傅搜索接口，不直接读取 worker 原始文档。
      OrderApi.request('workers.search', 'GET', null, {
        page: 1,
        page_size: 10,
        keyword: String(e.detail.value || ''),
        skill: ''
      }).then(res => {
        that.setData({ search_list1: (res.data && res.data.items) || [] })
      }).catch(error => {
        console.warn('[实验页] 师傅搜索失败', error)
        that.setData({ search_list1: [] })
      })
    },
  
  //推广赚钱
  goEmbeddedMiniProgram2(e) {
    wx.openEmbeddedMiniProgram({
      appId: 'wx2e4158c10c8005fc',
      path: 'promoter/pages/recruit?behavior=recruiting&invite_id=0',
      allowFullScreen:'true',
      success(res) {
        // 打开成功
      }
    })
  },
  //打赏捐助
  goPay() {
    wx.navigateTo({
      url: '/pages/pay/pay'
    })
  },
  goSizeMark2() {
    wx.navigateTo({
      url: '/pages/h5/h5?title=mp'
    })
  },
  // 旧“相关协议”入口从“我的”移至实验功能，正式协议统一使用平台协议中心。
  goPact() {
    wx.openEmbeddedMiniProgram({
      appId: 'wx9ed353c2ad8fda21',
      path: 'pages/code/code?q=http%3A%2F%2Fqr71.cn%2FochsuX%2Fq0YWUrp'
    })
  },
   
})