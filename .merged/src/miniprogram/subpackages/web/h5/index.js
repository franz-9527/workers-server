
Page({
  /*
  data: {
    Title: "web-view",//给个默认的title
    H5Url: ""
  },
  // 跳转到这个页面，加上title的参数，根据传参不同，src不同，自定义
  onLoad: function (options) {
    console.log(options.title)
    let url = "";
    if(options.title == 'B-ART'){
      url = "https://wallcloth.art"
      this.setData({
        H5Url:url,
        Title: options.title   // 设置顶部title内容
      });
      wx.setNavigationBarTitle({
        title: options.title    
      })
    }
    else if(options.title == 'mall'){
      url = "https://mall.wallcloth.art"
      this.setData({
        H5Url:url,
        Title: options.title
      });
      wx.setNavigationBarTitle({
        title: options.title
      })
    }
    else if(options.title == 'bbs'){
      url = "https://bbs.wallcloth.art"
      this.setData({
        H5Url:url,
        Title: options.title
      });
      wx.setNavigationBarTitle({
        title: options.title
      })
    }
    else if(options.title == 'bbs-worker'){
      url = "https://bbs.wallcloth.art/t/worker"
      this.setData({
        H5Url:url,
        Title: options.title
      });
      wx.setNavigationBarTitle({
        title: options.title
      })
    }
    else if(options.title == 'bbs-store'){
      url = "https://bbs.wallcloth.art/t/store"
      this.setData({
        H5Url:url,
        Title: options.title
      });
      wx.setNavigationBarTitle({
        title: options.title
      })
    }
    else if(options.title == 'bbs14'){
      url = "https://bbs.wallcloth.art/d/14"
      this.setData({
        H5Url:url,
        Title: options.title
      });
      wx.setNavigationBarTitle({
        title: options.title
      })
    }
    else if(options.title == 'bbs48'){
      url = "https://bbs.wallcloth.art/d/48"
      this.setData({
        H5Url:url,
        Title: options.title
      });
      wx.setNavigationBarTitle({
        title: options.title
      })
    }
    else if(options.title == 'bbs50'){
      url = "https://bbs.wallcloth.art/d/50"
      this.setData({
        H5Url:url,
        Title: options.title
      });
      wx.setNavigationBarTitle({
        title: options.title
      })
    }
    else if(options.title == 'bbs51'){
      url = "https://bbs.wallcloth.art/d/51"
      this.setData({
        H5Url:url,
        Title: options.title
      });
      wx.setNavigationBarTitle({
        title: options.title
      })
    }
    else if(options.title == 'SVIP'){
      url = "https://mall.wallcloth.art/pages/annex/vip_paid/index"
      this.setData({
        H5Url:url,
        Title: options.title
      });
      wx.setNavigationBarTitle({
        title: options.title
      })
    }
  },
  */
 
  //简化代码
  data: {
    H5Url: "",
    Title: "web-view" // 默认标题
  },

  /* 10.8注释
  onLoad: function (options) {
    const titleMap = {
      'B-ART': "https://wallcloth.art",
      'mall': "https://mall.wallcloth.art",
      'bbs': "https://bbs.wallcloth.art",
      'bbs-worker': "https://bbs.wallcloth.art/t/worker",
      'bbs-store': "https://bbs.wallcloth.art/t/store",
      'bbs14': "https://bbs.wallcloth.art/d/14",
      'bbs48': "https://bbs.wallcloth.art/d/48",
      'bbs50': "https://bbs.wallcloth.art/d/50",
      'bbs51': "https://bbs.wallcloth.art/d/51",
      'SVIP': "https://mall.wallcloth.art/pages/annex/vip_paid/index",
      'kefu': "https://work.weixin.qq.com/kfid/kfc38c120fa9d42e3ab",
    };

    const title = options.title;
    const url = titleMap[title] || ""; // 如果options.title不存在于映射中，则url为空字符串

    if (url) {
      this.setData({
        H5Url: url,
        Title: title
      });
      wx.setNavigationBarTitle({
        title: title
      });
    } else {
      console.warn(`未找到与标题"${title}"对应的URL`);
    }
  }
  */

  onLoad: function (options) {
    const title = options.title;

    // 调用云函数获取url映射
    wx.cloud.callFunction({
      name: 'base', // 云函数名
      data: {
        action: 'getUrlMapping'
      },
      success: res => {
        const urlMapping = res.result.data; // 获取云函数返回的url映射
        const url = urlMapping[title] || ""; // 根据title查找对应的url

        if (url) {
          this.setData({
            H5Url: url,
            Title: title
          });
          wx.setNavigationBarTitle({
            title: title
          });
        } else {
          console.warn(`未找到与标题"${title}"对应的URL`);
        }
      },
      fail: err => {
        console.error("调用云函数失败:", err);
      }
    });
  }

});
