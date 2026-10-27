// 微信服务市场 OCR 统一适配层。
// 服务：https://fuwu.weixin.qq.com/service/detail/000ce4cec24ca026d37900ed551415
// OcrAllInOne：1=身份证，7=营业执照。
// 重要：normalized 只保留接口文档明确返回的原字段名，不自行发明同义字段/别名。
const OrderApi = require('./OrderApi.js')
const SERVICE_ID = 'wx79ac3de8be320b71'
const API_NAME = 'OcrAllInOne'
const OCR_TYPE = { ID_CARD: 1, BUSINESS_LICENSE: 7 }

function toObject(value) {
  if (!value) return {}
  if (typeof value === 'object' && !Array.isArray(value)) return value
  if (typeof value === 'string') {
    try {
      const parsed = JSON.parse(value)
      return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {}
    } catch (error) {
      return {}
    }
  }
  return {}
}

function plainClone(value) {
  try { return JSON.parse(JSON.stringify(value || {})) } catch (error) { return {} }
}

function unwrapResponse(response) {
  let result = toObject(response && response.data)
  if (!Object.keys(result).length) result = toObject(response)
  const nested = toObject(result.data)
  if (Object.keys(nested).length) result = nested
  return result
}

function fieldText(value) {
  if (value === 0) return '0'
  if (!value) return ''
  if (typeof value === 'string' || typeof value === 'number') return String(value).trim()
  if (typeof value === 'object' && value.text !== undefined && value.text !== null) return String(value.text).trim()
  return ''
}

function documentedText(source, key) {
  return fieldText(toObject(source)[key])
}

function documentedRaw(source, key) {
  const value = toObject(source)[key]
  if (value === undefined || value === null) return null
  if (typeof value === 'object') return plainClone(value)
  return value
}

function buildImageData(filePath, ocrType) {
  if (wx.serviceMarket && typeof wx.serviceMarket.CDN === 'function') {
    return {
      img_url: new wx.serviceMarket.CDN({ type: 'filePath', filePath }),
      data_type: 3,
      ocr_type: ocrType
    }
  }

  const fs = wx.getFileSystemManager()
  const base64 = fs.readFileSync(filePath, 'base64')
  if (!base64) throw new Error('无法读取证件图片，请重新拍摄')
  return { img_data: base64, data_type: 2, ocr_type: ocrType }
}

function normalizeError(error) {
  const raw = error && (error.errMsg || error.errmsg || error.message)
  if (!raw) return new Error('微信 OCR 调用失败，请重新拍摄后重试')
  const text = String(raw).replace(/^Error:\s*/i, '').trim()
  return new Error(text.indexOf('serviceMarket') >= 0 ? '微信 OCR 服务不可用，请确认服务市场已开通并授权当前小程序' : text)
}

function makeClientMsgId(ocrType) {
  return ['bxocr', ocrType, Date.now().toString(36), Math.random().toString(36).slice(2, 12)].join('-')
}

function invoke(filePath, ocrType) {
  if (!filePath) return Promise.reject(new Error('未获取到证件图片'))
  if (!wx.serviceMarket || typeof wx.serviceMarket.invokeService !== 'function') {
    return Promise.reject(new Error('当前微信版本不支持服务市场 OCR，请升级微信后重试'))
  }

  let data
  try {
    data = buildImageData(filePath, ocrType)
  } catch (error) {
    return Promise.reject(normalizeError(error))
  }

  const clientMsgId = makeClientMsgId(ocrType)
  return new Promise((resolve, reject) => {
    wx.serviceMarket.invokeService({
      service: SERVICE_ID,
      api: API_NAME,
      client_msg_id: clientMsgId,
      data,
      success: response => resolve({
        result: unwrapResponse(response),
        rawResponse: plainClone(response),
        clientMsgId
      }),
      fail: error => reject(normalizeError(error))
    })
  })
}

function recognizeIdCard(filePath) {
  return invoke(filePath, OCR_TYPE.ID_CARD).then(bundle => {
    const card = toObject(bundle.result.idcard_res || bundle.result)
    return {
      // OcrAllInOne 身份证文档/返回示例中的字段名保持原样。
      type: documentedRaw(card, 'type'),
      name: documentedText(card, 'name'),
      gender: documentedText(card, 'gender'),
      nationality: documentedText(card, 'nationality'),
      birth: documentedText(card, 'birth'),
      address: documentedText(card, 'address'),
      id: documentedText(card, 'id').replace(/\s+/g, '').toUpperCase(),
      valid_date: documentedText(card, 'valid_date'),
      card_position: documentedRaw(card, 'card_position'),
      image_width: documentedRaw(card, 'image_width'),
      image_height: documentedRaw(card, 'image_height'),
      card_property: documentedRaw(card, 'card_property'),
      clientMsgId: bundle.clientMsgId,
      rawResponse: bundle.rawResponse
    }
  })
}

function recognizeBusinessLicense(filePath) {
  return invoke(filePath, OCR_TYPE.BUSINESS_LICENSE).then(bundle => {
    const license = toObject(bundle.result.biz_license_res || bundle.result)
    return {
      // 营业执照只采用接口文档明确字段，不自行增加“登记机关”等推测字段。
      reg_num: documentedText(license, 'reg_num'),
      serial: documentedText(license, 'serial'),
      legal_representative: documentedText(license, 'legal_representative'),
      enterprise_name: documentedText(license, 'enterprise_name'),
      type_of_organization: documentedText(license, 'type_of_organization'),
      address: documentedText(license, 'address'),
      type_of_enterprise: documentedText(license, 'type_of_enterprise'),
      business_scope: documentedText(license, 'business_scope'),
      registered_capital: documentedText(license, 'registered_capital'),
      paid_in_capital: documentedText(license, 'paid_in_capital'),
      valid_period: documentedText(license, 'valid_period'),
      registered_date: documentedText(license, 'registered_date'),
      cert_position: documentedRaw(license, 'cert_position'),
      img_size: documentedRaw(license, 'img_size'),
      image_path: documentedText(license, 'image_path'),
      clientMsgId: bundle.clientMsgId,
      rawResponse: bundle.rawResponse
    }
  })
}

function normalizedPayload(result) {
  const copy = Object.assign({}, result || {})
  delete copy.rawResponse
  delete copy.clientMsgId
  return copy
}

function saveResult(scene, ocrType, result) {
  if (!result || !result.clientMsgId) return Promise.reject(new Error('OCR 结果缺少请求编号，无法安全保存'))
  return OrderApi.request('ocr.result.save', 'POST', {
    scene,
    ocr_type: ocrType,
    client_msg_id: result.clientMsgId,
    normalized: normalizedPayload(result),
    raw: plainClone(result.rawResponse)
  })
}

module.exports = {
  SERVICE_ID,
  API_NAME,
  OCR_TYPE,
  recognizeIdCard,
  recognizeBusinessLicense,
  saveResult
}
