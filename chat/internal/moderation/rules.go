package moderation

import (
	"regexp"
	"strings"
	"unicode"
	"unicode/utf8"
)

const RuleVersion = "wechat-v2.diversion-20261025.2"

var appPattern = regexp.MustCompile(`^[A-Za-z0-9_-]{1,64}$`)
var tokenPattern = regexp.MustCompile(`^[A-Za-z0-9_-]{10,}$`)
var hexPattern = regexp.MustCompile(`^[a-f0-9]{64}$`)
var openIDPattern = regexp.MustCompile(`^[A-Za-z0-9_-]{20,64}$`)
var clauses = regexp.MustCompile(`[，,。！？!?;；\n]+`)
var negated = regexp.MustCompile(`(?:请勿|不要|禁止|不准|不能|别再|拒绝|不允许|不得)(?:私下|私自|绕过平台|发|发送|添加|加|提供|泄露|透露|联系|转账|走私单)`)
var mobile = regexp.MustCompile(`(?:^|[^a-z0-9_])(?:\+?86)?(1[3-9][0-9]{9})(?:[^a-z0-9_]|$)`)
var contact = regexp.MustCompile(`(?:联系我|联系你|联系电话|手机号|手机号码|电话|打给我|致电|加我|拨打|给我打|微信|\bweixin\b|\bvx\b|\bwx\b)`)
var wechatID = regexp.MustCompile(`(?:微信|weixin|vx|wx|v信|薇信)(?:号|是|为|号码|[:：])*([a-z][a-z0-9_-]{5,19})`)
var privateDeal = regexp.MustCompile(`(?:绕过平台|不要走平台|不走平台|别走平台|避开平台|私下接单|私下成交|私下交易|私下转账|私下做|私下付款|省掉服务费|绕过服务费|取消订单.{0,24}私下|私下.{0,24}取消订单)`)
var englishDigit = regexp.MustCompile(`\b(zero|one|two|three|four|five|six|seven|eight|nine|ling|yao|yi|er|san|si|wu|liu|qi|ba|jiu)\b`)
var digitWords = map[string]string{"zero": "0", "one": "1", "two": "2", "three": "3", "four": "4", "five": "5", "six": "6", "seven": "7", "eight": "8", "nine": "9", "ling": "0", "yao": "1", "yi": "1", "er": "2", "san": "3", "si": "4", "wu": "5", "liu": "6", "qi": "7", "ba": "8", "jiu": "9"}

func validEnvironment(e string) bool { return e == "production" || e == "development" || e == "test" }
func normalize(text string) string {
	var b strings.Builder
	for _, r := range strings.ToLower(text) {
		if r >= 0xff01 && r <= 0xff5e {
			r -= 0xfee0
			r = unicode.ToLower(r)
		}
		if unicode.Is(unicode.Cf, r) {
			continue
		}
		switch r {
		case '零', '〇', '洞':
			r = '0'
		case '一', '壹', '幺':
			r = '1'
		case '二', '贰', '两':
			r = '2'
		case '三', '叁':
			r = '3'
		case '四', '肆':
			r = '4'
		case '五', '伍':
			r = '5'
		case '六', '陆':
			r = '6'
		case '七', '柒', '拐':
			r = '7'
		case '八', '捌':
			r = '8'
		case '九', '玖', '勾':
			r = '9'
		}
		b.WriteRune(r)
	}
	return englishDigit.ReplaceAllStringFunc(b.String(), func(v string) string { return digitWords[v] })
}
func compact(text string) string {
	return strings.Map(func(r rune) rune {
		if unicode.IsSpace(r) || r == '-' || r == '_' || r == '·' || r == '/' || r == '\\' || r == '(' || r == ')' || r == '（' || r == '）' {
			return -1
		}
		return r
	}, text)
}

