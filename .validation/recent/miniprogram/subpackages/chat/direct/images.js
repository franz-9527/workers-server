const MAX_BYTES = 3 * 1024 * 1024
const isFinal = state => ['accepted', 'rejected', 'canceled'].includes(state)
const fail = code => Object.assign(new Error(code), { code })
const states = {
  editing: '图片已选好，确认后发送。',
  checking: '图片正在处理和审核。',
  review_pending: '图片正在等待人工审核。',
  retryable: '图片检查暂未完成，可以重试。',
  unknown: '图片发送结果待确认。'
}

const directImages = {
  imageRows() {
    if (!this.manager.directImageAttempts) this.manager.directImageAttempts = new Map()
    return this.manager.directImageAttempts
  },
  stopImageUploads() {
    for (const task of this.imageUploads) task.abort()
    this.imageUploads.clear()
  },
  resetSceneImages() {
    if (this.sceneImageObserver) this.sceneImageObserver.disconnect()
    this.sceneImageObserver = null
    this.sceneImageJobs?.clear()
    this.sceneImageJobs = new Map()
    this.sceneImageCache = new Map()
  },
  refreshSceneImageObserver() {
    if (this.sceneImageObserver) this.sceneImageObserver.disconnect()
    this.sceneImageObserver = null
    if (!this.visible || this.data.inbox || !this.data.messages?.some(row=>row.images?.length)) return
    const create = typeof this.createIntersectionObserver === 'function'
      ? () => this.createIntersectionObserver({observeAll:true,nativeMode:true})
      : typeof wx.createIntersectionObserver === 'function'
        ? () => wx.createIntersectionObserver(this,{observeAll:true,nativeMode:true})
        : null
    if (!create) {
      const visible=[]
      for (const row of this.data.messages.slice(-8)) for (const photo of (row.images||[]).slice(0,4)) visible.push([row.message_id,photo.item_id])
      for (const [message,item] of visible.slice(0,12)) this.resolveSceneImageThumb(message,item)
      return
    }
    const observer=create()
    observer.relativeToViewport({top:120,bottom:160})
    observer.observe('.scene-chat-image',entry=>{
      if (entry.intersectionRatio <= 0 || !this.visible) return
      const data=entry.dataset||{}
      this.resolveSceneImageThumb(String(data.message||''),String(data.item||''))
    })
    this.sceneImageObserver=observer
  },
  async resolveSceneImageThumb(message,item) {
    const row=this.data.messages.find(value=>String(value.message_id)===String(message))
    const photo=row?.images?.find(value=>String(value.item_id)===String(item))
    if (!row || !photo || row.state !== 'published') return
    const version=String(row.version||''),key=message+':'+item+':'+version
    const cached=this.sceneImageCache?.get(key)
    if (cached?.url && cached.expiresAt > Date.now()+5000) {
      if (photo.thumb !== cached.url) this.applySceneImageThumb(message,item,version,cached.url)
      return
    }
    if (!this.sceneImageJobs) this.sceneImageJobs=new Map()
    if (this.sceneImageJobs.has(key)) return this.sceneImageJobs.get(key)
    const serial=this.serial
    const job=(async()=>{
      try{
        const result=await this.http(serial,'media/url',{conversation_id:this.data.conversation,message_id:message,item_id:item,thumbnail:true})
        if(!this.active(serial)||!result?.cloud_available||!/^https:\/\//.test(result.url||''))return
        if(String(result.state_version||'')!==version||Number(result.expires_at)*1000<=Date.now())return
        if(!this.sceneImageCache)this.sceneImageCache=new Map()
        this.sceneImageCache.set(key,{url:result.url,expiresAt:Number(result.expires_at)*1000})
        this.applySceneImageThumb(message,item,version,result.url)
      }catch(err){
        if(this.active(serial)&&['CHAT_UNAUTHORIZED','CHAT_FORBIDDEN','CHAT_PERMISSION_DENIED','CHAT_DIRECT_DISABLED'].includes(err.code))this.failure(serial,err)
      }
    })().finally(()=>{if(this.sceneImageJobs?.get(key)===job)this.sceneImageJobs.delete(key)})
    this.sceneImageJobs.set(key,job)
    return job
  },
  applySceneImageThumb(message,item,version,url) {
    let changed=false
    const messages=this.data.messages.map(row=>{
      if(String(row.message_id)!==String(message)||String(row.version||'')!==String(version))return row
      let rowChanged=false
      const images=(row.images||[]).map(photo=>{
        if(String(photo.item_id)!==String(item)||photo.thumb===url)return photo
        changed=true
        rowChanged=true
        return {...photo,thumb:url}
      })
      return rowChanged?{...row,images}:row
    })
    if(changed)this.setData({messages})
  },
  chooseImages(event) {
    if (!this.data.ready || !this.data.imagesEnabled || this.data.busy || this.imageRows().has(this.key)) return
    const identity = this.manager.identityEpoch
    const selection = ++this.selectionSerial
    const source=event?.currentTarget?.dataset?.source==='camera'?'camera':'album'
    this.setData({showTools:false})
    wx.chooseMedia({
      count: source==='camera'?1:9,
      mediaType: ['image'],
      sizeType: ['compressed'],
      sourceType: [source],
      success: result => {
        if (this.unloaded || identity !== this.manager.identityEpoch || selection !== this.selectionSerial) return
        this.imageSelection = { identity, files: result.tempFiles || [] }
        if (this.visible && this.data.ready) this.consumeImageSelection()
      }
    })
  },
  consumeImageSelection() {
    const selection = this.imageSelection
    this.imageSelection = null
    if (!selection || selection.identity !== this.manager.identityEpoch || !this.data.imagesEnabled || this.imageRows().has(this.key)) return
    const files = selection.files
    if (files.length < 1 || files.length > 9 || files.some(f => !f.tempFilePath || !Number.isSafeInteger(f.size) || f.size <= 0 || f.size > MAX_BYTES)) {
      this.setData({ imageNotice: '一次可发送 1 至 9 张图片，每张不超过 3 MB。' })
      return
    }
    const row = {
      state: 'editing',
      client: '',
      items: files.map((file,index) => ({ path: file.tempFilePath, size: file.size, ordinal: index, state: '' }))
    }
    this.imageRows().set(this.key, row)
    this.showImageRow(row)
  },
  showImageRow(row, notice) {
    this.setData({
      imageItems: (row?.items || []).map(item => ({ ordinal: item.ordinal, path: item.path || '' })),
      imageState: row?.state || '',
      imageNotice: notice || states[row?.state] || ''
    })
  },
  async refreshImageState(serial, restore = false) {
    const id = this.data.conversation
    let row = this.imageRows().get(this.key)
    if (restore && !row && id) {
      const saved = this.imageLedger?.list().find(value => value.conversation_id === id)
      if (saved) {
        row = { client: saved.client_message_id, state: 'unknown', items: [], submitted: true }
        this.imageRows().set(this.key, row)
      }
    }
    if (restore) this.showImageRow(row)
    if (!id || !row?.submitted || this.imageQuery) return
    this.imageQuery = row
    try {
      const result = await this.http(serial,'media/status',{conversation_id:id,client_message_id:row.client})
      this.applyImageResult(serial,row,result)
    } catch (err) {
      if (this.active(serial) && err.code === 'CHAT_SEND_RESULT_UNKNOWN') this.showImageRow(row, '暂无发送记录，可继续查询或取消此操作。')
      else this.imageError(serial,err)
    } finally {
      if (this.imageQuery === row) this.imageQuery = null
    }
  },
  applyImageResult(serial, row, result) {
    if (!this.active(serial) || this.imageRows().get(this.key) !== row) return
    if (!result || result.client_message_id !== row.client || !Object.prototype.hasOwnProperty.call(states,result.state) && !isFinal(result.state)) {
      throw fail('CHAT_INVALID_RESPONSE')
    }
    if (!isFinal(result.state)) {
      row.state = result.state
      if (result.items?.length) {
        row.items = result.items.map(item => ({
          ...row.items[item.ordinal], ordinal: item.ordinal, attachment: item.attachment_id, state: item.state
        }))
      }
      this.showImageRow(row)
      return
    }
    this.imageRows().delete(this.key)
    this.imageLedger?.remove(row.client)
    this.showImageRow(null,({ accepted:'图片已发送。', rejected:'图片未发送，可重新选择。', canceled:'已取消发送图片。' })[result.state])
    if (result.state === 'accepted') {
      this.refresh()
      this.manager.refreshAccount()
    }
  },
  async sendImages() {
    const row = this.imageRows().get(this.key)
    if (!row || this.data.busy || !this.data.ready) return
    const serial = this.serial
    this.setData({ busy: true })
    try {
      if (!row.client) row.client = await this.manager.clientID()
      if (!this.active(serial)) return
      const id = await this.ensureConversation(serial,row.client)
      if (!this.active(serial)) return
      if (!id) { this.showImageRow(row,'正在准备会话，请稍后再次点击发送。'); return }
      const request = {conversation_id:id,client_message_id:row.client}
      if (row.submitted) {
        let result
        try { result = await this.http(serial,'media/status',request) }
        catch (err) { if (err.code !== 'CHAT_SEND_RESULT_UNKNOWN') throw err }
        if (result) {
          this.applyImageResult(serial,row,result)
          if (result.state === 'retryable' && this.data.imagesEnabled) {
            for (const item of result.items || []) {
              if (item.state === 'check_unavailable') {
                await this.http(serial,'media/uploaded',{conversation_id:id,attachment_id:item.attachment_id})
              }
            }
          }
          return
        }
        if (!row.request || !this.data.imagesEnabled) {
          this.showImageRow(row,'暂无发送记录，可取消此操作后重新选择。')
          return
        }
      }
      if (!this.data.imagesEnabled) throw fail('CHAT_SEND_RESTRICTED')
      if (!row.request) {
        for (const item of row.items) {
          if (item.ready) continue
          if (!item.uploadID) item.uploadID = await this.manager.clientID()
          if (!this.active(serial)) return
          const grant = await this.http(serial,'media/upload',{conversation_id:id,upload_id:item.uploadID,size_bytes:item.size})
          item.attachment = grant.attachment_id
          item.version = grant.object_version
          if (grant.state === 'blocked') throw fail('CHAT_CONTENT_BLOCKED')
          if (grant.state === 'uploading') {
            if (!/^https:\/\//.test(grant.host || '')) throw fail('CHAT_MEDIA_UNAVAILABLE')
            try { await this.manager.io.upload(grant,item.path,() => {},this.imageUploads) }
            catch (err) { if (err.statusCode !== 614) throw err }
          }
          if (!this.active(serial)) return
          await this.http(serial,'media/uploaded',{conversation_id:id,attachment_id:item.attachment})
          item.ready = true
        }
        row.request = { ...request, revision:1, message_type:row.items.length===1?'image':'image_album',
          items:row.items.map(item=>({attachment_id:item.attachment,object_version:item.version,ordinal:item.ordinal})) }
      }
      row.submitted = true
      row.state = 'unknown'
      this.imageLedger?.put(request)
      this.showImageRow(row)
      this.applyImageResult(serial,row,await this.http(serial,'messages/send',row.request))
    } catch (err) {
      this.imageError(serial,err)
    } finally {
      if (this.active(serial)) this.setData({ busy: false })
    }
  },
  async cancelImages() {
    if (this.data.busy) return
    const row = this.imageRows().get(this.key), serial = this.serial
    if (!row) return
    this.setData({ busy: true })
    try {
      if (row.submitted) {
        const result = await this.http(serial,'media/cancel',{conversation_id:this.data.conversation,client_message_id:row.client})
        this.applyImageResult(serial,row,result)
      } else {
        this.imageRows().delete(this.key)
        this.showImageRow(null,'已取消选择。')
      }
    } catch (err) { this.imageError(serial,err) }
    finally { if (this.active(serial)) this.setData({ busy: false }) }
  },
  imageError(serial, err) {
    if (!this.active(serial)) return
    if (['CHAT_UNAUTHORIZED','CHAT_FORBIDDEN','CHAT_PERMISSION_DENIED','CHAT_DIRECT_DISABLED'].includes(err.code)) {
      this.failure(serial,err)
      return
    }
    const copy = {
      CHAT_DIRECT_STRANGER_LIMIT:'已发送 3 条消息，等待对方回复后可继续发送。',
      CHAT_DIRECT_DAILY_LIMIT:'今日主动联系新联系人次数已用完，明日恢复。',
      CHAT_SEND_RESTRICTED:'当前暂不可继续发送图片。',
      CHAT_CONTENT_BLOCKED:'图片未通过审核。',
      CHAT_ACTION_EXPIRED:'此次发送已超时，请取消后重新选择图片。',
      CHAT_RECALL_WINDOW_EXPIRED:'这条图片消息已超过撤回时限。',
      CHAT_MEDIA_CLOUD_EXPIRED:'图片已超过云端保留时间。'
    }
    this.setData({imageNotice:copy[err.code] || '图片操作暂未完成，请稍后重试。'})
  },
  async sceneImageURL(serial,message,item,version) {
    let result=await this.http(serial,'media/url',{conversation_id:this.data.conversation,message_id:message,item_id:item,thumbnail:false})
    if(!result.cloud_available)result=await this.http(serial,'media/url',{conversation_id:this.data.conversation,message_id:message,item_id:item,thumbnail:true})
    if(!result.cloud_available)throw fail('CHAT_MEDIA_CLOUD_EXPIRED')
    if(!/^https:\/\//.test(result.url||'')||Number(result.expires_at)*1000<=Date.now()||String(result.state_version||'')!==String(version||''))throw fail('CHAT_INVALID_RESPONSE')
    return result.url
  },
  async openImage(event) {
    const serial = this.serial, { message, item } = event.currentTarget.dataset
    const row = this.data.messages.find(value=>String(value.message_id)===String(message))
    if (!row?.images.some(value=>String(value.item_id)===String(item))) return
    try {
      const resolved=await Promise.all(row.images.map(async photo=>{
        try{return{item:String(photo.item_id),url:await this.sceneImageURL(serial,message,photo.item_id,row.version)}}
        catch(_){return{item:String(photo.item_id),url:''}}
      }))
      if(!this.active(serial))return
      const urls=resolved.map(photo=>photo.url).filter(Boolean)
      const current=resolved.find(photo=>photo.item===String(item))?.url
      if(!current||!urls.length){wx.showToast({title:'图片暂不可查看',icon:'none'});return}
      wx.previewImage({urls,current})
    } catch (err) { this.imageError(serial,err) }
  },
  async recallImage(event) {
    const serial = this.serial, id = event.currentTarget.dataset.message
    if (this.data.busy || !this.data.messages.some(row=>row.message_id===id && row.canRecall && row.images.length)) return
    this.setData({busy:true})
    try {
      await this.http(serial,'messages/recall',{conversation_id:this.data.conversation,message_id:id})
      const messages = this.data.messages.map(row=>row.message_id===id ? {...row,images:[],canRecall:false,text:'图片已撤回'} : row)
      this.setData({messages})
      this.manager.refreshAccount()
    } catch (err) { this.imageError(serial,err) }
    finally { if (this.active(serial)) this.setData({busy:false}) }
  }
}

module.exports = { directImages }