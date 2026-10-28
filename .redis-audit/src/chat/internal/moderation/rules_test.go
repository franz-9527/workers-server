package moderation

import "testing"

func TestDiversionRulesAndConstructionNegatives(t *testing.T) {
	cases := []struct {
		text     string
		previous []string
		blocked  bool
	}{
		{"电话 １３８ ００１３ ８０００", nil, true},
		{"12345678901", nil, true},
		{"tel:+8613800138000", nil, true},
		{"这是13800138000，稍后沟通", nil, true},
		{"abc13800138000mm", nil, false},
		{"12米，13800138000平方米，联系我核对", nil, false},
		{"１３８\u200b００１３８０００", nil, true},
		{"138-0013-8000", nil, true},
		{"+86 138 0013 8000", nil, true},
		{"13800138000，谢谢", nil, true},
		{"请勿发送微信abc123456", nil, true},
		{"微信：abc___", nil, true},
		{"加微信abc123456mm", nil, true},
		{"abcwx13800138000mm", nil, false},
		{"请联系我核对工单号 ：13800138000", nil, false},
		{"微信abcdefghijklmnopqrstuvwxyz", nil, false},
		{"abc_123456", nil, false},
		{"wxid_abcdefgh123456", nil, true},
		{"请联系我确认工单号13800138000", nil, false},
		{"电话请在订单页面查看，产品型号VX13800138000", nil, false},
		{"工单号13800138000，微信开发文档照常使用", nil, false},
		{"联系电话13800138000米", nil, true},
		{"面积13800138000平方米，联系我核对", nil, false},
		{"规格1380x800mm，联系我核对", nil, false},
		{"厂家资料 https://materials.example.test/电话13800138000", nil, false},
		{"材料网址 https://materials.example.test/VXabc123456", nil, false},
		{"weixin://dl/business/?t=fixture", nil, false},
		{"https://u.wechat.com/fixture", nil, true},
		{"https://u.wechat.com.evil.invalid/fixture", nil, false},
		{"工单号13800138000", []string{"电话请看订单页"}, false},
		{"联系我", []string{"工单号13800138000"}, false},
		{"38000", []string{"工单号138001"}, false},

		{"联系我 一三八 零零一三 八零零零", nil, true},
		{"电话 one three eight zero zero one three eight zero zero zero", nil, true},
		{"电话 yao san ba ling ling yi san ba ling ling ling", nil, true},
		{"加我 ＶＸ：abc_123456", nil, true},
		{"加微信a\u200bb\u200cc123456", nil, true},
		{"weixin://contacts/profile/fixture", nil, true},
		{"取消订单后私下做，省掉服务费", nil, true},
		{"不走平台，这单私下做", nil, true},
		{"13800138000", nil, true},
		{"工单号13800138000，墙长12.5米，面积25平方米，报价1380元", nil, false},
		{"施工20261022138001，预约10:30，五楼二单元三零一", nil, false},
		{"不要私下加微信", nil, false},
		{"禁止绕过平台交易", nil, false},
		{"请勿发送电话13800138000", nil, true},
		{"售后电话请在订单页面查看", nil, false},
		{"请使用订单的联系师傅入口，尾款在原入口确认", nil, false},
		{"加微信", nil, false},
		{"型号VXabc123456，按图施工", nil, false},
		{"材料说明 https://brand.example.test/spec/13800138000", nil, false},
		{"不要私下加微信abc123，但加微信xyz789", nil, true},
		{"38000", []string{"电话138001"}, true},
		{"def456", []string{"微信abc"}, true},
		{"ok", []string{"请勿私下加微信abc123"}, false},
		{"ok", []string{"微信abc123456"}, false},
		{"你好", []string{"电话13800138000"}, false},
	}
	for _, c := range cases {
		t.Run(c.text, func(t *testing.T) {
			code := analyze(c.text, c.previous)
			if (code != "") != c.blocked {
				t.Fatalf("decision %q expected blocked=%v", code, c.blocked)
			}
		})
	}
}
