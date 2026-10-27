//小程序配置
const ApiEnvironment = require('../utils/ApiEnvironment.js')
const MINIPROGRAM_VERSION = '2.6.5'
const API_VERSION = '2.5.0'

const APPCONFIG = {
  // 小程序展示/发布版本与后台 API/数据库版本允许独立推进。
  // 仅小程序升级时只调整 MINIPROGRAM_VERSION；涉及后台/数据库时再按发行规则同步。
  VERSION: MINIPROGRAM_VERSION,
  VERSIONTEXT: MINIPROGRAM_VERSION
}

// 2026-08-23：新订单系统与后台使用同一域名，避免额外配置第二个业务域名。
const APICONFIG = {
  get BASEURL() { return ApiEnvironment.current().baseURL },
  // 发行包版本与 server/VERSION、后台配置及旧云函数调用统一使用当前版本号。
  VERSION: API_VERSION
}

//云开发环境配置
const CLOUDCONFIG = {
  CLOUDENV:'worker-real',//云开发环境，留空默认使用第一个创建的环境
}

//地图相关配置
const MAPCONFIG = {
  MAPKEY: '32CBZ-FEPK6-POMSA-MCDE2-M2MIK-ZOBK2',//使用在腾讯位置服务申请的key
  DEFLOCATION: { name: "", latitude: 29.950894, longitude: 120.567175, city: "绍兴市", address: "" }//默认坐标

}

module.exports = {
  APPCONFIG:APPCONFIG,
	CLOUDCONFIG:CLOUDCONFIG,
	MAPCONFIG: MAPCONFIG,
  APICONFIG: APICONFIG
}
