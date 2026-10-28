// components/addTip/addTip.js
const STORAGE_KEY_ONE = 'ADD-MYAPP-KEY-ONE';
const DISMISS_INTERVAL_MS = 30 * 24 * 60 * 60 * 1000;

function wasDismissedRecently() {
  const dismissedAt = Number(wx.getStorageSync(STORAGE_KEY_ONE));
  return dismissedAt > 0 && Date.now() - dismissedAt < DISMISS_INTERVAL_MS;
}

Component({
  /**
   * 组件的属性列表
   */
  properties: {
    // 提示文字
    text: {
      type: String,
      value: '「添加小程序」访问更便捷!'
    }
  },

  /**
   * 组件的初始数据
   */
  data: {
    SHOW_TOP: false,
    SHOW_TOP_key: 1,
    marRight: 66
  },
  ready: function () {
    this.initTips();
  },
  /**
   * 组件的方法列表
   */
  methods: {
    // 2025.2.15 增加检测是否已添加到我的小程序
    initTips: function () {
      if (wasDismissedRecently()) return;

      // 检查接口是否可用
      if (!wx.canIUse('checkIsAddedToMyMiniProgram')) {
        this.initTipsWithoutCheck();
        return;
      }

      wx.checkIsAddedToMyMiniProgram({
        success: (res) => {
          if (res.added) {
            return; // 如果已添加，直接返回不显示提示
          }
          
          // 接口异步返回前用户可能已关闭提示，再检查一次。
          if (wasDismissedRecently()) return;

          // 2025.2.15 wx.getSystemInfoSync()停止维护修改接口
          // 处理根据系统信息处理位移箭头位置
          // let systemInfo = wx.getSystemInfoSync();
          // let client = wx.getMenuButtonBoundingClientRect();
          // if (systemInfo && client) {
          //   this.setData({
          //     marRight: systemInfo.screenWidth - client.left - 28
          //   });
          // }
          // this.setData({
          //   SHOW_TOP: true
          // });

          // 使用新API获取窗口和菜单按钮信息
          try {
            const windowInfo = wx.getWindowInfo();
            const menuButtonInfo = wx.getMenuButtonBoundingClientRect();
            
            if (windowInfo && menuButtonInfo) {
              this.setData({
                marRight: windowInfo.screenWidth - menuButtonInfo.left - 28
              });
            }
            
            this.setData({
              SHOW_TOP: true
            });
          } catch (error) {
            console.error('获取系统信息失败:', error);
          }
        },
        fail: (error) => {
          console.error('检查是否已添加到我的小程序失败:', error);
          this.initTipsWithoutCheck();
        }
      });
    },

    // 原有的初始化逻辑，作为备用方案
    initTipsWithoutCheck: function() {
      if (wasDismissedRecently()) return;
      // 处理根据系统信息处理位移箭头位置（重点）
      let systemInfo = wx.getSystemInfoSync();
      let client = wx.getMenuButtonBoundingClientRect();
      if (systemInfo && client) {
        this.setData({
          marRight: systemInfo.screenWidth - client.left - 28
        });
      }
      // 没显示过，则进行展示
      this.setData({
        SHOW_TOP: true
      });
    },

    // 显示全屏添加说明
    showModal: function () {
      this.setData({
        SHOW_TOP: false,
        SHOW_MODAL: true
      });
    },
    okHandler: function () {
      this.setData({
        SHOW_TOP: false
      });
      wx.setStorageSync(STORAGE_KEY_ONE, Date.now());
    }
  }
})
