function money(cents) {
  const value = Number(cents)
  return '¥' + (Number.isFinite(value) ? value / 100 : 0).toFixed(2)
}

function clampMoney(cents, maximum) {
  const value = Math.max(0, Number(cents) || 0)
  return Math.min(Math.max(0, Number(maximum) || 0), value)
}

Component({
  properties: {
    pricing: { type: Object, value: null },
    orderState: { type: Object, value: null },
    viewerMode: { type: String, value: '' }
  },
  data: {
    visible: false,
    viewerLabel: '师傅结算',
    settlementLabel: '待确定结算方式',
    settlementStatus: 'pending',
    settlementStatusText: '待验收结算',
    workerLaborText: money(0),
    platformIncomeLabel: '预计平台收入款',
    workerRemitText: money(0),
    showWorkerBalance: false,
    workerBalanceLabel: '预计记入师傅余额',
    workerBalanceText: money(0),
    settlementRuleText: '',
    platformIncomeHint: '',
    estimateText: ''
  },
  observers: {
    'pricing,orderState,viewerMode': function () { this.refresh() }
  },
  methods: {
    refresh() {
      const pricing = this.properties.pricing || {}
      const viewerMode = String(this.properties.viewerMode || '')
      const visible = viewerMode === 'accepted_worker' || viewerMode === 'admin'
      if (!visible) {
        this.setData({ visible: false })
        return
      }

      const customerTotal = Math.max(0, Number(pricing.customer_contract_total_cents) || 0)
      const stage = String(pricing.settlement_status || 'pending')
      const hasSnapshot = stage === 'awaiting_platform_income' || stage === 'settled'
      const externalPrepayment = clampMoney(pricing.booking_paid_cents, customerTotal)
      const customerTail = hasSnapshot
        ? clampMoney(pricing.customer_tail_cents, customerTotal)
        : Math.max(0, customerTotal - externalPrepayment)
      const labor = Math.max(0, Number(pricing.worker_labor_cents) || 0)
      const mode = String(pricing.worker_settlement_mode || '')
      const remit = hasSnapshot
        ? Math.max(0, Number(pricing.worker_remit_to_platform_cents !== undefined ? pricing.worker_remit_to_platform_cents : pricing.platform_receivable_cents) || 0)
        : (mode === 'worker_collect' ? Math.max(0, customerTotal - labor - externalPrepayment) : customerTail)
      const workerCredit = hasSnapshot
        ? Math.max(0, Number(pricing.worker_balance_credit_cents) || 0)
        : (mode === 'platform_balance' ? labor : 0)
      const labels = { worker_collect: '师傅代收结算', platform_balance: '平台余额结算' }
      const statuses = {
        pending: '待验收结算',
        awaiting_platform_income: '待平台确认收入款到账',
        settled: '平台收入款已结清'
      }
      const freezeDays = Math.max(0, Number(pricing.worker_balance_freeze_days_snapshot) || 0)
      const workerBalanceLabel = !hasSnapshot ? '验收预计记入师傅余额'
        : (freezeDays > 0 ? (pricing.worker_balance_released ? '已解冻至师傅可提现余额' : '已记入师傅冻结余额') : '已记入师傅可提现余额')
      const platformIncomeLabel = !hasSnapshot ? '预计平台收入款'
        : (stage === 'settled' ? '已结清平台收入款' : '待核对平台收入款')
      const settlementRuleText = mode === 'platform_balance'
        ? '客户现场剩余应收全部属于平台；师傅工费由平台另行记入师傅余额，不重复扣普通订单服务费。'
        : '师傅现场代收客户剩余款，从中留下约定工费，其余作为平台收入款交平台；不另扣普通订单服务费。'
      const platformIncomeHint = stage === 'pending'
        ? '当前为预计金额。外部已收预付款来自平台托管条款，验收时固化为结算快照；不会生成微信预约费记录。'
        : (stage === 'awaiting_platform_income'
          ? '客户验收已完成，平台收入款尚待后台按真实收款流水核对到账。'
          : '平台收入款已按结算流程核对完成；师傅余额最终以钱包台账为准。')
      const estimateText = hasSnapshot
        ? '本区金额已锁定为验收结算快照。'
        : ('客户计价层当前按外部已收预付款 ' + money(externalPrepayment) + ' 抵扣。')

      this.setData({
        visible: true,
        viewerLabel: viewerMode === 'admin' ? '管理员可见' : '师傅结算',
        settlementLabel: labels[mode] || '待确定结算方式',
        settlementStatus: stage,
        settlementStatusText: statuses[stage] || '结算状态待确认',
        workerLaborText: money(labor),
        platformIncomeLabel,
        workerRemitText: money(remit),
        showWorkerBalance: mode === 'platform_balance',
        workerBalanceLabel,
        workerBalanceText: money(workerCredit),
        settlementRuleText,
        platformIncomeHint,
        estimateText
      })
    }
  }
})