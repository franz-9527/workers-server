// 2026-08-07：集中维护订阅消息模板，后续新增订单类模板时只需在此处扩展。
const SUBSCRIBE_TEMPLATES = {
  reviewResult: {
    templateId: 'b7RKsUBxQu3BWwMEwzw-oFHkw1K9m4egjVFSLQ5rWMY',
    title: '入驻审核结果通知',
    description: '师傅入驻申请审核通过后通知',
  },
  orderMessage: {
    templateId: '',
    title: '订单消息通知',
    description: '预留订单接单、状态变更等消息订阅位置',
  },
};

module.exports = {
  SUBSCRIBE_TEMPLATES,
  REVIEW_RESULT_TEMPLATE_ID: SUBSCRIBE_TEMPLATES.reviewResult.templateId,
};
