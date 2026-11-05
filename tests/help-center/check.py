from pathlib import Path

ROOT = Path(__file__).parent
F = ROOT / "fixtures"

def text(path):
    return (F / path).read_text(encoding="utf-8")

def require(content, needle, label):
    if needle not in content:
        raise AssertionError(f"{label}: missing {needle}")

admin = text("server/HelpCenterAdminController.php")
require(admin, "$this->assertPublishable($payload);", "publish guard")
require(admin, "所选场景已停用，无法发布", "disabled-category publish error")
require(admin, "private function wantsJson()", "AJAX save response")
require(admin, "业务入口按钮文字和小程序路径需要同时填写", "business-entry pair validation")
require(admin, "场景编码已存在，请更换编码", "category duplicate validation")

api = text("server/HelpCenterApiController.php")
require(api, "WHERE query_hash=:query_hash AND role_key=:role_key", "search-click role isolation")
require(api, "'aliases' => (string)", "offline alias search data")
require(api, "'keywords' => (string)", "offline keyword search data")
require(api, "c.status='active'", "public category visibility guard")
require(api, "'has_more' => $hasMore", "bootstrap pagination")

read_model = text("server/HelpCenterAdminReadModel.php")
require(read_model, "c.status AS category_status", "admin hidden-category marker")
require(read_model, "visible_count", "visible FAQ stats")
require(read_model, "hidden_published_count", "hidden published stats")

help_api = text("miniprogram/HelpApi.js")
require(help_api, "const seen = Object.create(null)", "bootstrap page de-duplication")
require(help_api, "role: role || 'all'", "search click role payload")
require(help_api, "ApiEnvironment.storageKey(CACHE_KEY)", "environment-scoped cache")

page = text("miniprogram/index.js")
require(page, "if (!this.didShowOnce)", "first onShow duplicate request guard")
if "this.hasLoadedOnce = true" in page:
    raise AssertionError("legacy duplicate-load flag restored")
require(page, "HelpApi.recordClick(faqId, this.data.query.trim(), this.data.activeRole)", "role-aware click logging")

view = text("server/help-center.php")
require(view, "fetch(form.action", "AJAX editor save")
require(view, "data-help-save-status", "save state feedback")
require(view, "（已停用）", "disabled category editor visibility")
if ":has(" in view:
    raise AssertionError("unsupported :has() selector restored")

wxss = text("miniprogram/index.wxss")
require(wxss, ".help-faq-featured", "featured badge style")
require(wxss, "background: #3a2d1e", "dark featured badge override")

# Cheap structural CSS guard for the fixture.
if wxss.count("{") != wxss.count("}"):
    raise AssertionError("WXSS brace count mismatch")

print("help-center regression contracts OK")

# trigger: second-round help-center regression
