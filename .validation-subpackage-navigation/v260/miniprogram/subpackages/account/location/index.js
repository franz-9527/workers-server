import { MAPCONFIG } from '../../../config/config';
const app = getApp();
const chooseLocationPlugin = requirePlugin('chooseLocation');

// 2026年8月4日 17:22:23
Page({
  data: {
    location: {},
    addressText: '',
    Note: [],
    copy: {
      title: '设置居住地址',
      description: '平台会根据您设置的居住地址为中心点',
      label: '居住地址',
      emptyAddress: '请选择居住地址',
      submit: '保存设置',
      success: '修改成功',
      failure: '修改失败',
      network: '网络请求失败',
      noWorker: '暂无生效的师傅信息 '
    },
    saving: false
  },
  onLoad() {
    this.getLocationPrompt();
    this.loadActiveLocation();
  },
  onShow() {
    if (!this.waitingForLocation) return;
    this.waitingForLocation = false;
    const location = chooseLocationPlugin.getLocation();
    if (location && location.latitude && location.longitude) this.setLocation(location);
  },
  chooseLocation() {
    this.waitingForLocation = true;
    wx.navigateTo({ url: 'plugin://chooseLocation/index?key=' + MAPCONFIG.MAPKEY + '&referer=worker' });
  },
  getLocationPrompt() {
    wx.cloud.callFunction({
      name: 'record',
      data: { action: 'getLocationPrompt' },
      success: res => {
        if (Array.isArray(res.result)) this.setData({ Note: res.result });
      }
    });
  },
  loadActiveLocation() {
    wx.cloud.callFunction({
      name: 'worker',
      data: { action: 'getActiveLocation' },
      success: res => {
        const data = res.result && res.result.data;
        const location = data && (data.location || data);
        if (res.result && res.result.code === 1 && location) this.setLocation(this.mergeGeo(location, data.geo));
      }
    });
  },
  mergeGeo(location, geo) {
    const result = Object.assign({}, location);
    const coordinates = geo && geo.coordinates;
    if ((!result.latitude || !result.longitude) && coordinates && coordinates.length === 2) {
      result.longitude = coordinates[0];
      result.latitude = coordinates[1];
    }
    return result;
  },
  setLocation(location) { this.setData({ location, addressText: this.formatAddress(location) }); },
  formatAddress(location) {
    if (!location) return '';
    return location.district && location.name ? location.district + location.name : location.address || location.name || '';
  },
  saveLocation() {
    const location = this.data.location;
    if (!location.latitude || !location.longitude) return;
    this.setData({ saving: true });
    wx.cloud.callFunction({
      name: 'worker',
      data: { action: 'updateActiveLocation', location },
      success: res => {
        const result = res.result || {};
        if (result.code === 1) {
          app.globalData.userInfo = app.globalData.userInfo || {};
          app.globalData.userInfo.location = location;
          wx.setStorageSync('userInfo', app.globalData.userInfo);
          wx.showToast({ title: result.msg || this.data.copy.success });
          setTimeout(() => wx.navigateBack(), 800);
        } else {
          wx.showToast({ icon: 'none', title: result.msg || (result.errorCode === 'NO_ACTIVE_WORKER' ? this.data.copy.noWorker : this.data.copy.failure) });
        }
      },
      fail: () => wx.showToast({ icon: 'none', title: this.data.copy.network }),
      complete: () => this.setData({ saving: false })
    });
  }
});