// Business exceptions bind to the value itself, never to a keyword elsewhere.
// Masking inserts a barrier so two lawful numbers cannot become a new phone.
var businessValue = regexp.MustCompile(`(?:订单号|工单号|派工单号|流水号|合同号|运单号|材料编号|产品编号|设备编号|产品型号|材料型号|型号|规格)[ \t]*(?:是|为|[:：#])?[ \t]*[a-z0-9][a-z0-9_.\/-]{0,63}`)
var quantityValue = regexp.MustCompile(`(^|[^a-z0-9_])[0-9]+(?:\.[0-9]+)?[ \t]*(?:平方毫米|平方厘米|平方米|立方米|毫米|厘米|千米|公斤|千克|万元|米|吨|元|m²|m³|(?:mm|cm|m2|m3|kg|kpa|mpa|kv|kw|m)\b)`)
var ordinaryURL = regexp.MustCompile(`https?://[^\s，,。！？!?;；]+|weixin://[^\s，,。！？!?;；]+`)
var directContactLink = regexp.MustCompile(`(?:^|[^a-z0-9+.-])(?:weixin://(?:contacts/profile/|dl/(?:profile|addfriend)(?:[/?:]|$)|wxpay/)|wxp://|alipayqr://|https?://(?:weixin\.qq\.com/r/|u\.wechat\.com/|qr\.alipay\.com/))`)
var explicitPhone = regexp.MustCompile(`(?:^|[^a-z0-9_])(?:手机号|手机号码|联系电话|电话|手机|联系我|联系你|加我|微信|weixin|vx|wx|tel)(?:号码|号|是|为|[:：])*[ \t]*(?:\+?86)?[0-9]{11}(?:[^0-9]|$)`)
var bareEleven = regexp.MustCompile(`^(?:[0-9]{11}|(?:\+?86)1[3-9][0-9]{9})$`)
var generatedWechat = regexp.MustCompile(`^wxid_[a-z0-9]{6,32}$`)

func businessContent(text string) string {
	return businessValue.ReplaceAllString(ordinaryURL.ReplaceAllString(normalize(text), "|"), "|")
}
func contactContent(text string) string {
	return quantityValue.ReplaceAllString(businessContent(text), "$1|")
}
func clearPhone(text string) bool {
	for _, clause := range clauses.Split(text, -1) {
		value := strings.Trim(compact(clause), "：:。.!！?？,，;；\"'“”‘’<>《》[]【】")
		if bareEleven.MatchString(value) || mobile.MatchString(compact(clause)) {
			return true
		}
	}
	return false
}

// Explicit contacts are rejected even without an invitation or inside a quote.
// Ambiguous fragments/IDs pass this local rule and still need official safety.
func analyze(text string, previous []string) string {
	normalized := normalize(text)
	if explicitPhone.MatchString(compact(businessContent(text))) {
		return "private_phone"
	}
	for _, clause := range clauses.Split(normalized, -1) {
		if !negated.MatchString(clause) && privateDeal.MatchString(compact(clause)) {
			return "off_platform_deal"
		}
	}
	if directContactLink.MatchString(normalized) {
		return "private_contact_link"
	}
	current := contactContent(text)
	if wechatEvidence(current) || generatedWechat.MatchString(strings.TrimSpace(current)) {
		return "private_wechat"
	}
	if clearPhone(current) {
		return "private_phone"
	}
	if strings.TrimSpace(current) == "" {
		return ""
	}
	prior := ""
	for _, p := range previous {
		prior += contactContent(p)
	}
	joined := prior + current
	if !clearPhone(prior) && contact.MatchString(joined) && mobile.MatchString(compact(joined)) &&
		(strings.ContainsAny(current, "0123456789") || contact.MatchString(current)) {
		return "split_private_phone"
	}
	if !wechatEvidence(prior) && wechatEvidence(joined) &&
		(strings.ContainsAny(current, "abcdefghijklmnopqrstuvwxyz0123456789") || strings.Contains(current, "微信")) {
		return "split_private_wechat"
	}
	return ""
}
func validText(s string) bool {
	return utf8.ValidString(s) && len(s) > 0 && len(s) <= 4000 && utf8.RuneCountInString(s) <= 2000 && strings.TrimSpace(s) != "" && !strings.ContainsRune(s, 0)
}

func wechatEvidence(text string) bool {
	text = strings.Map(func(r rune) rune {
		if unicode.IsSpace(r) {
			return -1
		}
		return r
	}, text)
	for _, match := range wechatID.FindAllStringSubmatchIndex(text, -1) {
		end := match[3]
		if end < len(text) && (text[end] >= 'a' && text[end] <= 'z' || text[end] >= '0' && text[end] <= '9' || text[end] == '_' || text[end] == '-') {
			continue
		}
		start := match[0]
		if start > 0 && (text[start-1] >= 'a' && text[start-1] <= 'z' || text[start-1] >= '0' && text[start-1] <= '9' || text[start-1] == '_' || text[start-1] == '-') {
			continue
		}
		return true
	}
	return false
}
