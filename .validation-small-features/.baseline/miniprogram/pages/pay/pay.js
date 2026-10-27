Page({
  /**
   * 页面的初始数据
   */
  data: {
    slideVal: 5, // slide滑块默认初始值
    showSlide: false, // slide滑块默认显示
    showInput: true, // input表单默认隐藏
    onOff: true, // 其他金额切换开关
  },
 
  /**
   * 生命周期函数--监听页面加载
   */
  onLoad: function(options) {},
 
  // 滑动滑块
  sliderChange(event) {
    const slideVal = event.detail.value;
    this.setData({
      slideVal,
    });
  },
 
  // 点击其他金额进行切换
  onOtherPayTap() {
    const onOff = this.data.onOff;
    if (onOff) {
      this.toggleChange(false, true);
    } else {
      this.toggleChange(true, false);
    }
    this.setData({
      onOff: !onOff,
    });
  },
 
  toggleChange(showSlide, showInput) {
    this.setData({
      showSlide,
      showInput,
    });
  },
 
  // 点击支付按钮,发起支付
  questionPay(event) {
    const { sliderVal, value } = event.detail.value;
    const showSlide = this.data.showSlide;
    const goodsnum = this._getGoodsRandomNumber();
    const subMchId = '1443774102'; // 子商户号,微信支付商户号,必填
    const body = '打赏壁选';
    const sliderPayVal = sliderVal * 100;
    const inputPayVal = value * 100;
    if (showSlide === false) {
      this._callQuestionPay(body, goodsnum, subMchId, sliderPayVal);
    } else {
      if (value) {
        this._callQuestionPay(body, goodsnum, subMchId, inputPayVal);
      } else {
        wx.showToast({
          icon: 'none',
          title: '亲,您没有输入任何数额,无法赞助哦',
          duration: 2000,
        });
      }
    }
  },
 
  // 请求questionPay云函数,调用支付能力
  _callQuestionPay(body, goodsnum, subMchId, payVal) {
    wx.cloud
      .callFunction({
        name: 'questionPay',
        data: {
          // 需要将data里面的参数传给questionPay云函数
          body,
          goodsnum, // 商品订单号不能重复
          subMchId, // 子商户号,微信支付商户号,必填
          payVal, // 这里必须整数,不能是小数,而且类型是number,否则就会报错
        },
      })
      .then((res) => {
        console.log(res);
        const payment = res.result.payment;
        console.log(payment); // 里面包含appId,nonceStr,package,paySign,signType,timeStamp这些支付参数
        wx.requestPayment({
          // 根据获取到的参数调用支付 API 发起支付
          ...payment, // 解构参数appId,nonceStr,package,paySign,signType,timeStamp
          success: (res) => {
            console.log('支付成功', res);
          },
          fail: (err) => {
            console.error('支付失败', err);
          },
        });
      })
      .catch((err) => {
        console.error(err);
      });
  },
 
  // 随机生成商品订单号,订单号不能重复
  _getGoodsRandomNumber() {
    const date = new Date(); // 当前时间
    let Year = `${date.getFullYear()}`; // 获取年份
    let Month = `${
      date.getMonth() + 1 < 10 ? `0${date.getMonth() + 1}` : date.getMonth() + 1
    }`; // 获取月
    let Day = `${date.getDate() < 10 ? `0${date.getDate()}` : date.getDate()}`; // 获取天
    let hour = `${
      date.getHours() < 10 ? `0${date.getHours()}` : date.getHours()
    }`; // 获取小时
    let min = `${
      date.getMinutes() < 10 ? `0${date.getMinutes()}` : date.getMinutes()
    }`; // 获取分钟
    let sec = `${
      date.getSeconds() < 10 ? `0${date.getSeconds()}` : date.getSeconds()
    }`; // 获取秒
    let formateDate = `${Year}${Month}${Day}${hour}${min}${sec}`; // 时间
    return `${Math.round(Math.random() * 1000)}${formateDate +
      Math.round(Math.random() * 89 + 100).toString()}`;
  },
});