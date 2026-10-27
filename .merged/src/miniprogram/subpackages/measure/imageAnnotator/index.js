// 2026-09-22：量房标注工具。对象化标注 + 轻量底部面板；独立使用只保存本地，测量单模式仅回传本地文件。
const { MAPCONFIG } = require('../../../config/config')
const chooseLocationPlugin = requirePlugin('chooseLocation')
const clone = value => JSON.parse(JSON.stringify(value))
const TYPE_LABEL = {
  dimension: '尺寸标注',
  text: '文字标注',
  arrow: '箭头',
  line: '直线',
  rect: '方框',
  ellipse: '圆圈',
  pen: '画笔',
  leader: '文字',
  multiDimension: '多段测量',
  area: '面积',
  localZoom: '局部放大',
  locationStamp: '时间与位置'
}

Page({
  data: {
    sourceReady: false,
    ready: false,
    returnMode: false,
    tool: 'dimension',
    toolGroup: 'dimension',
    styleType: 'dimension',
    tools: [
      { key: 'select', label: '选择', image: '/images/measure-icons/pointer.svg' },
      { key: 'dimension', label: '尺寸', image: '/images/measure-icons/size.svg' },
      { key: 'leader', label: '文字', icon: 'cuIcon-wenzi' },
      { key: 'pen', label: '画笔', icon: 'cuIcon-write' },
      { key: 'shape', label: '图形', icon: 'cuIcon-square' },
      { key: 'other', label: '其他', icon: 'cuIcon-more' }
    ],
    color: '#f44336',
    colors: ['#f44336', '#ff9800', '#119d62', '#1976d2', '#ffffff', '#222222'],
    stroke: 'normal',
    strokes: [
      { key: 'thin', label: '细' },
      { key: 'normal', label: '中' },
      { key: 'thick', label: '粗' }
    ],
    lineStyle: 'solid',
    dimensionEnd: 'arrow',
    textBackground: true,
    selectedId: '',
    selectedName: '',
    selectedCanEditText: false,
    layerPanel: false,
    layers: [],
    saving: false,
    canvasWidth: 320,
    canvasHeight: 360,
    canUndo: false,
    canRedo: false,
    inputSheetVisible: false,
    inputSheetMode: 'dimension',
    inputSheetTitle: '输入尺寸',
    inputPlaceholder: '例如 2.8m、260cm',
    inputValue: '',
    multiDimensionInputs: [],
    multiDimensionActiveIndex: 0,
    inputUnit: 'cm',
    inputUnits: ['mm', 'cm', 'm', '米'],
    quickDimensionValues: ['2.4m', '2.6m', '2.8m', '3m', '20cm', '30cm'],
    quickPhrases: ['门', '窗', '梁', '柜后', '不贴', '电视墙', '床头墙', '插座', '空调位', '测量'],
    continuousDimension: true,
    continuousTool: false,
    axisSnap: true,
    endpointSnap: true,
    showGuides: true,
    styleSheetVisible: false,
    toolPickerVisible: false,
    toolPickerGroup: '',
    shapeDockTool: null,
    otherDockTool: null,
    moreSheetVisible: false,
    viewScale: 1,
    viewTranslateX: 0,
    viewTranslateY: 0,
    viewScaleText: '100%',
    homeFolders: [],
    homeProjects: [],
    homeFolderId: '',
    folderSheetVisible: false,
    folderInputValue: '',
    projectSheetVisible: false,
    projectActionId: '',
    projectActionName: '',
    projectActionFolderId: '',
    folderManageVisible: false,
    folderActionId: '',
    folderActionName: '',
    settingsSheetVisible: false,
    imageMaxEdge: 2048,
    resolutionOptions: [
      { value: 1600, label: '省空间' },
      { value: 2048, label: '标准' },
      { value: 2560, label: '高清' }
    ],
    autoSaveAlbum: true,
    showMagnifier: true,
    magnifierVisible: false,
    storageUsageText: '—',
    storageProjectCount: 0,
    storageFileCount: 0,
    helpSheetVisible: false,
    multiMeasureActive: false,
    multiMeasurePointCount: 0
  },

  onLoad(options) {
    options = options || {}
    this.returnMode = String(options.return || '') === '1'
    this.projectEntryId = options.projectId ? decodeURIComponent(String(options.projectId)) : ''
    this.enteredFromProjectHome = !!this.projectEntryId
    this.initialSource = options.src ? decodeURIComponent(options.src) : ''
    this.shapes = []
    this.history = []
    this.redoHistory = []
    this.pageScrollTop = 0
    this.guideState = null
    this.resizeState = null
    this.panState = null
    this.viewGesture = null
    this.viewGestureConsumed = false
    this.currentProjectId = ''
    this.draftTimer = null
    this.multiDraft = null
    this.shapeDraft = null
    this.waitingMeasureStampLocation = false
    this.measureStampLocation = null
    this.locationStampBusy = false
    const settings = this.readEditorSettings()
    this.setData(Object.assign({ returnMode: this.returnMode }, settings))
    if (!this.returnMode && !this.projectEntryId) this.refreshLocalHome()
  },

  onHide() {
    this.saveCurrentProjectDraft()
  },

  onUnload() {
    if (this.draftTimer) clearTimeout(this.draftTimer)
    this.saveCurrentProjectDraft()
  },

  onReady() {
    if (this.projectEntryId) return this.openProjectById(this.projectEntryId)
    if (this.initialSource) this.loadSource(this.initialSource)
  },

  onShow() {
    if (!this.returnMode && !this.projectEntryId && !this.data.sourceReady) this.refreshLocalHome()
    if (!this.waitingMeasureStampLocation) return
    this.waitingMeasureStampLocation = false
    let location = null
    try {
      location = chooseLocationPlugin.getLocation()
    } catch (error) {
      console.warn('[量房标注] 读取腾讯地图选点失败', error)
    }
    if (!location || !location.latitude || !location.longitude) return
    this.measureStampLocation = location
    this.setDockGroupTool('other', 'locationStamp')
    this.setActiveTool('locationStamp', 'other')
    wx.showToast({ title: '已选择地址，轻点图片添加', icon: 'none' })
    if (typeof chooseLocationPlugin.setLocation === 'function') {
      try { chooseLocationPlugin.setLocation(null) } catch (error) {}
    }
  },

  onPageScroll(e) {
    this.pageScrollTop = Math.max(0, Number(e && e.scrollTop) || 0)
  },

  topLeftAction() {
    if (this.returnMode) return this.chooseSource()
    if (this.enteredFromProjectHome && getCurrentPages().length > 1) return wx.navigateBack()
    this.showProjectHome()
  },

  showProjectHome() {
    this.saveCurrentProjectDraft()
    this.active = null
    this.moveState = null
    this.resizeState = null
    this.panState = null
    this.viewGesture = null
    this.viewGestureConsumed = false
    this.guideState = null
    this.multiDraft = null
    this.shapeDraft = null
    this.waitingMeasureStampLocation = false
    this.measureStampLocation = null
    this.currentProjectId = ''
    this.projectEntryId = ''
    this.enteredFromProjectHome = false
    this.setData({
      sourceReady: false,
      ready: false,
      tool: 'dimension',
      toolGroup: 'dimension',
      styleType: 'dimension',
      selectedId: '',
      selectedName: '',
      selectedCanEditText: false,
      layerPanel: false,
      styleSheetVisible: false,
      toolPickerVisible: false,
      toolPickerGroup: '',
      shapeDockTool: null,
      otherDockTool: null,
      moreSheetVisible: false,
      settingsSheetVisible: false,
      helpSheetVisible: false,
      folderSheetVisible: false,
      folderManageVisible: false,
      projectSheetVisible: false,
      magnifierVisible: false,
      multiMeasureActive: false,
      multiMeasurePointCount: 0
    }, () => this.refreshLocalHome())
  },

  chooseSource() {
    this.chooseSourceFrom(['album', 'camera'])
  },

  chooseCamera() {
    this.chooseSourceFrom(['camera'])
  },

  chooseAlbum() {
    this.chooseSourceFrom(['album'])
  },

  chooseSourceFrom(sourceType) {
    if (this.data.saving) return
    const choose = () => wx.chooseMedia({
      count: 1,
      mediaType: ['image'],
      sourceType: sourceType || ['album', 'camera'],
      sizeType: ['original', 'compressed'],
      success: result => {
        const file = result.tempFiles && result.tempFiles[0]
        if (!file || !file.tempFilePath) return
        if (this.returnMode) return this.loadSource(file.tempFilePath)
        this.saveCurrentProjectDraft()
        this.persistProjectSource(file.tempFilePath).then(savedPath => {
          const project = this.createLocalProject(savedPath, false)
          if (!project) {
            if (typeof wx.removeSavedFile === 'function') wx.removeSavedFile({ filePath: savedPath, fail: () => {} })
            return
          }
          this.openProjectEditor(project.id)
        }).catch(error => {
          wx.showToast({ title: (error && error.message) || '本地图片保存失败', icon: 'none' })
        })
      }
    })
    if (this.shapes && this.shapes.length) {
      return wx.showModal({
        title: this.returnMode ? '更换图片？' : '新建标注项目？',
        content: this.returnMode ? '更换后当前未保存的标注会清空。' : '当前项目会先保存草稿，新图片将创建为另一个本地项目。',
        confirmColor: '#119d62',
        success: result => { if (result.confirm) choose() }
      })
    }
    choose()
  },

  persistProjectSource(tempFilePath) {
    return new Promise((resolve, reject) => {
      wx.saveFile({
        tempFilePath,
        success: result => result && result.savedFilePath ? resolve(result.savedFilePath) : reject(new Error('本地原图保存失败')),
        fail: error => {
          const message = String(error && error.errMsg || '')
          reject(new Error(message.indexOf('space') >= 0 ? '本地存储空间不足，请先清理旧项目' : '本地原图保存失败'))
        }
      })
    })
  },

  projectStorageKey() {
    return 'measure_annotator_projects_v1'
  },

  folderStorageKey() {
    return 'measure_annotator_folders_v1'
  },

  settingsStorageKey() {
    return 'measure_annotator_settings_v1'
  },

  readEditorSettings() {
    const defaults = {
      imageMaxEdge: 2048,
      autoSaveAlbum: true,
      continuousDimension: true,
      continuousTool: false,
      axisSnap: true,
      endpointSnap: true,
      showGuides: true,
      showMagnifier: true
    }
    try {
      const value = wx.getStorageSync(this.settingsStorageKey())
      if (!value || typeof value !== 'object') return defaults
      const edge = [1600, 2048, 2560].indexOf(Number(value.imageMaxEdge)) >= 0 ? Number(value.imageMaxEdge) : 2048
      return Object.assign({}, defaults, value, { imageMaxEdge: edge })
    } catch (error) {
      return defaults
    }
  },

  saveEditorSettings(patch) {
    const settings = Object.assign({}, this.readEditorSettings(), patch || {})
    try {
      wx.setStorageSync(this.settingsStorageKey(), settings)
    } catch (error) {}
  },

  readLocalProjects() {
    try {
      const value = wx.getStorageSync(this.projectStorageKey())
      return Array.isArray(value) ? value.filter(item => item && item.id) : []
    } catch (error) {
      return []
    }
  },

  writeLocalProjects(projects) {
    try {
      wx.setStorageSync(this.projectStorageKey(), projects || [])
      return true
    } catch (error) {
      wx.showToast({ title: '本地项目索引保存失败', icon: 'none' })
      return false
    }
  },

  readLocalFolders() {
    try {
      const value = wx.getStorageSync(this.folderStorageKey())
      return Array.isArray(value) ? value.filter(item => item && item.id && item.name) : []
    } catch (error) {
      return []
    }
  },

  writeLocalFolders(folders) {
    try {
      wx.setStorageSync(this.folderStorageKey(), folders || [])
      return true
    } catch (error) {
      wx.showToast({ title: '文件夹保存失败', icon: 'none' })
      return false
    }
  },

  createLocalProject(sourcePath, blankPaper) {
    const now = Date.now()
    const project = {
      version: 1,
      id: 'measure_project_' + now.toString(36) + '_' + Math.random().toString(36).slice(2, 7),
      name: blankPaper ? '空白量房草图' : ('量房标注 ' + this.formatProjectTime(now)),
      folderId: this.data.homeFolderId || '',
      sourcePath: sourcePath || '',
      blankPaper: !!blankPaper,
      shapes: [],
      outputWidth: blankPaper ? 1600 : 0,
      outputHeight: blankPaper ? 1200 : 0,
      createdAt: now,
      updatedAt: now
    }
    const projects = this.readLocalProjects()
    projects.unshift(project)
    if (!this.writeLocalProjects(projects)) return null
    this.refreshLocalHome()
    return project
  },

  formatProjectTime(timestamp) {
    const date = new Date(Number(timestamp) || Date.now())
    const pad = value => ('0' + String(value)).slice(-2)
    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes())
  },

  refreshLocalHome() {
    const folders = this.readLocalFolders()
    const allProjects = this.readLocalProjects().sort((a, b) => (Number(b.updatedAt) || 0) - (Number(a.updatedAt) || 0))
    const folderId = this.data.homeFolderId || ''
    const projects = allProjects.filter(item => !folderId || String(item.folderId || '') === String(folderId)).map(item => Object.assign({}, item, {
      updatedText: this.formatProjectTime(item.updatedAt || item.createdAt)
    }))
    this.setData({ homeFolders: folders, homeProjects: projects })
  },

  selectHomeFolder(e) {
    const folderId = e.currentTarget.dataset.id || ''
    this.setData({ homeFolderId: folderId }, () => this.refreshLocalHome())
  },

  openFolderSheet() {
    this.setData({ folderSheetVisible: true, folderInputValue: '' })
  },

  closeFolderSheet() {
    this.setData({ folderSheetVisible: false, folderInputValue: '' })
  },

  onFolderInput(e) {
    this.setData({ folderInputValue: String(e.detail && e.detail.value || '').slice(0, 24) })
  },

  confirmFolderCreate() {
    const name = String(this.data.folderInputValue || '').trim()
    if (!name) return wx.showToast({ title: '请输入文件夹名称', icon: 'none' })
    const folders = this.readLocalFolders()
    if (folders.some(item => item.name === name)) return wx.showToast({ title: '已有同名文件夹', icon: 'none' })
    folders.push({
      id: 'measure_folder_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 6),
      name,
      createdAt: Date.now()
    })
    if (!this.writeLocalFolders(folders)) return
    this.setData({ folderSheetVisible: false, folderInputValue: '' }, () => this.refreshLocalHome())
  },

  openFolderManage(e) {
    const id = e.currentTarget.dataset.id
    const folder = this.readLocalFolders().find(item => item.id === id)
    if (!folder) return
    this.setData({
      folderManageVisible: true,
      folderActionId: id,
      folderActionName: folder.name
    })
  },

  closeFolderManage() {
    this.setData({ folderManageVisible: false, folderActionId: '', folderActionName: '' })
  },

  onFolderActionInput(e) {
    this.setData({ folderActionName: String(e.detail && e.detail.value || '').slice(0, 24) })
  },

  renameFolder() {
    const id = this.data.folderActionId
    const name = String(this.data.folderActionName || '').trim()
    if (!id || !name) return wx.showToast({ title: '请输入文件夹名称', icon: 'none' })
    const folders = this.readLocalFolders()
    if (folders.some(item => item.id !== id && item.name === name)) return wx.showToast({ title: '已有同名文件夹', icon: 'none' })
    const folder = folders.find(item => item.id === id)
    if (!folder) return
    folder.name = name
    if (this.writeLocalFolders(folders)) {
      this.refreshLocalHome()
      wx.showToast({ title: '文件夹已重命名', icon: 'success' })
    }
  },

  deleteFolder() {
    const id = this.data.folderActionId
    if (!id) return
    const used = this.readLocalProjects().some(item => String(item.folderId || '') === String(id))
    if (used) return wx.showToast({ title: '请先移出文件夹内的项目', icon: 'none' })
    wx.showModal({
      title: '删除空文件夹？',
      content: '只删除文件夹，不会删除其他项目。',
      confirmText: '删除',
      confirmColor: '#d9524b',
      success: result => {
        if (!result.confirm) return
        const folders = this.readLocalFolders().filter(item => item.id !== id)
        if (!this.writeLocalFolders(folders)) return
        const nextFolder = this.data.homeFolderId === id ? '' : this.data.homeFolderId
        this.setData({ homeFolderId: nextFolder })
        this.closeFolderManage()
        this.refreshLocalHome()
      }
    })
  },

  openProjectEditor(projectId) {
    if (!projectId) return
    wx.navigateTo({
      url: '/subpackages/measure/imageAnnotator/index?projectId=' + encodeURIComponent(projectId),
      fail: error => {
        console.warn('[量房标注] 打开项目编辑页失败', error)
        wx.showToast({ title: '打开项目失败，请重试', icon: 'none' })
      }
    })
  },

  openProjectById(id) {
    const project = this.readLocalProjects().find(item => item.id === id)
    if (!project) {
      this.projectEntryId = ''
      this.enteredFromProjectHome = false
      this.currentProjectId = ''
      this.setData({ sourceReady: false, ready: false })
      this.refreshLocalHome()
      return wx.showToast({ title: '项目已不存在', icon: 'none' })
    }
    this.currentProjectId = project.id
    if (project.blankPaper) return this.loadBlankProject(project)
    if (!project.sourcePath) return wx.showToast({ title: '项目原图已不存在', icon: 'none' })
    this.loadSource(project.sourcePath, project)
  },

  createBlankPaper() {
    if (this.returnMode) return
    this.saveCurrentProjectDraft()
    const project = this.createLocalProject('', true)
    if (!project) return
    this.openProjectEditor(project.id)
  },

  openLocalProject(e) {
    const id = e.currentTarget.dataset.id
    const project = this.readLocalProjects().find(item => item.id === id)
    if (!project) return wx.showToast({ title: '项目已不存在', icon: 'none' })
    this.openProjectEditor(project.id)
  },

  openProjectSheet(e) {
    const id = e.currentTarget.dataset.id
    const project = this.readLocalProjects().find(item => item.id === id)
    if (!project) return
    this.setData({
      projectSheetVisible: true,
      projectActionId: id,
      projectActionName: project.name || '',
      projectActionFolderId: project.folderId || ''
    })
  },

  closeProjectSheet() {
    this.setData({ projectSheetVisible: false, projectActionId: '', projectActionName: '', projectActionFolderId: '' })
  },

  onProjectNameInput(e) {
    this.setData({ projectActionName: String(e.detail && e.detail.value || '').slice(0, 36) })
  },

  saveProjectName() {
    const id = this.data.projectActionId
    const name = String(this.data.projectActionName || '').trim()
    if (!id || !name) return wx.showToast({ title: '请输入项目名称', icon: 'none' })
    const projects = this.readLocalProjects()
    const project = projects.find(item => item.id === id)
    if (!project) return
    project.name = name
    project.updatedAt = Date.now()
    if (this.writeLocalProjects(projects)) {
      this.refreshLocalHome()
      wx.showToast({ title: '名称已保存', icon: 'success' })
    }
  },

  moveProjectToFolder(e) {
    const id = this.data.projectActionId
    const folderId = e.currentTarget.dataset.id || ''
    const projects = this.readLocalProjects()
    const project = projects.find(item => item.id === id)
    if (!project) return
    project.folderId = folderId
    project.updatedAt = Date.now()
    if (this.writeLocalProjects(projects)) {
      this.setData({ projectActionFolderId: folderId })
      this.refreshLocalHome()
    }
  },

  deleteLocalProject() {
    const id = this.data.projectActionId
    if (!id) return
    wx.showModal({
      title: '删除本地项目？',
      content: '会删除本地项目记录和保存的原图，不影响已经上传到工单或系统相册的图片。',
      confirmText: '删除',
      confirmColor: '#d9524b',
      success: result => {
        if (!result.confirm) return
        const projects = this.readLocalProjects()
        const target = projects.find(item => item.id === id)
        const next = projects.filter(item => item.id !== id)
        if (!this.writeLocalProjects(next)) return
        if (target && target.sourcePath && typeof wx.removeSavedFile === 'function') {
          wx.removeSavedFile({ filePath: target.sourcePath, fail: () => {} })
        }
        if (target && target.exportPath && typeof wx.removeSavedFile === 'function') {
          wx.removeSavedFile({ filePath: target.exportPath, fail: () => {} })
        }
        this.closeProjectSheet()
        this.refreshLocalHome()
      }
    })
  },

  scheduleDraftSave() {
    if (this.returnMode || !this.currentProjectId) return
    if (this.draftTimer) clearTimeout(this.draftTimer)
    this.draftTimer = setTimeout(() => {
      this.draftTimer = null
      this.saveCurrentProjectDraft()
    }, 900)
  },

  saveCurrentProjectDraft() {
    if (this.draftTimer) {
      clearTimeout(this.draftTimer)
      this.draftTimer = null
    }
    if (this.returnMode || !this.currentProjectId) return
    const projects = this.readLocalProjects()
    const project = projects.find(item => item.id === this.currentProjectId)
    if (!project) return
    project.shapes = clone(this.shapes || [])
    project.outputWidth = Number(this.outputWidth) || project.outputWidth || 0
    project.outputHeight = Number(this.outputHeight) || project.outputHeight || 0
    project.updatedAt = Date.now()
    this.writeLocalProjects(projects)
  },

  loadBlankProject(project) {
    const token = Date.now() + ':' + Math.random()
    this.loadToken = token
    this.active = null
    this.moveState = null
    this.resizeState = null
    this.guideState = null
    this.panState = null
    this.viewGesture = null
    this.viewGestureConsumed = false
    this.multiDraft = null
    this.shapeDraft = null
    this.image = null
    this.blankPaper = true
    this.source = ''
    this.shapes = clone((project && project.shapes) || [])
    this.history = []
    this.redoHistory = []
    this.outputWidth = Number(project && project.outputWidth) || 1600
    this.outputHeight = Number(project && project.outputHeight) || 1200

    const screen = wx.getSystemInfoSync()
    const maxWidth = Math.max(260, Math.min(screen.windowWidth - 24, 560))
    const maxHeight = Math.max(260, screen.windowHeight - 350)
    const displayScale = Math.min(maxWidth / this.outputWidth, maxHeight / this.outputHeight, 1)
    const displayWidth = Math.max(1, Math.round(this.outputWidth * displayScale))
    const displayHeight = Math.max(1, Math.round(this.outputHeight * displayScale))
    this.coordScaleX = this.outputWidth / displayWidth
    this.coordScaleY = this.outputHeight / displayHeight

    this.setData({
      ready: false,
      sourceReady: true,
      tool: 'dimension',
      toolGroup: 'dimension',
      styleType: 'dimension',
      selectedId: '',
      selectedName: '',
      selectedCanEditText: false,
      layers: [],
      canUndo: false,
      canRedo: false,
      layerPanel: false,
      styleSheetVisible: false,
      toolPickerVisible: false,
      toolPickerGroup: '',
      moreSheetVisible: false,
      settingsSheetVisible: false,
      helpSheetVisible: false,
      magnifierVisible: false,
      canvasWidth: displayWidth,
      canvasHeight: displayHeight,
      viewScale: 1,
      viewTranslateX: 0,
      viewTranslateY: 0,
      viewScaleText: '100%'
    }, () => {
      wx.createSelectorQuery().in(this).select('#imageAnnotatorCanvas').fields({ node: true, size: true, rect: true }).exec(result => {
        if (this.loadToken !== token) return
        const entry = result && result[0]
        if (!entry || !entry.node) return wx.showToast({ title: '画布初始化失败', icon: 'none' })
        this.canvas = entry.node
        this.canvasRect = {
          left: Number(entry.left) || 0,
          pageTop: (Number(entry.top) || 0) + (this.pageScrollTop || 0)
        }
        this.canvas.width = this.outputWidth
        this.canvas.height = this.outputHeight
        this.ctx = this.canvas.getContext('2d')
        this.initMagnifierCanvas()
        this.setData({ ready: true })
        this.syncState()
        this.render()
      })
    })
  },

  loadSource(source, project) {
    const token = Date.now() + ':' + Math.random()
    this.loadToken = token
    this.active = null
    this.moveState = null
    this.resizeState = null
    this.guideState = null
    this.panState = null
    this.viewGesture = null
    this.viewGestureConsumed = false
    this.multiDraft = null
    this.shapeDraft = null
    this.source = source
    this.blankPaper = false
    this.shapes = clone((project && project.shapes) || [])
    this.history = []
    this.redoHistory = []
    this.setData({
      ready: false,
      sourceReady: true,
      selectedId: '',
      selectedName: '',
      selectedCanEditText: false,
      layers: [],
      canUndo: false,
      canRedo: false,
      layerPanel: false,
      styleSheetVisible: false,
      toolPickerVisible: false,
      toolPickerGroup: '',
      moreSheetVisible: false,
      settingsSheetVisible: false,
      helpSheetVisible: false,
      magnifierVisible: false,
      viewScale: 1,
      viewTranslateX: 0,
      viewTranslateY: 0,
      viewScaleText: '100%',
      multiMeasureActive: false,
      multiMeasurePointCount: 0
    })
    wx.showLoading({ title: '正在打开图片', mask: true })
    wx.getImageInfo({
      src: source,
      success: info => {
        if (this.loadToken !== token) return
        this.source = info.path || source
        this.initCanvas(info, token, project)
      },
      fail: () => {
        if (this.loadToken !== token) return
        if (project && project.id === this.currentProjectId) this.currentProjectId = ''
        this.shapes = []
        this.setData({ sourceReady: false, ready: false })
        if (!this.returnMode) this.refreshLocalHome()
        wx.showToast({ title: '图片读取失败，可在设置中检查失效项目', icon: 'none' })
      },
      complete: () => wx.hideLoading()
    })
  },

  initCanvas(info, token, project) {
    const screen = wx.getSystemInfoSync()
    const maxWidth = Math.max(260, Math.min(screen.windowWidth - 24, 560))
    const maxHeight = Math.max(260, screen.windowHeight - 350)
    const displayScale = Math.min(maxWidth / info.width, maxHeight / info.height, 1.5)
    const displayWidth = Math.max(1, Math.round(info.width * displayScale))
    const displayHeight = Math.max(1, Math.round(info.height * displayScale))
    const projectWidth = Number(project && project.outputWidth) || 0
    const projectHeight = Number(project && project.outputHeight) || 0
    if (projectWidth > 0 && projectHeight > 0) {
      this.outputWidth = Math.max(1, Math.round(projectWidth))
      this.outputHeight = Math.max(1, Math.round(projectHeight))
    } else {
      const maxEdge = [1600, 2048, 2560].indexOf(Number(this.data.imageMaxEdge)) >= 0 ? Number(this.data.imageMaxEdge) : 2048
      const outputScale = Math.min(1, maxEdge / Math.max(info.width, info.height))
      this.outputWidth = Math.max(1, Math.round(info.width * outputScale))
      this.outputHeight = Math.max(1, Math.round(info.height * outputScale))
    }
    this.coordScaleX = this.outputWidth / displayWidth
    this.coordScaleY = this.outputHeight / displayHeight

    this.setData({
      canvasWidth: displayWidth,
      canvasHeight: displayHeight,
      viewScale: 1,
      viewTranslateX: 0,
      viewTranslateY: 0,
      viewScaleText: '100%'
    }, () => {
      wx.createSelectorQuery().in(this).select('#imageAnnotatorCanvas').fields({ node: true, size: true, rect: true }).exec(result => {
        if (this.loadToken !== token) return
        const entry = result && result[0]
        if (!entry || !entry.node) {
          this.setData({ ready: false })
          return wx.showToast({ title: '画布初始化失败', icon: 'none' })
        }
        this.canvas = entry.node
        this.canvasRect = {
          left: Number(entry.left) || 0,
          pageTop: (Number(entry.top) || 0) + (this.pageScrollTop || 0)
        }
        this.canvas.width = this.outputWidth
        this.canvas.height = this.outputHeight
        this.ctx = this.canvas.getContext('2d')
        this.initMagnifierCanvas()
        const image = this.canvas.createImage()
        image.onload = () => {
          if (this.loadToken !== token) return
          this.image = image
          this.setData({ ready: true })
          this.syncState()
          this.render()
        }
        image.onerror = () => {
          this.setData({ ready: false })
          wx.showToast({ title: '原图载入失败', icon: 'none' })
        }
        image.src = this.source
      })
    })
  },

  point(e) {
    const touch = (e.touches && e.touches[0]) || (e.changedTouches && e.changedTouches[0])
    if (!touch || !this.canvasRect) return null
    const clientX = Number(touch.clientX)
    const clientY = Number(touch.clientY)
    if (!Number.isFinite(clientX) || !Number.isFinite(clientY)) return null
    const top = this.canvasRect.pageTop - (this.pageScrollTop || 0)
    const scale = Math.max(1, Number(this.data.viewScale) || 1)
    const translateX = Number(this.data.viewTranslateX) || 0
    const translateY = Number(this.data.viewTranslateY) || 0
    return {
      x: Math.max(0, Math.min(this.outputWidth, ((clientX - this.canvasRect.left - translateX) / scale) * this.coordScaleX)),
      y: Math.max(0, Math.min(this.outputHeight, ((clientY - top - translateY) / scale) * this.coordScaleY))
    }
  },

  touchClient(e, index) {
    const touches = (e && e.touches) || []
    const touch = touches[index || 0] || ((e && e.changedTouches) || [])[index || 0]
    if (!touch) return null
    const x = Number(touch.clientX)
    const y = Number(touch.clientY)
    return Number.isFinite(x) && Number.isFinite(y) ? { x, y } : null
  },

  clampViewTranslation(x, y, scale) {
    const currentScale = Math.max(1, Math.min(5, Number(scale) || 1))
    const overflowX = Math.max(0, (Number(this.data.canvasWidth) || 0) * (currentScale - 1))
    const overflowY = Math.max(0, (Number(this.data.canvasHeight) || 0) * (currentScale - 1))
    return {
      x: Math.max(-overflowX, Math.min(0, Number(x) || 0)),
      y: Math.max(-overflowY, Math.min(0, Number(y) || 0))
    }
  },

  setViewTransform(scale, x, y) {
    const nextScale = Math.max(1, Math.min(5, Number(scale) || 1))
    const translate = this.clampViewTranslation(x, y, nextScale)
    this.setData({
      viewScale: Number(nextScale.toFixed(3)),
      viewTranslateX: Math.round(translate.x * 10) / 10,
      viewTranslateY: Math.round(translate.y * 10) / 10,
      viewScaleText: Math.round(nextScale * 100) + '%'
    })
  },

  zoomAround(nextScale, clientX, clientY) {
    if (!this.canvasRect) return
    const currentScale = Math.max(1, Number(this.data.viewScale) || 1)
    const x = Number(this.data.viewTranslateX) || 0
    const y = Number(this.data.viewTranslateY) || 0
    const top = this.canvasRect.pageTop - (this.pageScrollTop || 0)
    const focusX = Number.isFinite(clientX) ? clientX : this.canvasRect.left + (Number(this.data.canvasWidth) || 0) / 2
    const focusY = Number.isFinite(clientY) ? clientY : top + (Number(this.data.canvasHeight) || 0) / 2
    const localX = (focusX - this.canvasRect.left - x) / currentScale
    const localY = (focusY - top - y) / currentScale
    const targetScale = Math.max(1, Math.min(5, Number(nextScale) || 1))
    this.setViewTransform(
      targetScale,
      focusX - this.canvasRect.left - localX * targetScale,
      focusY - top - localY * targetScale
    )
  },

  zoomIn() {
    this.zoomAround((Number(this.data.viewScale) || 1) + 0.25)
  },

  zoomOut() {
    this.zoomAround((Number(this.data.viewScale) || 1) - 0.25)
  },

  resetView() {
    this.viewGesture = null
    this.panState = null
    this.setViewTransform(1, 0, 0)
  },

  initMagnifierCanvas() {
    wx.createSelectorQuery().in(this).select('#magnifierCanvas').fields({ node: true, size: true }).exec(result => {
      const entry = result && result[0]
      if (!entry || !entry.node) return
      this.magnifierCanvas = entry.node
      this.magnifierCanvas.width = 180
      this.magnifierCanvas.height = 180
      this.magnifierCtx = this.magnifierCanvas.getContext('2d')
    })
  },

  updateMagnifier(point) {
    if (!this.data.showMagnifier || !this.magnifierCtx || !this.canvas || !point) return
    const ctx = this.magnifierCtx
    const size = 180
    const sample = Math.max(70, Math.min(220, this.outputWidth / 9))
    const sx = Math.max(0, Math.min(this.outputWidth - sample, point.x - sample / 2))
    const sy = Math.max(0, Math.min(this.outputHeight - sample, point.y - sample / 2))
    try {
      ctx.clearRect(0, 0, size, size)
      ctx.save()
      ctx.beginPath()
      ctx.arc(size / 2, size / 2, size / 2 - 4, 0, Math.PI * 2)
      ctx.clip()
      ctx.drawImage(this.canvas, sx, sy, sample, sample, 0, 0, size, size)
      ctx.restore()
      ctx.save()
      ctx.strokeStyle = '#119d62'
      ctx.lineWidth = 3
      ctx.beginPath()
      ctx.arc(size / 2, size / 2, size / 2 - 5, 0, Math.PI * 2)
      ctx.stroke()
      ctx.strokeStyle = 'rgba(17,157,98,.78)'
      ctx.lineWidth = 2
      ctx.beginPath()
      ctx.moveTo(size / 2 - 14, size / 2)
      ctx.lineTo(size / 2 + 14, size / 2)
      ctx.moveTo(size / 2, size / 2 - 14)
      ctx.lineTo(size / 2, size / 2 + 14)
      ctx.stroke()
      ctx.restore()
      if (!this.data.magnifierVisible) this.setData({ magnifierVisible: true })
    } catch (error) {}
  },

  hideMagnifier() {
    if (this.data.magnifierVisible) this.setData({ magnifierVisible: false })
  },

  beginViewGesture(e) {
    const first = this.touchClient(e, 0)
    const second = this.touchClient(e, 1)
    if (!first || !second || !this.canvasRect) return false

    if (this.resizeState && this.resizeState.moved) this.shapes = JSON.parse(this.resizeState.before)
    if (this.moveState && this.moveState.moved) this.shapes = JSON.parse(this.moveState.before)
    this.active = null
    this.shapeDraft = null
    this.resizeState = null
    this.moveState = null
    this.panState = null
    this.guideState = null
    this.viewGestureConsumed = true

    const midX = (first.x + second.x) / 2
    const midY = (first.y + second.y) / 2
    const distance = Math.max(1, Math.hypot(second.x - first.x, second.y - first.y))
    const scale = Math.max(1, Number(this.data.viewScale) || 1)
    const translateX = Number(this.data.viewTranslateX) || 0
    const translateY = Number(this.data.viewTranslateY) || 0
    const top = this.canvasRect.pageTop - (this.pageScrollTop || 0)

    this.viewGesture = {
      distance,
      scale,
      localX: (midX - this.canvasRect.left - translateX) / scale,
      localY: (midY - top - translateY) / scale
    }
    this.render()
    return true
  },

  updateViewGesture(e) {
    if (!this.viewGesture) {
      if (!this.beginViewGesture(e)) return
    }
    const first = this.touchClient(e, 0)
    const second = this.touchClient(e, 1)
    if (!first || !second || !this.canvasRect) return
    const midX = (first.x + second.x) / 2
    const midY = (first.y + second.y) / 2
    const distance = Math.max(1, Math.hypot(second.x - first.x, second.y - first.y))
    const scale = Math.max(1, Math.min(5, this.viewGesture.scale * distance / this.viewGesture.distance))
    const top = this.canvasRect.pageTop - (this.pageScrollTop || 0)
    this.setViewTransform(
      scale,
      midX - this.canvasRect.left - this.viewGesture.localX * scale,
      midY - top - this.viewGesture.localY * scale
    )
  },

  panViewBy(dx, dy) {
    const scale = Math.max(1, Number(this.data.viewScale) || 1)
    if (scale <= 1) return
    this.setViewTransform(
      scale,
      (Number(this.data.viewTranslateX) || 0) + dx,
      (Number(this.data.viewTranslateY) || 0) + dy
    )
  },

  dockToolMeta(tool) {
    const meta = {
      arrow: { label: '箭头', icon: 'cuIcon-back_android' },
      line: { label: '直线', image: '/images/measure-icons/minus.svg' },
      rect: { label: '矩形', icon: 'cuIcon-square' },
      ellipse: { label: '圆 / 椭圆', icon: 'cuIcon-round' },
      area: { label: '面积', image: '/images/measure-icons/area.svg' },
      multiDimension: { label: '多段测量线', image: '/images/measure-icons/ruler.svg', rotate: true },
      localZoom: { label: '局部放大', icon: 'cuIcon-search' },
      locationStamp: { label: '时间与位置', icon: 'cuIcon-location' }
    }
    return meta[tool] ? Object.assign({ tool }, meta[tool]) : null
  },

  setDockGroupTool(group, tool) {
    const meta = this.dockToolMeta(tool)
    if (!meta) return
    if (group === 'shape') this.setData({ shapeDockTool: meta })
    if (group === 'other') this.setData({ otherDockTool: meta })
  },

  toolGroupFor(tool) {
    if (['arrow', 'line', 'rect', 'ellipse', 'area'].indexOf(tool) >= 0) return 'shape'
    if (['multiDimension', 'localZoom', 'locationStamp'].indexOf(tool) >= 0) return 'other'
    return tool
  },

  selectTool(e) {
    const key = e.currentTarget.dataset.tool
    if (!key) return
    if (key === 'shape' || key === 'other') {
      this.openToolPicker(key)
      return
    }
    if (this.multiDraft) this.cancelMultiMeasure(true)
    this.setActiveTool(key, key)
  },


  setActiveTool(tool, group) {
    this.shapeDraft = null
    const update = {
      tool,
      toolGroup: group || tool,
      styleType: tool,
      toolPickerVisible: false,
      toolPickerGroup: ''
    }
    if (tool !== 'select') {
      update.selectedId = ''
      update.selectedName = ''
      update.selectedCanEditText = false
    }
    this.setData(update)
    this.render()
  },


  isShapeDrawingTool(tool) {
    return ['arrow', 'line', 'rect', 'ellipse', 'area', 'localZoom'].indexOf(tool) >= 0
  },

  beginShapeDrawing(point) {
    const tool = this.data.tool
    if (!this.isShapeDrawingTool(tool) || !point) return false
    this.shapeDraft = {
      id: this.nextId(),
      tool,
      color: this.data.color,
      stroke: this.data.stroke,
      lineStyle: tool === 'area' ? 'dashed' : this.data.lineStyle,
      visible: true,
      start: { x: point.x, y: point.y },
      end: { x: point.x, y: point.y }
    }
    if (tool === 'area') {
      this.shapeDraft.points = [
        { x: point.x, y: point.y },
        { x: point.x, y: point.y },
        { x: point.x, y: point.y },
        { x: point.x, y: point.y }
      ]
    }
    if (tool === 'localZoom') this.shapeDraft.radius = this.localZoomRadius()
    this.render(this.shapeDraft)
    return true
  },


  updateShapeDrawing(point) {
    if (!this.shapeDraft || !point) return false
    const tool = this.shapeDraft.tool
    if (tool === 'area') {
      const a = this.shapeDraft.start
      this.shapeDraft.end = { x: point.x, y: point.y }
      this.shapeDraft.points = [
        { x: a.x, y: a.y },
        { x: point.x, y: a.y },
        { x: point.x, y: point.y },
        { x: a.x, y: point.y }
      ]
    } else {
      this.shapeDraft.end = (tool === 'line' || tool === 'arrow')
        ? this.snapPoint(this.shapeDraft.start, point, this.shapeDraft.id, tool)
        : { x: point.x, y: point.y }
    }
    this.render(this.shapeDraft)
    return true
  },


  finishShapeDrawing(point) {
    if (!this.shapeDraft) return false
    const shape = this.shapeDraft
    if (point) {
      if (shape.tool === 'area') {
        const a = shape.start
        shape.end = { x: point.x, y: point.y }
        shape.points = [
          { x: a.x, y: a.y },
          { x: point.x, y: a.y },
          { x: point.x, y: point.y },
          { x: a.x, y: point.y }
        ]
      } else {
        shape.end = (shape.tool === 'line' || shape.tool === 'arrow')
          ? this.snapPoint(shape.start, point, shape.id, shape.tool)
          : { x: point.x, y: point.y }
      }
    }
    this.shapeDraft = null
    this.guideState = null

    if (shape.tool === 'localZoom') {
      const minDistance = Math.max(28, this.outputWidth / 32)
      if (Math.hypot(shape.end.x - shape.start.x, shape.end.y - shape.start.y) < minDistance) {
        const radius = shape.radius || this.localZoomRadius()
        shape.end = {
          x: Math.max(radius + 4, Math.min(this.outputWidth - radius - 4, shape.start.x + radius * 1.75)),
          y: Math.max(radius + 4, Math.min(this.outputHeight - radius - 4, shape.start.y + radius * 1.05))
        }
      }
      this.commit(shape)
      return true
    }

    if (this.isTooShort(shape)) {
      this.render()
      return true
    }
    if (shape.tool === 'area') {
      this.ensureAreaPoints(shape)
      this.render(shape)
      this.askText(shape)
      return true
    }
    this.commit(shape)
    return true
  },


  cancelShapeDrawing() {
    if (!this.shapeDraft) return false
    this.shapeDraft = null
    this.guideState = null
    this.render()
    return true
  },

  localZoomRadius() {
    return Math.max(58, Math.min(138, Math.round(this.outputWidth / 9)))
  },

  selectColor(e) {
    this.setData({ color: e.currentTarget.dataset.color })
    this.applyStyleToSelected({ color: e.currentTarget.dataset.color })
  },

  selectStroke(e) {
    this.setData({ stroke: e.currentTarget.dataset.stroke })
    this.applyStyleToSelected({ stroke: e.currentTarget.dataset.stroke })
  },

  selectLineStyle(e) {
    const lineStyle = e.currentTarget.dataset.style
    this.setData({ lineStyle })
    this.applyStyleToSelected({ lineStyle })
  },

  selectDimensionEnd(e) {
    const dimensionEnd = e.currentTarget.dataset.end
    this.setData({ dimensionEnd })
    this.applyStyleToSelected({ dimensionEnd })
  },

  textBackgroundChange(e) {
    const textBackground = !!(e.detail && e.detail.value)
    this.setData({ textBackground })
    this.applyStyleToSelected({ textBackground })
  },

  applyStyleToSelected(patch) {
    const index = this.shapeIndex(this.data.selectedId)
    if (index < 0) return
    const shape = this.shapes[index]
    if (!shape || shape.tool === 'pen' && patch.textBackground !== undefined) return
    this.pushHistory()
    Object.assign(shape, patch)
    this.syncState()
    this.render()
  },

  touchStart(e) {
    if (!this.data.ready) return
    if (e.touches && e.touches.length >= 2) {
      this.beginViewGesture(e)
      return
    }
    if (this.viewGestureConsumed) return

    const point = this.point(e)
    if (!point) return
    this.guideState = null

    if (this.data.tool === 'locationStamp') {
      this.addLocationStamp(point)
      return
    }

    if (this.data.tool === 'multiDimension') {
      this.addMultiMeasurePoint(point)
      return
    }

    if (this.isShapeDrawingTool(this.data.tool)) {
      this.beginShapeDrawing(point)
      return
    }

    if (this.data.tool === 'select') {
      const hit = this.hitTest(point)
      if (!hit) {
        this.setData({ selectedId: '', selectedName: '', selectedCanEditText: false, styleType: 'select' })
        if ((Number(this.data.viewScale) || 1) > 1) {
          const client = this.touchClient(e)
          if (client) this.panState = { lastX: client.x, lastY: client.y }
        }
        this.render()
        return
      }
      this.setSelected(hit.id)
      const handle = this.controlHit(point, hit)
      if (handle) {
        this.resizeState = { id: hit.id, handle, moved: false, before: JSON.stringify(this.shapes) }
        return
      }
      this.moveState = { id: hit.id, last: point, moved: false, before: JSON.stringify(this.shapes) }
      return
    }

    this.active = {
      id: this.nextId(),
      tool: this.data.tool,
      color: this.data.color,
      stroke: this.data.stroke,
      lineStyle: this.data.lineStyle,
      dimensionEnd: this.data.dimensionEnd,
      textBackground: this.data.textBackground,
      visible: true,
      start: point,
      end: point,
      points: [point]
    }
  },


  touchMove(e) {
    if (e.touches && e.touches.length >= 2) {
      this.updateViewGesture(e)
      return
    }
    if (this.viewGestureConsumed) return

    if (this.panState) {
      const client = this.touchClient(e)
      if (!client) return
      const dx = client.x - this.panState.lastX
      const dy = client.y - this.panState.lastY
      this.panState.lastX = client.x
      this.panState.lastY = client.y
      this.panViewBy(dx, dy)
      return
    }

    const point = this.point(e)
    if (!point) return

    if (this.resizeState) {
      const index = this.shapeIndex(this.resizeState.id)
      if (index < 0) return
      const shape = this.shapes[index]

      if (this.resizeState.handle === 'label' && shape.tool === 'dimension') {
        const midpoint = this.dimensionMidpoint(shape)
        shape.labelOffset = { x: point.x - midpoint.x, y: point.y - midpoint.y }
        this.resizeState.moved = true
        this.render()
        this.updateMagnifier(point)
        return
      }

      if (shape.tool === 'multiDimension' && String(this.resizeState.handle).indexOf('point:') === 0) {
        const pointIndex = Number(String(this.resizeState.handle).split(':')[1])
        if (Number.isInteger(pointIndex) && shape.points && shape.points[pointIndex]) {
          const anchor = pointIndex > 0 ? shape.points[pointIndex - 1] : shape.points[pointIndex + 1]
          const snapped = this.snapPoint(anchor, point, shape.id, 'multiDimension')
          shape.points[pointIndex] = snapped
          shape.start = Object.assign({}, shape.points[0])
          shape.end = Object.assign({}, shape.points[shape.points.length - 1])
          this.resizeState.moved = true
          this.render()
          this.updateMagnifier(snapped)
        }
        return
      }

      if (shape.tool === 'area' && String(this.resizeState.handle).indexOf('areaPoint:') === 0) {
        const pointIndex = Number(String(this.resizeState.handle).split(':')[1])
        const points = this.ensureAreaPoints(shape)
        if (Number.isInteger(pointIndex) && points[pointIndex]) {
          points[pointIndex] = { x: point.x, y: point.y }
          shape.points = points
          this.syncAreaLegacyBounds(shape)
          this.resizeState.moved = true
          this.render()
          this.updateMagnifier(point)
        }
        return
      }

      const anchor = this.resizeState.handle === 'start' ? shape.end : shape.start
      const snapped = ['line', 'arrow', 'dimension', 'leader'].indexOf(shape.tool) >= 0
        ? this.snapPoint(anchor, point, shape.id, shape.tool)
        : { x: point.x, y: point.y }
      const target = this.resizeState.handle === 'start' ? shape.start : shape.end
      if (target && Math.abs(target.x - snapped.x) + Math.abs(target.y - snapped.y) > 0.5) {
        target.x = snapped.x
        target.y = snapped.y
        this.resizeState.moved = true
        this.render()
        this.updateMagnifier(snapped)
      }
      return
    }

    if (this.moveState) {
      const index = this.shapeIndex(this.moveState.id)
      if (index < 0) return
      const dx = point.x - this.moveState.last.x
      const dy = point.y - this.moveState.last.y
      if (Math.abs(dx) + Math.abs(dy) > 0.5) {
        this.translateShape(this.shapes[index], dx, dy)
        this.moveState.last = point
        this.moveState.moved = true
        this.render()
      }
      return
    }

    if (this.shapeDraft) {
      this.updateShapeDrawing(point)
      return
    }

    if (!this.active) return
    if (this.active.tool === 'pen') {
      this.active.end = point
      this.active.points.push(point)
    } else {
      this.active.end = this.snapPoint(this.active.start, point, this.active.id, this.active.tool)
    }
    this.render(this.active)
  },


  touchCancel() {
    this.hideMagnifier()
    if (this.viewGestureConsumed || this.viewGesture) {
      this.viewGesture = null
      this.viewGestureConsumed = false
      this.panState = null
      this.guideState = null
      this.shapeDraft = null
      this.render()
      return
    }
    if (this.panState) {
      this.panState = null
      return
    }

    this.guideState = null
    if (this.resizeState) {
      if (this.resizeState.moved) this.shapes = JSON.parse(this.resizeState.before)
      this.resizeState = null
      this.syncState()
      this.render()
      return
    }
    if (this.moveState) {
      if (this.moveState.moved) this.shapes = JSON.parse(this.moveState.before)
      this.moveState = null
      this.syncState()
      this.render()
      return
    }
    if (this.cancelShapeDrawing()) return
    if (!this.active) return
    this.active = null
    this.render()
  },


  touchEnd(e) {
    this.hideMagnifier()
    if (this.viewGestureConsumed || this.viewGesture) {
      if (!e.touches || e.touches.length < 2) this.viewGesture = null
      if (!e.touches || e.touches.length === 0) this.viewGestureConsumed = false
      this.guideState = null
      return
    }

    if (this.panState) {
      this.panState = null
      return
    }

    if (this.resizeState) {
      if (this.resizeState.moved) {
        this.history.push(this.resizeState.before)
        if (this.history.length > 40) this.history.shift()
        this.redoHistory = []
      }
      this.resizeState = null
      this.guideState = null
      this.syncState()
      this.render()
      return
    }

    if (this.moveState) {
      if (this.moveState.moved) {
        this.history.push(this.moveState.before)
        if (this.history.length > 40) this.history.shift()
        this.redoHistory = []
      }
      this.moveState = null
      this.guideState = null
      this.syncState()
      this.render()
      return
    }

    const point = this.point(e)
    if (this.shapeDraft) {
      this.finishShapeDrawing(point)
      return
    }

    if (!this.active) return
    if (point && this.active.tool !== 'pen') {
      this.active.end = this.snapPoint(this.active.start, point, this.active.id, this.active.tool)
    }
    const shape = this.active
    this.active = null
    this.guideState = null

    if (shape.tool === 'text') {
      this.render(shape)
      return this.askText(shape)
    }
    if (shape.tool === 'dimension') {
      if (this.isTooShort(shape)) return this.render()
      this.render(shape)
      return this.askText(shape)
    }
    if (shape.tool === 'leader') {
      if (this.isTooShort(shape)) return this.render()
      this.render(shape)
      return this.askText(shape)
    }
    if ((shape.tool === 'pen' && (shape.points || []).length < 2) || (shape.tool !== 'pen' && this.isTooShort(shape))) {
      this.render()
      return
    }
    this.commit(shape)
  },


  askText(shape) {
    const mode = shape.tool === 'dimension'
      ? 'dimension'
      : (shape.tool === 'multiDimension'
        ? 'multiDimension'
        : (shape.tool === 'area'
          ? 'area'
          : (shape.tool === 'leader' ? 'leader' : 'text')))
    const titles = {
      dimension: '输入尺寸',
      multiDimension: '输入各段尺寸',
      area: '输入面积',
      leader: '输入文字',
      text: '输入备注'
    }
    const placeholders = {
      dimension: '例如 2.8m、260cm',
      multiDimension: '逐段输入尺寸',
      area: '例如 12.6㎡',
      leader: '例如 柜后不贴、此处收口',
      text: '输入量房备注'
    }
    this.pendingInputShape = shape
    this.editingShapeId = ''
    if (mode === 'multiDimension') {
      const count = Math.max(1, (shape.points || []).length - 1)
      const existing = this.multiDimensionLabels(shape)
      const values = Array.from({ length: count }, (_, index) => existing[index] || '')
      this.setData({
        inputSheetVisible: true,
        inputSheetMode: mode,
        inputSheetTitle: titles[mode],
        inputPlaceholder: placeholders[mode],
        inputValue: '',
        multiDimensionInputs: values,
        multiDimensionActiveIndex: 0,
        inputUnit: this.data.inputUnit || 'cm'
      })
      return
    }
    this.setData({
      inputSheetVisible: true,
      inputSheetMode: mode,
      inputSheetTitle: titles[mode],
      inputPlaceholder: placeholders[mode],
      inputValue: shape.text || '',
      multiDimensionInputs: [],
      multiDimensionActiveIndex: 0,
      inputUnit: mode === 'dimension' ? (this.data.inputUnit || 'cm') : this.data.inputUnit
    })
  },


  canResizeShape(shape) {
    if (!shape) return false
    if (shape.tool === 'multiDimension') return !!(shape.points && shape.points.length >= 2)
    if (shape.tool === 'area') return this.ensureAreaPoints(shape).length === 4
    return !!(['dimension', 'arrow', 'line', 'rect', 'ellipse', 'leader', 'localZoom'].indexOf(shape.tool) >= 0 && shape.start && shape.end)
  },


  dimensionMidpoint(shape) {
    return {
      x: (((shape.start && shape.start.x) || 0) + ((shape.end && shape.end.x) || 0)) / 2,
      y: (((shape.start && shape.start.y) || 0) + ((shape.end && shape.end.y) || 0)) / 2
    }
  },

  dimensionLabelPoint(shape) {
    const midpoint = {
      x: (((shape.start && shape.start.x) || 0) + ((shape.end && shape.end.x) || 0)) / 2,
      y: (((shape.start && shape.start.y) || 0) + ((shape.end && shape.end.y) || 0)) / 2
    }
    const offset = shape.labelOffset || { x: 0, y: 0 }
    return { x: midpoint.x + (Number(offset.x) || 0), y: midpoint.y + (Number(offset.y) || 0) }
  },

  controlHit(point, shape) {
    if (!this.canResizeShape(shape)) return ''
    const tolerance = Math.max(26, this.outputWidth / 34)
    if (shape.tool === 'multiDimension' && shape.points) {
      for (let i = 0; i < shape.points.length; i++) {
        const current = shape.points[i]
        if (Math.hypot(point.x - current.x, point.y - current.y) <= tolerance) return 'point:' + i
      }
      return ''
    }
    if (shape.tool === 'area') {
      const points = this.ensureAreaPoints(shape)
      for (let i = 0; i < points.length; i++) {
        if (Math.hypot(point.x - points[i].x, point.y - points[i].y) <= tolerance) return 'areaPoint:' + i
      }
      return ''
    }
    if (Math.hypot(point.x - shape.start.x, point.y - shape.start.y) <= tolerance) return 'start'
    if (Math.hypot(point.x - shape.end.x, point.y - shape.end.y) <= tolerance) return 'end'
    if (shape.tool === 'dimension') {
      const label = this.dimensionLabelPoint(shape)
      if (Math.hypot(point.x - label.x, point.y - label.y) <= tolerance * 1.15) return 'label'
    }
    return ''
  },


  snapPoint(anchor, point, currentId, tool) {
    const next = { x: point.x, y: point.y }
    let guide = null
    const lineTool = ['dimension', 'arrow', 'line', 'leader', 'multiDimension'].indexOf(tool) >= 0

    if (this.data.axisSnap && lineTool && anchor) {
      const dx = next.x - anchor.x
      const dy = next.y - anchor.y
      const length = Math.hypot(dx, dy)
      if (length > Math.max(12, this.outputWidth / 160)) {
        const threshold = length * 0.09
        if (Math.abs(dy) <= threshold) {
          next.y = anchor.y
          guide = { type: 'horizontal', y: anchor.y }
        } else if (Math.abs(dx) <= threshold) {
          next.x = anchor.x
          guide = { type: 'vertical', x: anchor.x }
        }
      }
    }

    if (this.data.endpointSnap) {
      const radius = Math.max(18, this.outputWidth / 70)
      let nearest = null
      let nearestDistance = radius
      this.shapes.forEach(shape => {
        if (!shape || shape.id === currentId || shape.visible === false) return
        const candidates = []
        if (shape.start) candidates.push(shape.start)
        if (shape.end) candidates.push(shape.end)
        if (shape.points && shape.tool === 'multiDimension') candidates.push.apply(candidates, shape.points)
        candidates.forEach(candidate => {
          if (!candidate) return
          const distance = Math.hypot(next.x - candidate.x, next.y - candidate.y)
          if (distance < nearestDistance) {
            nearestDistance = distance
            nearest = candidate
          }
        })
      })
      if (nearest) {
        next.x = nearest.x
        next.y = nearest.y
        guide = { type: 'endpoint', x: nearest.x, y: nearest.y }
      }
    }

    const guideKey = guide ? [guide.type, Math.round(guide.x || 0), Math.round(guide.y || 0)].join(':') : ''
    const previousKey = this.guideState && this.guideState.key || ''
    this.guideState = guide && this.data.showGuides ? Object.assign({ key: guideKey }, guide) : null
    if (guideKey && guideKey !== previousKey && typeof wx.vibrateShort === 'function') {
      wx.vibrateShort({ type: 'light', fail: () => {} })
    }
    return next
  },



  addMultiMeasurePoint(point) {
    if (!point) return
    if (!this.multiDraft) {
      const first = this.snapPoint(null, point, '', 'multiDimension')
      this.multiDraft = {
        id: this.nextId(),
        tool: 'multiDimension',
        color: this.data.color,
        stroke: this.data.stroke,
        lineStyle: this.data.lineStyle,
        dimensionEnd: this.data.dimensionEnd,
        textBackground: true,
        visible: true,
        start: Object.assign({}, first),
        end: Object.assign({}, first),
        points: [Object.assign({}, first)],
        segmentTexts: [],
        text: ''
      }
      this.setData({ multiMeasureActive: true, multiMeasurePointCount: 1 })
      this.render(this.multiDraft)
      return
    }
    const points = this.multiDraft.points || []
    const last = points[points.length - 1]
    const snapped = this.snapPoint(last, point, this.multiDraft.id, 'multiDimension')
    if (last && Math.hypot(snapped.x - last.x, snapped.y - last.y) < Math.max(10, this.outputWidth / 180)) return
    points.push(Object.assign({}, snapped))
    this.multiDraft.points = points
    this.multiDraft.segmentTexts = Array.from({ length: Math.max(0, points.length - 1) }, (_, i) => (this.multiDraft.segmentTexts || [])[i] || '')
    this.multiDraft.start = Object.assign({}, points[0])
    this.multiDraft.end = Object.assign({}, points[points.length - 1])
    this.setData({ multiMeasureActive: true, multiMeasurePointCount: points.length })
    this.render(this.multiDraft)
  },


  undoMultiMeasurePoint() {
    if (!this.multiDraft || !this.multiDraft.points || !this.multiDraft.points.length) return
    this.multiDraft.points.pop()
    if (!this.multiDraft.points.length) return this.cancelMultiMeasure()
    this.multiDraft.segmentTexts = (this.multiDraft.segmentTexts || []).slice(0, Math.max(0, this.multiDraft.points.length - 1))
    this.multiDraft.start = Object.assign({}, this.multiDraft.points[0])
    this.multiDraft.end = Object.assign({}, this.multiDraft.points[this.multiDraft.points.length - 1])
    this.setData({ multiMeasurePointCount: this.multiDraft.points.length })
    this.render(this.multiDraft)
  },


  finishMultiMeasure() {
    if (!this.multiDraft || !this.multiDraft.points || this.multiDraft.points.length < 2) {
      wx.showToast({ title: '至少标两个点', icon: 'none' })
      return
    }
    const shape = this.multiDraft
    shape.segmentTexts = Array.from({ length: shape.points.length - 1 }, (_, i) => (shape.segmentTexts || [])[i] || '')
    this.multiDraft = null
    this.guideState = null
    this.setData({ multiMeasureActive: false, multiMeasurePointCount: 0 })
    this.render(shape)
    this.askText(shape)
  },


  cancelMultiMeasure(silent) {
    this.multiDraft = null
    this.guideState = null
    this.setData({ multiMeasureActive: false, multiMeasurePointCount: 0 })
    this.render()
    if (!silent) wx.showToast({ title: '已取消多段测量', icon: 'none' })
  },

  normalizeMultiDimensionText(value) {
    const unit = this.data.inputUnit || 'cm'
    return String(value || '')
      .split(/[\/、,，\n]+/)
      .map(item => String(item || '').trim())
      .filter(Boolean)
      .map(item => /^-?\d+(?:\.\d+)?$/.test(item) ? item + unit : item)
      .join(' / ')
  },

  normalizeAreaText(value) {
    const text = String(value || '').trim()
    if (!text) return ''
    return /^-?\d+(?:\.\d+)?$/.test(text) ? text + '㎡' : text
  },

  formatLocationStampTime() {
    const date = new Date()
    const pad = value => ('0' + String(value)).slice(-2)
    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes())
  },

  addLocationStamp(point) {
    if (this.locationStampBusy) return
    const location = this.measureStampLocation
    const address = this.formatMeasureStampAddress(location)
    if (!location || !address) {
      this.chooseMeasureStampLocation()
      return
    }

    this.locationStampBusy = true
    const shape = {
      id: this.nextId(),
      tool: 'locationStamp',
      color: this.data.color,
      stroke: this.data.stroke,
      lineStyle: 'solid',
      visible: true,
      start: Object.assign({}, point),
      end: Object.assign({}, point),
      timeText: '时间：' + this.formatLocationStampTime(),
      locationText: '位置：' + address,
      location: {
        name: location.name || '',
        address: location.address || '',
        province: location.province || '',
        city: location.city || '',
        district: location.district || '',
        latitude: Number(location.latitude) || 0,
        longitude: Number(location.longitude) || 0
      },
      text: ''
    }
    shape.text = shape.timeText + ' · ' + shape.locationText
    this.locationStampBusy = false
    this.commit(shape)
  },

  isTooShort(shape) {
    const dx = shape.end.x - shape.start.x
    const dy = shape.end.y - shape.start.y
    return Math.sqrt(dx * dx + dy * dy) < Math.max(8, this.outputWidth / 180)
  },

  nextId() {
    this.idSeed = (this.idSeed || 0) + 1
    return 'mark_' + Date.now().toString(36) + '_' + this.idSeed
  },

  commit(shape) {
    this.pushHistory()
    this.shapes.push(shape)
    // 尺寸保留独立开关；“连续添加工具”只控制其他操作，避免两个设置互相覆盖。
    const keepDimension = shape.tool === 'dimension' && this.data.continuousDimension
    const keepTool = shape.tool !== 'dimension' && shape.tool !== 'select' && this.data.continuousTool
    if (keepDimension || keepTool) {
      const group = this.toolGroupFor(shape.tool)
      if (group === 'shape' || group === 'other') this.setDockGroupTool(group, shape.tool)
      this.setData({
        tool: shape.tool,
        toolGroup: group,
        styleType: shape.tool,
        selectedId: '',
        selectedName: '',
        selectedCanEditText: false
      })
    } else {
      this.setData({ tool: 'select', toolGroup: 'select', styleType: 'select' })
      this.setSelected(shape.id)
    }
    this.syncState()
    this.render()
  },


  pushHistory() {
    this.history.push(JSON.stringify(this.shapes))
    if (this.history.length > 40) this.history.shift()
    this.redoHistory = []
  },

  undo() {
    if (!this.history.length) return
    this.redoHistory.push(JSON.stringify(this.shapes))
    this.shapes = JSON.parse(this.history.pop())
    this.ensureSelection()
    this.syncState()
    this.render()
  },

  redo() {
    if (!this.redoHistory.length) return
    this.history.push(JSON.stringify(this.shapes))
    this.shapes = JSON.parse(this.redoHistory.pop())
    this.ensureSelection()
    this.syncState()
    this.render()
  },

  clear() {
    if (!this.shapes.length) return
    wx.showModal({
      title: '清除全部标注？',
      content: '只清除标注，不会删除原图。',
      confirmColor: '#119d62',
      success: result => {
        if (!result.confirm) return
        this.pushHistory()
        this.shapes = []
        this.setData({ selectedId: '', selectedName: '', selectedCanEditText: false, styleType: this.data.tool })
        this.syncState()
        this.render()
      }
    })
  },

  openLayers() {
    this.syncState()
    this.setData({ layerPanel: true })
  },

  closeLayers() {
    this.setData({ layerPanel: false })
  },

  setSelected(id) {
    const shape = this.shapes[this.shapeIndex(id)]
    const textTools = ['dimension', 'text', 'leader', 'multiDimension', 'area']
    this.setData({
      selectedId: shape ? id : '',
      selectedName: shape ? this.layerName(shape) : '',
      selectedCanEditText: !!(shape && textTools.indexOf(shape.tool) >= 0),
      styleType: shape ? shape.tool : this.data.tool
    })
    if (shape) {
      this.setData({
        color: shape.color || this.data.color,
        stroke: shape.stroke || this.data.stroke,
        lineStyle: shape.lineStyle || 'solid',
        dimensionEnd: shape.dimensionEnd || 'arrow',
        textBackground: shape.textBackground !== false
      })
    }
  },


  selectLayer(e) {
    const id = e.currentTarget.dataset.id
    this.setSelected(id)
    this.setData({ layerPanel: false, tool: 'select' })
    this.render()
  },

  toggleLayer(e) {
    const index = this.shapeIndex(e.currentTarget.dataset.id)
    if (index < 0) return
    this.pushHistory()
    this.shapes[index].visible = this.shapes[index].visible === false
    if (this.shapes[index].visible === false && this.data.selectedId === this.shapes[index].id) {
      this.setData({ selectedId: '', selectedName: '', selectedCanEditText: false, styleType: this.data.tool })
    }
    this.syncState()
    this.render()
  },

  deleteLayer(e) {
    const id = e.currentTarget.dataset.id || this.data.selectedId
    const index = this.shapeIndex(id)
    if (index < 0) return
    this.pushHistory()
    this.shapes.splice(index, 1)
    if (this.data.selectedId === id) this.setData({ selectedId: '', selectedName: '', selectedCanEditText: false, styleType: this.data.tool })
    this.syncState()
    this.render()
  },

  duplicateLayer(e) {
    const id = e.currentTarget.dataset.id || this.data.selectedId
    const index = this.shapeIndex(id)
    if (index < 0) return
    this.pushHistory()
    const copy = clone(this.shapes[index])
    copy.id = this.nextId()
    this.translateShape(copy, Math.max(12, this.outputWidth / 35), Math.max(12, this.outputWidth / 35))
    this.shapes.splice(index + 1, 0, copy)
    this.setSelected(copy.id)
    this.syncState()
    this.render()
  },

  moveLayer(e) {
    const id = e.currentTarget.dataset.id
    const direction = e.currentTarget.dataset.direction
    const index = this.shapeIndex(id)
    if (index < 0) return
    const next = direction === 'up' ? index + 1 : index - 1
    if (next < 0 || next >= this.shapes.length) return
    this.pushHistory()
    const item = this.shapes.splice(index, 1)[0]
    this.shapes.splice(next, 0, item)
    this.syncState()
    this.render()
  },

  editSelectedText() {
    const index = this.shapeIndex(this.data.selectedId)
    if (index < 0) return
    const shape = this.shapes[index]
    if (['text', 'dimension', 'leader', 'multiDimension', 'area'].indexOf(shape.tool) < 0) return
    const mode = shape.tool === 'dimension'
      ? 'dimension'
      : (shape.tool === 'multiDimension'
        ? 'multiDimension'
        : (shape.tool === 'area'
          ? 'area'
          : (shape.tool === 'leader' ? 'leader' : 'text')))
    const titles = { dimension: '修改尺寸', multiDimension: '修改各段尺寸', area: '修改面积', leader: '修改文字', text: '修改文字' }
    const placeholders = { dimension: '例如 2.8m、260cm', multiDimension: '逐段修改尺寸', area: '例如 12.6㎡', leader: '输入文字说明', text: '输入量房备注' }
    this.pendingInputShape = null
    this.editingShapeId = shape.id
    if (mode === 'multiDimension') {
      const count = Math.max(1, (shape.points || []).length - 1)
      const existing = this.multiDimensionLabels(shape)
      const values = Array.from({ length: count }, (_, i) => existing[i] || '')
      this.setData({
        inputSheetVisible: true,
        inputSheetMode: mode,
        inputSheetTitle: titles[mode],
        inputPlaceholder: placeholders[mode],
        inputValue: '',
        multiDimensionInputs: values,
        multiDimensionActiveIndex: 0
      })
      return
    }
    this.setData({
      inputSheetVisible: true,
      inputSheetMode: mode,
      inputSheetTitle: titles[mode],
      inputPlaceholder: placeholders[mode],
      inputValue: shape.text || '',
      multiDimensionInputs: []
    })
  },


  onInputValue(e) {
    this.setData({ inputValue: String(e.detail && e.detail.value || '').slice(0, 80) })
  },

  onMultiDimensionInput(e) {
    const index = Number(e.currentTarget.dataset.index)
    if (!Number.isInteger(index) || index < 0) return
    const values = (this.data.multiDimensionInputs || []).slice()
    values[index] = String(e.detail && e.detail.value || '').slice(0, 40)
    this.setData({ multiDimensionInputs: values, multiDimensionActiveIndex: index })
  },

  onMultiDimensionFocus(e) {
    const index = Number(e.currentTarget.dataset.index)
    if (Number.isInteger(index) && index >= 0) this.setData({ multiDimensionActiveIndex: index })
  },

  normalizeMultiDimensionValues(values) {
    return (values || []).map(value => this.normalizeDimensionText(String(value || '').trim()))
  },

  multiDimensionLabels(shape) {
    if (shape && Array.isArray(shape.segmentTexts) && shape.segmentTexts.length) return shape.segmentTexts.slice()
    return String(shape && shape.text || '').split(/\s*\/\s*/).map(item => item.trim()).filter(Boolean)
  },

  selectInputUnit(e) {
    this.setData({ inputUnit: e.currentTarget.dataset.unit || 'cm' })
  },

  applyQuickDimension(e) {
    const value = e.currentTarget.dataset.value || ''
    if (this.data.inputSheetMode === 'multiDimension') {
      const values = (this.data.multiDimensionInputs || []).slice()
      const index = Math.max(0, Math.min(values.length - 1, Number(this.data.multiDimensionActiveIndex) || 0))
      if (values.length) {
        values[index] = value
        this.setData({ multiDimensionInputs: values })
      }
      return
    }
    this.setData({ inputValue: value })
  },


  applyQuickPhrase(e) {
    const phrase = e.currentTarget.dataset.value || ''
    const current = String(this.data.inputValue || '').trim()
    this.setData({ inputValue: current ? (current + ' ' + phrase).slice(0, 80) : phrase })
  },

  toggleContinuousDimension(e) {
    const continuousDimension = !!(e.detail && e.detail.value)
    this.setData({ continuousDimension })
    this.saveEditorSettings({ continuousDimension })
  },

  toggleContinuousTool(e) {
    const continuousTool = !!(e.detail && e.detail.value)
    this.setData({ continuousTool })
    this.saveEditorSettings({ continuousTool })
  },

  cancelInputSheet() {
    this.pendingInputShape = null
    this.editingShapeId = ''
    this.setData({ inputSheetVisible: false, inputValue: '', multiDimensionInputs: [], multiDimensionActiveIndex: 0 })
    this.render()
  },


  normalizeDimensionText(value) {
    const text = String(value || '').trim()
    if (!text) return ''
    if (/^-?\d+(?:\.\d+)?$/.test(text)) return text + (this.data.inputUnit || 'cm')
    return text
  },

  confirmInputSheet() {
    if (this.data.inputSheetMode === 'multiDimension') {
      const values = this.normalizeMultiDimensionValues(this.data.multiDimensionInputs || [])
      if (!values.length || values.some(value => !value)) {
        wx.showToast({ title: '请填写每一段尺寸', icon: 'none' })
        return
      }
      const content = values.join(' / ')
      if (this.editingShapeId) {
        const index = this.shapeIndex(this.editingShapeId)
        if (index >= 0) {
          this.pushHistory()
          this.shapes[index].segmentTexts = values
          this.shapes[index].text = content
          this.setData({ inputSheetVisible: false, inputValue: '', multiDimensionInputs: [] })
          this.editingShapeId = ''
          this.pendingInputShape = null
          this.syncState()
          this.render()
        }
        return
      }
      const shape = this.pendingInputShape
      if (!shape) {
        this.setData({ inputSheetVisible: false, inputValue: '', multiDimensionInputs: [] })
        return
      }
      shape.segmentTexts = values
      shape.text = content
      this.pendingInputShape = null
      this.setData({ inputSheetVisible: false, inputValue: '', multiDimensionInputs: [] })
      this.commit(shape)
      return
    }

    let content = String(this.data.inputValue || '').trim()
    if (this.data.inputSheetMode === 'dimension') content = this.normalizeDimensionText(content)
    if (this.data.inputSheetMode === 'area') content = this.normalizeAreaText(content)
    if (!content) {
      const title = this.data.inputSheetMode === 'area'
        ? '请输入面积'
        : (this.data.inputSheetMode === 'dimension' ? '请输入尺寸' : '请输入文字')
      wx.showToast({ title, icon: 'none' })
      return
    }

    if (this.editingShapeId) {
      const index = this.shapeIndex(this.editingShapeId)
      if (index >= 0) {
        this.pushHistory()
        this.shapes[index].text = content.slice(0, 120)
        this.setData({ inputSheetVisible: false, inputValue: '', multiDimensionInputs: [] })
        this.editingShapeId = ''
        this.pendingInputShape = null
        this.syncState()
        this.render()
      }
      return
    }

    const shape = this.pendingInputShape
    if (!shape) {
      this.setData({ inputSheetVisible: false, inputValue: '', multiDimensionInputs: [] })
      return
    }
    shape.text = content.slice(0, 120)
    this.pendingInputShape = null
    this.setData({ inputSheetVisible: false, inputValue: '', multiDimensionInputs: [] })
    this.commit(shape)
  },


  openStyleSheet() {
    if (!this.data.selectedId) return
    this.setData({ styleSheetVisible: true })
  },

  closeStyleSheet() {
    this.setData({ styleSheetVisible: false })
  },

  openToolPicker(group) {
    if (group !== 'shape' && group !== 'other') return
    this.shapeDraft = null
    this.setData({
      toolPickerVisible: true,
      toolPickerGroup: group
    })
  },

  closeToolPicker() {
    this.setData({
      toolPickerVisible: false,
      toolPickerGroup: ''
    })
  },

  selectSubTool(e) {
    const tool = e.currentTarget.dataset.tool
    const group = this.data.toolPickerGroup || e.currentTarget.dataset.group || ''
    const allowed = {
      shape: ['arrow', 'line', 'rect', 'ellipse', 'area'],
      other: ['multiDimension', 'localZoom', 'locationStamp']
    }
    if (!allowed[group] || allowed[group].indexOf(tool) < 0) return

    if (this.multiDraft && tool !== 'multiDimension') this.cancelMultiMeasure(true)
    if (tool === 'locationStamp') {
      this.closeToolPicker()
      this.chooseMeasureStampLocation()
      return
    }
    this.setDockGroupTool(group, tool)
    this.setActiveTool(tool, group)
  },


  chooseMeasureStampLocation() {
    if (this.waitingMeasureStampLocation) return
    this.waitingMeasureStampLocation = true
    const location = this.measureStampLocation
    const latitude = location ? Number(location.latitude) : NaN
    const longitude = location ? Number(location.longitude) : NaN
    const current = location && Number.isFinite(latitude) && Number.isFinite(longitude)
      ? '&location=' + JSON.stringify({ latitude, longitude })
      : ''
    wx.navigateTo({
      url: 'plugin://chooseLocation/index?key=' + encodeURIComponent(MAPCONFIG.MAPKEY) +
        '&referer=' + encodeURIComponent('壁选师傅') + current,
      fail: error => {
        this.waitingMeasureStampLocation = false
        wx.showModal({
          title: '地图暂不可用',
          content: '腾讯地图选点未打开，请稍后重试。\n' + String(error && error.errMsg || ''),
          showCancel: false
        })
      }
    })
  },

  formatMeasureStampAddress(location) {
    if (!location) return ''
    const address = String(location.address || '').trim()
    const name = String(location.name || '').trim()
    const district = String(location.district || '').trim()
    if (address && name && address.indexOf(name) < 0) return address + ' ' + name
    if (address) return address
    if (district && name) return district + name
    return name || district || ''
  },

  openMore() {
    this.setData({ moreSheetVisible: true })
  },

  closeMore() {
    this.setData({ moreSheetVisible: false })
  },

  axisSnapChange(e) {
    const axisSnap = !!(e.detail && e.detail.value)
    this.setData({ axisSnap })
    this.saveEditorSettings({ axisSnap })
  },

  endpointSnapChange(e) {
    const endpointSnap = !!(e.detail && e.detail.value)
    this.setData({ endpointSnap })
    this.saveEditorSettings({ endpointSnap })
  },

  showGuidesChange(e) {
    const showGuides = !!(e.detail && e.detail.value)
    this.guideState = null
    this.setData({ showGuides })
    this.saveEditorSettings({ showGuides })
    this.render()
  },

  openSettings() {
    this.setData({ moreSheetVisible: false, settingsSheetVisible: true })
    this.refreshStorageStats()
  },

  closeSettings() {
    this.setData({ settingsSheetVisible: false })
  },

  openHelp() {
    this.setData({ settingsSheetVisible: false, helpSheetVisible: true })
  },

  closeHelp() {
    this.setData({ helpSheetVisible: false })
  },

  selectResolution(e) {
    const imageMaxEdge = Number(e.currentTarget.dataset.value)
    if ([1600, 2048, 2560].indexOf(imageMaxEdge) < 0) return
    this.setData({ imageMaxEdge })
    this.saveEditorSettings({ imageMaxEdge })
  },

  autoSaveAlbumChange(e) {
    const autoSaveAlbum = !!(e.detail && e.detail.value)
    this.setData({ autoSaveAlbum })
    this.saveEditorSettings({ autoSaveAlbum })
  },

  showMagnifierChange(e) {
    const showMagnifier = !!(e.detail && e.detail.value)
    this.setData({ showMagnifier, magnifierVisible: false })
    this.saveEditorSettings({ showMagnifier })
  },

  formatBytes(bytes) {
    const size = Math.max(0, Number(bytes) || 0)
    if (size < 1024) return size + ' B'
    if (size < 1024 * 1024) return (size / 1024).toFixed(size < 10 * 1024 ? 1 : 0) + ' KB'
    return (size / 1024 / 1024).toFixed(size < 10 * 1024 * 1024 ? 1 : 0) + ' MB'
  },

  refreshStorageStats() {
    const projects = this.readLocalProjects()
    const refs = new Set()
    projects.forEach(project => {
      if (project.sourcePath) refs.add(project.sourcePath)
      if (project.exportPath) refs.add(project.exportPath)
    })
    this.setData({ storageProjectCount: projects.length })
    if (typeof wx.getSavedFileList !== 'function') {
      this.setData({ storageUsageText: '当前环境不可统计', storageFileCount: refs.size })
      return
    }
    wx.getSavedFileList({
      success: result => {
        let bytes = 0
        let count = 0
        ;(result.fileList || []).forEach(file => {
          if (!refs.has(file.filePath)) return
          bytes += Number(file.size) || 0
          count += 1
        })
        this.setData({ storageUsageText: this.formatBytes(bytes), storageFileCount: count })
      },
      fail: () => this.setData({ storageUsageText: '统计失败', storageFileCount: refs.size })
    })
  },

  cleanBrokenProjects() {
    if (typeof wx.getSavedFileList !== 'function') {
      return wx.showToast({ title: '当前环境不支持检查', icon: 'none' })
    }
    wx.getSavedFileList({
      success: result => {
        const valid = new Set((result.fileList || []).map(file => file.filePath))
        const projects = this.readLocalProjects()
        const next = projects.filter(project => project.blankPaper || !project.sourcePath || valid.has(project.sourcePath))
        const removed = projects.length - next.length
        if (!removed) {
          this.refreshStorageStats()
          return wx.showToast({ title: '没有失效项目', icon: 'success' })
        }
        wx.showModal({
          title: '发现 ' + removed + ' 个失效项目',
          content: '只清理原图已经不存在的本地项目记录，不删除正常项目，也不会处理其他业务保存的文件。',
          confirmText: '清理',
          confirmColor: '#119d62',
          success: modal => {
            if (!modal.confirm) return
            if (!this.writeLocalProjects(next)) return
            if (next.every(project => project.id !== this.currentProjectId)) this.currentProjectId = ''
            this.refreshLocalHome()
            this.refreshStorageStats()
            wx.showToast({ title: '已清理失效记录', icon: 'success' })
          }
        })
      },
      fail: () => wx.showToast({ title: '本地文件检查失败', icon: 'none' })
    })
  },

  clearFromMore() {
    this.setData({ moreSheetVisible: false }, () => this.clear())
  },

  bringSelectedToFront() {
    const index = this.shapeIndex(this.data.selectedId)
    if (index < 0 || index === this.shapes.length - 1) return
    this.pushHistory()
    const item = this.shapes.splice(index, 1)[0]
    this.shapes.push(item)
    this.syncState()
    this.render()
  },

  sendSelectedToBack() {
    const index = this.shapeIndex(this.data.selectedId)
    if (index <= 0) return
    this.pushHistory()
    const item = this.shapes.splice(index, 1)[0]
    this.shapes.unshift(item)
    this.syncState()
    this.render()
  },

  shapeIndex(id) {
    if (!id) return -1
    return this.shapes.findIndex(item => item.id === id)
  },

  ensureSelection() {
    if (this.shapeIndex(this.data.selectedId) < 0) {
      this.setData({ selectedId: '', selectedName: '', selectedCanEditText: false, styleType: this.data.tool })
    }
  },

  syncState() {
    const textTools = ['dimension', 'text', 'leader', 'multiDimension', 'area']
    const layers = this.shapes.map((shape, index) => ({
      id: shape.id,
      name: this.layerName(shape),
      visible: shape.visible !== false,
      canUp: index < this.shapes.length - 1,
      canDown: index > 0,
      selected: shape.id === this.data.selectedId,
      type: shape.tool
    })).reverse()
    const selected = this.shapes[this.shapeIndex(this.data.selectedId)]
    this.setData({
      layers,
      selectedName: selected ? this.layerName(selected) : '',
      selectedCanEditText: !!(selected && textTools.indexOf(selected.tool) >= 0),
      styleType: selected ? selected.tool : this.data.tool,
      canUndo: this.history.length > 0,
      canRedo: this.redoHistory.length > 0
    })
    this.scheduleDraftSave()
  },


  layerName(shape) {
    if (!shape) return '标注'
    if (['dimension', 'text', 'leader', 'multiDimension', 'area'].indexOf(shape.tool) >= 0 && shape.text) {
      const short = String(shape.text).replace(/\s+/g, ' ').slice(0, 12)
      return (TYPE_LABEL[shape.tool] || '标注') + ' · ' + short
    }
    return TYPE_LABEL[shape.tool] || '标注'
  },


  hitTest(point) {
    const tolerance = Math.max(20, this.outputWidth / 42)
    for (let i = this.shapes.length - 1; i >= 0; i--) {
      const shape = this.shapes[i]
      if (shape.visible === false) continue
      if (shape.tool === 'localZoom') {
        const radius = Number(shape.radius) || this.localZoomRadius()
        if (shape.end && Math.hypot(point.x - shape.end.x, point.y - shape.end.y) <= radius + tolerance) return shape
        if (this.distanceToSegment(point, shape.start, shape.end) <= tolerance) return shape
        continue
      }
      if (shape.tool === 'leader') {
        if (this.distanceToSegment(point, shape.start, shape.end) <= tolerance) return shape
        const box = this.leaderLabelBox(shape)
        const edge = { x: box.sign > 0 ? box.left : box.right, y: box.centerY }
        if (this.distanceToSegment(point, shape.end, edge) <= tolerance) return shape
        if (point.x >= box.left - tolerance && point.x <= box.right + tolerance &&
            point.y >= box.top - tolerance && point.y <= box.bottom + tolerance) return shape
        continue
      }
      if (shape.tool === 'area') {
        const points = this.ensureAreaPoints(shape)
        for (let p = 0; p < points.length; p++) {
          const next = points[(p + 1) % points.length]
          if (this.distanceToSegment(point, points[p], next) <= tolerance) return shape
        }
        if (this.pointInPolygon(point, points)) return shape
        continue
      }
      if (shape.tool === 'line' || shape.tool === 'arrow' || shape.tool === 'dimension') {
        if (this.distanceToSegment(point, shape.start, shape.end) <= tolerance) return shape
        if (shape.tool === 'dimension') {
          const label = this.dimensionLabelPoint(shape)
          const fontSize = this.fontSize()
          const width = Math.min(this.outputWidth * 0.65, Math.max(fontSize * 2.4, String(shape.text || '').length * fontSize * 0.62 + fontSize))
          const height = fontSize * 1.45
          if (point.x >= label.x - width / 2 - tolerance && point.x <= label.x + width / 2 + tolerance &&
              point.y >= label.y - height / 2 - tolerance && point.y <= label.y + height / 2 + tolerance) return shape
        }
        continue
      }
      if ((shape.tool === 'pen' || shape.tool === 'multiDimension') && shape.points && shape.points.length > 1) {
        for (let p = 1; p < shape.points.length; p++) {
          if (this.distanceToSegment(point, shape.points[p - 1], shape.points[p]) <= tolerance) return shape
        }
        continue
      }
      const box = this.shapeBounds(shape)
      if (point.x >= box.left - tolerance && point.x <= box.right + tolerance &&
          point.y >= box.top - tolerance && point.y <= box.bottom + tolerance) return shape
    }
    return null
  },


  distanceToSegment(point, a, b) {
    if (!a || !b) return Infinity
    const dx = b.x - a.x
    const dy = b.y - a.y
    if (dx === 0 && dy === 0) return Math.hypot(point.x - a.x, point.y - a.y)
    const t = Math.max(0, Math.min(1, ((point.x - a.x) * dx + (point.y - a.y) * dy) / (dx * dx + dy * dy)))
    const x = a.x + t * dx
    const y = a.y + t * dy
    return Math.hypot(point.x - x, point.y - y)
  },

  shapeBounds(shape) {
    if ((shape.tool === 'pen' || shape.tool === 'multiDimension' || shape.tool === 'area') && shape.points && shape.points.length) {
      const points = shape.tool === 'area' ? this.ensureAreaPoints(shape) : shape.points
      let left = points[0].x
      let right = left
      let top = points[0].y
      let bottom = top
      points.forEach(point => {
        left = Math.min(left, point.x)
        right = Math.max(right, point.x)
        top = Math.min(top, point.y)
        bottom = Math.max(bottom, point.y)
      })
      const padding = shape.tool === 'multiDimension' ? this.fontSize() * 2.2 : (shape.tool === 'area' ? this.fontSize() : 0)
      return { left: left - padding, right: right + padding, top: top - padding, bottom: bottom + padding }
    }
    if (shape.tool === 'text') {
      const fontSize = this.fontSize()
      const width = Math.min(this.outputWidth / 2, Math.max(fontSize * 2, String(shape.text || '').length * fontSize * 0.62))
      return { left: shape.start.x, right: shape.start.x + width, top: shape.start.y, bottom: shape.start.y + fontSize * 1.5 }
    }
    if (shape.tool === 'locationStamp') {
      const fontSize = Math.max(20, Math.round(this.fontSize() * .74))
      const width = Math.min(this.outputWidth * .82, fontSize * 24)
      return { left: shape.start.x, right: shape.start.x + width, top: shape.start.y, bottom: shape.start.y + fontSize * 3.1 }
    }
    const a = shape.start || { x: 0, y: 0 }
    const b = shape.end || a
    if (shape.tool === 'localZoom') {
      const radius = Number(shape.radius) || this.localZoomRadius()
      return {
        left: Math.min(a.x, b.x - radius),
        right: Math.max(a.x, b.x + radius),
        top: Math.min(a.y, b.y - radius),
        bottom: Math.max(a.y, b.y + radius)
      }
    }
    const padding = shape.tool === 'dimension' ? this.fontSize() : 0
    if (shape.tool === 'dimension') {
      const label = this.dimensionLabelPoint(shape)
      return {
        left: Math.min(a.x, b.x, label.x - padding * 1.5),
        right: Math.max(a.x, b.x, label.x + padding * 1.5),
        top: Math.min(a.y, b.y, label.y - padding),
        bottom: Math.max(a.y, b.y, label.y + padding)
      }
    }
    if (shape.tool === 'leader') {
      const box = this.leaderLabelBox(shape)
      return {
        left: Math.min(a.x, b.x, box.left),
        right: Math.max(a.x, b.x, box.right),
        top: Math.min(a.y, b.y, box.top),
        bottom: Math.max(a.y, b.y, box.bottom)
      }
    }
    return {
      left: Math.min(a.x, b.x),
      right: Math.max(a.x, b.x),
      top: Math.min(a.y, b.y),
      bottom: Math.max(a.y, b.y)
    }
  },


  translateShape(shape, dx, dy) {
    const clampX = value => Math.max(0, Math.min(this.outputWidth, value))
    const clampY = value => Math.max(0, Math.min(this.outputHeight, value))
    if (shape.tool === 'area') {
      const points = this.ensureAreaPoints(shape)
      shape.points = points.map(point => ({ x: clampX(point.x + dx), y: clampY(point.y + dy) }))
      this.syncAreaLegacyBounds(shape)
      return
    }
    if (shape.start) {
      shape.start.x = clampX(shape.start.x + dx)
      shape.start.y = clampY(shape.start.y + dy)
    }
    if (shape.end) {
      shape.end.x = clampX(shape.end.x + dx)
      shape.end.y = clampY(shape.end.y + dy)
    }
    if (shape.points) {
      shape.points = shape.points.map(point => ({ x: clampX(point.x + dx), y: clampY(point.y + dy) }))
    }
  },


  lineWidth(shape) {
    const factor = shape.stroke === 'thin' ? 0.55 : (shape.stroke === 'thick' ? 2.15 : 1.15)
    return Math.max(2, Math.round(this.outputWidth / 260 * factor))
  },


  fontSize() {
    return Math.max(24, Math.round(this.outputWidth / 32))
  },

  render(active, exporting) {
    if (!this.ctx || (!this.image && !this.blankPaper)) return
    const ctx = this.ctx
    ctx.clearRect(0, 0, this.outputWidth, this.outputHeight)
    if (this.blankPaper) {
      ctx.save()
      ctx.fillStyle = '#ffffff'
      ctx.fillRect(0, 0, this.outputWidth, this.outputHeight)
      ctx.restore()
    } else {
      ctx.drawImage(this.image, 0, 0, this.outputWidth, this.outputHeight)
    }
    this.shapes.forEach(shape => {
      if (shape.visible !== false) this.draw(shape)
    })
    if (active) this.draw(active)
    if (!exporting && this.guideState) this.drawGuide(this.guideState)
    if (!exporting && this.data.selectedId) {
      const selected = this.shapes[this.shapeIndex(this.data.selectedId)]
      if (selected && selected.visible !== false) this.drawSelection(selected)
    }
  },

  configureLine(ctx, shape) {
    const lineWidth = this.lineWidth(shape)
    ctx.strokeStyle = shape.color || '#f44336'
    ctx.fillStyle = shape.color || '#f44336'
    ctx.lineWidth = lineWidth
    ctx.lineCap = 'round'
    ctx.lineJoin = 'round'
    ctx.shadowColor = 'rgba(0,0,0,.35)'
    ctx.shadowBlur = Math.max(1, lineWidth)
    if (shape.lineStyle === 'dashed' && ctx.setLineDash) {
      ctx.setLineDash([lineWidth * 4, lineWidth * 2.5])
    } else if (ctx.setLineDash) {
      ctx.setLineDash([])
    }
    return lineWidth
  },

  draw(shape) {
    const ctx = this.ctx
    const a = shape.start || { x: 0, y: 0 }
    const b = shape.end || a
    ctx.save()
    const lineWidth = this.configureLine(ctx, shape)

    if (shape.tool === 'pen') {
      const points = shape.points || []
      if (points.length) {
        ctx.beginPath()
        ctx.moveTo(points[0].x, points[0].y)
        points.slice(1).forEach(point => ctx.lineTo(point.x, point.y))
        ctx.stroke()
      }
    } else if (shape.tool === 'line' || shape.tool === 'arrow' || shape.tool === 'dimension') {
      ctx.beginPath()
      ctx.moveTo(a.x, a.y)
      ctx.lineTo(b.x, b.y)
      ctx.stroke()
      if (shape.tool === 'arrow') this.drawArrowHead(b, a, lineWidth)
      if (shape.tool === 'dimension') {
        this.drawDimensionEnds(shape, lineWidth)
        const label = this.dimensionLabelPoint(shape)
        this.drawLabel(shape.text || '', label.x, label.y, shape.color || '#f44336')
      }
    } else if (shape.tool === 'leader') {
      this.drawLeader(shape, lineWidth)
    } else if (shape.tool === 'multiDimension') {
      this.drawMultiDimension(shape, lineWidth)
    } else if (shape.tool === 'area') {
      this.drawArea(shape)
    } else if (shape.tool === 'localZoom') {
      this.drawLocalZoom(shape, lineWidth)
    } else if (shape.tool === 'rect') {
      ctx.strokeRect(a.x, a.y, b.x - a.x, b.y - a.y)
    } else if (shape.tool === 'ellipse') {
      const rx = Math.max(1, Math.abs(b.x - a.x) / 2)
      const ry = Math.max(1, Math.abs(b.y - a.y) / 2)
      ctx.beginPath()
      ctx.ellipse((a.x + b.x) / 2, (a.y + b.y) / 2, rx, ry, 0, 0, Math.PI * 2)
      ctx.stroke()
    } else if (shape.tool === 'text' && shape.text) {
      this.drawText(shape)
    } else if (shape.tool === 'locationStamp') {
      this.drawLocationStamp(shape)
    }

    ctx.restore()
  },



  leaderLabelBox(shape) {
    const fontSize = this.fontSize()
    const text = String(shape && shape.text || '')
    const end = shape && shape.end ? shape.end : { x: 0, y: 0 }
    const start = shape && shape.start ? shape.start : end
    const sign = end.x >= start.x ? 1 : -1
    let width = Math.max(fontSize * 2.8, text.length * fontSize * .62 + fontSize * 1.25)
    if (this.ctx && text) {
      this.ctx.save()
      this.ctx.font = 'bold ' + fontSize + 'px sans-serif'
      width = Math.max(fontSize * 2.8, this.ctx.measureText(text).width + fontSize * 1.25)
      this.ctx.restore()
    }
    width = Math.min(this.outputWidth * .58, width)
    const height = fontSize * 1.5
    let centerX = end.x + sign * (width / 2 + fontSize * .55)
    centerX = Math.max(width / 2 + 2, Math.min(this.outputWidth - width / 2 - 2, centerX))
    const centerY = Math.max(height / 2 + 2, Math.min(this.outputHeight - height / 2 - 2, end.y))
    return {
      centerX,
      centerY,
      left: centerX - width / 2,
      right: centerX + width / 2,
      top: centerY - height / 2,
      bottom: centerY + height / 2,
      width,
      height,
      sign
    }
  },

  drawLeader(shape, lineWidth) {
    const ctx = this.ctx
    const a = shape.start
    const b = shape.end
    if (!a || !b) return
    const box = this.leaderLabelBox(shape)
    const labelEdgeX = box.sign > 0 ? box.left : box.right

    ctx.save()
    ctx.shadowColor = 'transparent'
    ctx.strokeStyle = shape.color || '#f44336'
    ctx.fillStyle = shape.color || '#f44336'
    ctx.lineWidth = lineWidth
    ctx.beginPath()
    ctx.moveTo(a.x, a.y)
    ctx.lineTo(b.x, b.y)
    ctx.lineTo(labelEdgeX, box.centerY)
    ctx.stroke()

    ctx.beginPath()
    ctx.arc(a.x, a.y, Math.max(4, lineWidth * 1.15), 0, Math.PI * 2)
    ctx.fill()
    ctx.restore()

    this.drawLabel(shape.text || '', box.centerX, box.centerY, shape.color || '#f44336')
  },


  drawMultiDimension(shape, lineWidth) {
    const ctx = this.ctx
    const points = shape.points || []
    if (!points.length) return

    // 多段测量第一下只有一个点，必须给出明确反馈，否则会误以为首点未记录。
    if (points.length === 1) {
      const point = points[0]
      const radius = Math.max(8, lineWidth * 2.6)
      ctx.save()
      ctx.shadowColor = 'transparent'
      if (ctx.setLineDash) ctx.setLineDash([])
      ctx.fillStyle = shape.color || '#f44336'
      ctx.strokeStyle = 'rgba(255,255,255,.94)'
      ctx.lineWidth = Math.max(2, lineWidth * .7)
      ctx.beginPath()
      ctx.arc(point.x, point.y, radius, 0, Math.PI * 2)
      ctx.fill()
      ctx.stroke()
      ctx.restore()
      return
    }

    const labels = this.multiDimensionLabels(shape)
    const fontSize = this.fontSize()

    ctx.save()
    ctx.shadowColor = 'transparent'
    ctx.strokeStyle = shape.color || '#f44336'
    ctx.lineWidth = lineWidth

    for (let i = 1; i < points.length; i++) {
      const start = points[i - 1]
      const end = points[i]
      const dx = end.x - start.x
      const dy = end.y - start.y
      const len = Math.max(1, Math.hypot(dx, dy))
      const nx = -dy / len
      const ny = dx / len
      const cap = Math.max(10, lineWidth * 3.2)

      ctx.beginPath()
      ctx.moveTo(start.x, start.y)
      ctx.lineTo(end.x, end.y)
      ctx.stroke()

      ;[start, end].forEach(point => {
        ctx.beginPath()
        ctx.moveTo(point.x - nx * cap, point.y - ny * cap)
        ctx.lineTo(point.x + nx * cap, point.y + ny * cap)
        ctx.stroke()
      })

      const label = labels[i - 1] || ''
      if (label) {
        const offset = fontSize * 1.05 + lineWidth * 2
        const mx = (start.x + end.x) / 2 + nx * offset
        const my = (start.y + end.y) / 2 + ny * offset
        this.drawLabel(label, mx, my, shape.color || '#f44336')
      }
    }
    ctx.restore()
  },


  ensureAreaPoints(shape) {
    if (!shape) return []
    if (Array.isArray(shape.points) && shape.points.length === 4) return shape.points
    const a = shape.start || { x: 0, y: 0 }
    const b = shape.end || a
    shape.points = [
      { x: a.x, y: a.y },
      { x: b.x, y: a.y },
      { x: b.x, y: b.y },
      { x: a.x, y: b.y }
    ]
    return shape.points
  },

  syncAreaLegacyBounds(shape) {
    const points = this.ensureAreaPoints(shape)
    if (!points.length) return
    shape.start = { x: points[0].x, y: points[0].y }
    shape.end = { x: points[2].x, y: points[2].y }
  },

  areaCenter(shape) {
    const points = this.ensureAreaPoints(shape)
    if (!points.length) return { x: 0, y: 0 }
    const total = points.reduce((sum, point) => ({ x: sum.x + point.x, y: sum.y + point.y }), { x: 0, y: 0 })
    return { x: total.x / points.length, y: total.y / points.length }
  },

  pointInPolygon(point, polygon) {
    let inside = false
    for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
      const xi = polygon[i].x
      const yi = polygon[i].y
      const xj = polygon[j].x
      const yj = polygon[j].y
      const intersects = ((yi > point.y) !== (yj > point.y)) &&
        (point.x < (xj - xi) * (point.y - yi) / ((yj - yi) || 1e-9) + xi)
      if (intersects) inside = !inside
    }
    return inside
  },

  drawArea(shape) {
    const ctx = this.ctx
    const points = this.ensureAreaPoints(shape)
    if (points.length < 4) return

    ctx.save()
    ctx.beginPath()
    ctx.moveTo(points[0].x, points[0].y)
    for (let i = 1; i < points.length; i++) ctx.lineTo(points[i].x, points[i].y)
    ctx.closePath()
    ctx.globalAlpha = .10
    ctx.fillStyle = shape.color || '#f44336'
    ctx.fill()
    ctx.restore()

    ctx.save()
    ctx.beginPath()
    ctx.moveTo(points[0].x, points[0].y)
    for (let i = 1; i < points.length; i++) ctx.lineTo(points[i].x, points[i].y)
    ctx.closePath()
    ctx.stroke()
    ctx.restore()

    if (shape.text) {
      const center = this.areaCenter(shape)
      this.drawLabel(shape.text, center.x, center.y, shape.color || '#f44336')
    }
  },


  drawLocalZoom(shape, lineWidth) {
    const ctx = this.ctx
    const source = shape.start
    const center = shape.end
    if (!source || !center) return
    const radius = Number(shape.radius) || this.localZoomRadius()
    const dx = center.x - source.x
    const dy = center.y - source.y
    const distance = Math.max(1, Math.hypot(dx, dy))
    const edge = { x: center.x - dx / distance * radius, y: center.y - dy / distance * radius }

    ctx.save()
    ctx.shadowColor = 'transparent'
    ctx.strokeStyle = shape.color || '#f44336'
    ctx.fillStyle = shape.color || '#f44336'
    ctx.lineWidth = Math.max(3, lineWidth)
    ctx.beginPath()
    ctx.moveTo(source.x, source.y)
    ctx.lineTo(edge.x, edge.y)
    ctx.stroke()
    ctx.beginPath()
    ctx.arc(source.x, source.y, Math.max(5, lineWidth * 1.35), 0, Math.PI * 2)
    ctx.fill()

    ctx.save()
    ctx.beginPath()
    ctx.arc(center.x, center.y, radius - ctx.lineWidth, 0, Math.PI * 2)
    ctx.clip()
    if (this.blankPaper || !this.image) {
      ctx.fillStyle = '#ffffff'
      ctx.fillRect(center.x - radius, center.y - radius, radius * 2, radius * 2)
    } else {
      const zoom = 2.35
      const sampleRadius = Math.max(8, Math.min(radius / zoom, this.outputWidth / 2, this.outputHeight / 2))
      const sampleX = Math.max(sampleRadius, Math.min(this.outputWidth - sampleRadius, source.x))
      const sampleY = Math.max(sampleRadius, Math.min(this.outputHeight - sampleRadius, source.y))
      const naturalWidth = Number(this.image.width) || this.outputWidth
      const naturalHeight = Number(this.image.height) || this.outputHeight
      const sx = (sampleX - sampleRadius) * naturalWidth / this.outputWidth
      const sy = (sampleY - sampleRadius) * naturalHeight / this.outputHeight
      const sw = sampleRadius * 2 * naturalWidth / this.outputWidth
      const sh = sampleRadius * 2 * naturalHeight / this.outputHeight
      ctx.drawImage(this.image, sx, sy, sw, sh, center.x - radius, center.y - radius, radius * 2, radius * 2)
    }
    ctx.restore()

    ctx.beginPath()
    ctx.arc(center.x, center.y, radius, 0, Math.PI * 2)
    ctx.strokeStyle = shape.color || '#f44336'
    ctx.lineWidth = Math.max(4, lineWidth * 1.25)
    ctx.stroke()
    ctx.restore()
  },

  drawLocationStamp(shape) {
    const ctx = this.ctx
    const fontSize = Math.max(20, Math.round(this.fontSize() * .74))
    const x = Math.max(4, Math.min(shape.start.x, this.outputWidth - fontSize * 19))
    const y = Math.max(4, Math.min(shape.start.y, this.outputHeight - fontSize * 3.4))
    ctx.save()
    ctx.shadowColor = 'transparent'
    ctx.font = '600 ' + fontSize + 'px sans-serif'
    ctx.textAlign = 'left'
    ctx.textBaseline = 'top'
    const lines = [shape.timeText || '', shape.locationText || ''].filter(Boolean)
    const width = Math.min(this.outputWidth - x - 4, Math.max.apply(null, lines.map(line => ctx.measureText(line).width).concat([fontSize * 8])) + fontSize)
    const height = fontSize * (lines.length * 1.35 + .6)
    ctx.fillStyle = 'rgba(255,255,255,.92)'
    ctx.fillRect(x, y, width, height)
    ctx.strokeStyle = shape.color || '#119d62'
    ctx.lineWidth = Math.max(2, fontSize / 11)
    ctx.strokeRect(x, y, width, height)
    ctx.fillStyle = '#172126'
    lines.forEach((line, index) => ctx.fillText(line, x + fontSize * .45, y + fontSize * (.34 + index * 1.35), width - fontSize * .9))
    ctx.restore()
  },


  drawArrowHead(tip, from, lineWidth) {
    const ctx = this.ctx
    const angle = Math.atan2(tip.y - from.y, tip.x - from.x)
    const length = Math.max(14, lineWidth * 4.5)
    ctx.beginPath()
    ctx.moveTo(tip.x + length * Math.cos(angle + Math.PI - 0.52), tip.y + length * Math.sin(angle + Math.PI - 0.52))
    ctx.lineTo(tip.x, tip.y)
    ctx.lineTo(tip.x + length * Math.cos(angle + Math.PI + 0.52), tip.y + length * Math.sin(angle + Math.PI + 0.52))
    ctx.stroke()
  },

  drawDimensionEnds(shape, lineWidth) {
    const a = shape.start
    const b = shape.end
    const style = shape.dimensionEnd || 'arrow'
    if (style === 'arrow') {
      this.drawArrowHead(a, b, lineWidth)
      this.drawArrowHead(b, a, lineWidth)
      return
    }
    const ctx = this.ctx
    const angle = Math.atan2(b.y - a.y, b.x - a.x)
    const nx = Math.cos(angle + Math.PI / 2)
    const ny = Math.sin(angle + Math.PI / 2)
    const size = Math.max(10, lineWidth * 3.5)
    ;[a, b].forEach(point => {
      if (style === 'dot') {
        ctx.beginPath()
        ctx.arc(point.x, point.y, Math.max(4, lineWidth * 1.5), 0, Math.PI * 2)
        ctx.fill()
      } else {
        ctx.beginPath()
        ctx.moveTo(point.x - nx * size, point.y - ny * size)
        ctx.lineTo(point.x + nx * size, point.y + ny * size)
        ctx.stroke()
      }
    })
  },

  drawLabel(text, x, y, color) {
    if (!text) return
    const ctx = this.ctx
    const fontSize = this.fontSize()
    ctx.save()
    ctx.shadowColor = 'transparent'
    ctx.font = 'bold ' + fontSize + 'px sans-serif'
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    const width = Math.min(this.outputWidth * 0.65, Math.max(fontSize * 2.4, ctx.measureText(text).width + fontSize))
    const height = fontSize * 1.45
    const left = Math.max(2, Math.min(this.outputWidth - width - 2, x - width / 2))
    const top = Math.max(2, Math.min(this.outputHeight - height - 2, y - height / 2))
    ctx.fillStyle = 'rgba(255,255,255,.94)'
    ctx.fillRect(left, top, width, height)
    ctx.strokeStyle = color
    ctx.lineWidth = Math.max(2, fontSize / 12)
    ctx.strokeRect(left, top, width, height)
    ctx.fillStyle = '#172126'
    ctx.fillText(text, left + width / 2, top + height / 2, width - fontSize * 0.45)
    ctx.restore()
  },

  drawText(shape) {
    const ctx = this.ctx
    const fontSize = this.fontSize()
    ctx.shadowColor = 'transparent'
    ctx.font = 'bold ' + fontSize + 'px sans-serif'
    ctx.textAlign = 'left'
    ctx.textBaseline = 'top'
    const text = String(shape.text || '')
    const maxWidth = Math.max(fontSize * 2, this.outputWidth - shape.start.x - fontSize * 0.5)
    const metricsWidth = Math.min(maxWidth, Math.max(fontSize * 2, ctx.measureText(text).width))
    const x = Math.max(2, Math.min(shape.start.x, this.outputWidth - metricsWidth - fontSize))
    const y = Math.max(2, Math.min(shape.start.y, this.outputHeight - fontSize * 1.7))
    if (shape.textBackground !== false) {
      ctx.fillStyle = 'rgba(255,255,255,.92)'
      ctx.fillRect(x - fontSize * 0.22, y - fontSize * 0.16, metricsWidth + fontSize * 0.44, fontSize * 1.35)
    }
    ctx.lineWidth = Math.max(3, fontSize / 9)
    ctx.strokeStyle = shape.color === '#ffffff' ? '#222222' : '#ffffff'
    ctx.strokeText(text, x, y, maxWidth)
    ctx.fillStyle = shape.color || '#222222'
    ctx.fillText(text, x, y, maxWidth)
  },

  drawGuide(guide) {
    const ctx = this.ctx
    ctx.save()
    ctx.shadowColor = 'transparent'
    ctx.strokeStyle = 'rgba(17,157,98,.72)'
    ctx.fillStyle = '#119d62'
    ctx.lineWidth = Math.max(2, this.outputWidth / 800)
    if (ctx.setLineDash) ctx.setLineDash([8, 8])
    if (guide.type === 'horizontal') {
      ctx.beginPath()
      ctx.moveTo(0, guide.y)
      ctx.lineTo(this.outputWidth, guide.y)
      ctx.stroke()
    } else if (guide.type === 'vertical') {
      ctx.beginPath()
      ctx.moveTo(guide.x, 0)
      ctx.lineTo(guide.x, this.outputHeight)
      ctx.stroke()
    } else if (guide.type === 'endpoint') {
      if (ctx.setLineDash) ctx.setLineDash([])
      ctx.beginPath()
      ctx.arc(guide.x, guide.y, Math.max(8, this.outputWidth / 150), 0, Math.PI * 2)
      ctx.stroke()
    }
    ctx.restore()
  },

  drawSelection(shape) {
    const ctx = this.ctx
    const box = this.shapeBounds(shape)
    const pad = Math.max(8, this.outputWidth / 100)
    ctx.save()
    ctx.shadowColor = 'transparent'
    ctx.strokeStyle = '#119d62'
    ctx.lineWidth = Math.max(2, this.outputWidth / 600)
    if (ctx.setLineDash) ctx.setLineDash([8, 6])
    ctx.strokeRect(
      Math.max(1, box.left - pad),
      Math.max(1, box.top - pad),
      Math.max(2, Math.min(this.outputWidth, box.right + pad) - Math.max(1, box.left - pad)),
      Math.max(2, Math.min(this.outputHeight, box.bottom + pad) - Math.max(1, box.top - pad))
    )

    if (this.canResizeShape(shape)) {
      if (ctx.setLineDash) ctx.setLineDash([])
      const radius = Math.max(9, this.outputWidth / 150)
      ctx.fillStyle = '#ffffff'
      ctx.strokeStyle = '#119d62'
      ctx.lineWidth = Math.max(3, this.outputWidth / 500)
      let controls = [shape.start, shape.end]
      if (shape.tool === 'multiDimension') controls = shape.points || []
      if (shape.tool === 'area') controls = this.ensureAreaPoints(shape)
      controls.forEach(point => {
        if (!point) return
        ctx.beginPath()
        ctx.arc(point.x, point.y, radius, 0, Math.PI * 2)
        ctx.fill()
        ctx.stroke()
      })
      if (shape.tool === 'dimension') {
        const label = this.dimensionLabelPoint(shape)
        ctx.beginPath()
        ctx.rect(label.x - radius * .8, label.y - radius * .8, radius * 1.6, radius * 1.6)
        ctx.fill()
        ctx.stroke()
      }
    }
    ctx.restore()
  },


  exportImage() {
    this.render(null, true)
    return new Promise((resolve, reject) => {
      wx.canvasToTempFilePath({
        canvas: this.canvas,
        fileType: 'png',
        destWidth: this.outputWidth,
        destHeight: this.outputHeight,
        success: result => result.tempFilePath ? resolve(result.tempFilePath) : reject(new Error('未生成文件')),
        fail: reject,
        complete: () => this.render()
      }, this)
    })
  },

  save() {
    if (!this.data.ready || this.data.saving) return
    this.setData({ saving: true })
    this.exportImage().then(path => {
      if (this.returnMode) {
        return this.persistLocalFile(path).then(savedPath => {
          const channel = this.getOpenerEventChannel()
          const payload = { path: savedPath, local_only: true, persisted: true }
          channel.emit('imageAnnotated', payload)
          channel.emit('measurementEdited', payload)
          wx.showToast({ title: '已保存本地，未上传', icon: 'success', duration: 1200 })
          setTimeout(() => wx.navigateBack(), 180)
        })
      }
      this.saveCurrentProjectDraft()
      if (this.data.autoSaveAlbum) {
        return this.saveLocalAlbum(path)
      }
      wx.showToast({ title: '项目已保存', icon: 'success', duration: 1200 })
      return null
    }).catch(error => {
      wx.showToast({ title: (error && error.message) || '保存失败，请重试', icon: 'none' })
    }).finally(() => this.setData({ saving: false }))
  },

  persistLocalFile(tempFilePath) {
    return new Promise((resolve, reject) => {
      wx.saveFile({
        tempFilePath,
        success: result => {
          if (result && result.savedFilePath) resolve(result.savedFilePath)
          else reject(new Error('本地文件保存失败'))
        },
        fail: error => {
          const message = String(error && error.errMsg || '')
          reject(new Error(message.indexOf('space') >= 0 ? '本地存储空间不足，请清理后重试' : '本地文件保存失败'))
        }
      })
    })
  },

  saveLocalAlbum(path) {
    return new Promise((resolve, reject) => {
      wx.saveImageToPhotosAlbum({
        filePath: path,
        success: () => {
          wx.showToast({ title: '已保存到本地相册', icon: 'success' })
          resolve()
        },
        fail: error => {
          const message = String(error && error.errMsg || '')
          if (message.indexOf('auth deny') >= 0 || message.indexOf('auth denied') >= 0) {
            wx.showModal({
              title: '需要相册权限',
              content: '图片只保存到你的本地相册，请在设置中允许保存。',
              confirmText: '去设置',
              confirmColor: '#119d62',
              success: result => {
                if (result.confirm) wx.openSetting()
                resolve()
              }
            })
            return
          }
          reject(new Error('保存到相册失败'))
        }
      })
    })
  }
})
