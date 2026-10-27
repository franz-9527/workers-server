'use strict'
const assert = require('assert')
const fs = require('fs')
const path = require('path')

const root = path.resolve(__dirname, '..')
const wxml = fs.readFileSync(path.join(root, 'miniprogram/pages/userOrder/detail.wxml'), 'utf8')
const detailView = fs.readFileSync(path.join(root, 'server/app/Infrastructure/Persistence/OrderDetailFullView.php'), 'utf8')

assert(detailView.includes("'order_source_type'=>$sourceType"), '订单详情必须返回服务来源事实')
assert(detailView.includes("'is_merchant_order'=>$isMerchant"), '订单详情必须直接返回商家订单标记，前端不得靠收款码等间接事实猜测')

assert(wxml.includes('wx:if="{{order.is_merchant_order && actions.is_owner && !actions.is_admin}}"'), '角色卡只应展示给正在查看自己商家订单的 owner，不应展示给普通消费者或管理员视角')
assert(wxml.includes('本单角色与确认'), '商家订单需要明确角色说明')
assert(wxml.includes('<text>下单方</text><text class="right">当前商家账号</text>'), '需要明确下单方是当前商家账号')
assert(wxml.includes('<text>现场对接</text><text class="right">{{order.contact_name}} {{order.contact_mobile}}</text>'), '现场对接继续使用订单现场联系人事实')
assert(wxml.includes('<text>付款与确认</text><text class="right">当前下单商家账号</text>'), '需要明确付款/确认仍由下单账号完成')
assert(wxml.includes('现场对接人仅用于师傅上门联系'), '必须说明现场联系人只是履约联系角色')
assert(wxml.includes('不因填写姓名或手机号获得支付、接受加价、验收等账号权限'), '不得把联系人手机号解释成账号授权')
assert(wxml.includes('师傅手机号继续按平台隐私规则开放'), '角色说明不得放宽师傅手机号隐私')
assert(wxml.includes('师傅电话默认隐藏，请等待师傅主动联系并预约'), '既有师傅电话隐私提示必须保留')

console.log('V2.5.0 merchant order roles UI: merchant owner + onsite contact boundary OK')