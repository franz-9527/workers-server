package moderation

import (
	"context"
	"database/sql"
	"encoding/json"
	"io"
	"net"
	"net/http"
	"net/url"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"github.com/franz-9527/workers/chat/internal/auth"
	"github.com/franz-9527/workers/chat/internal/ports"
)

type Config struct {
	Enabled            bool   `json:"enabled"`
	AppID              string `json:"app_id"`
	CredentialFile     string `json:"credential_file"`
	RejectionDirectory string `json:"rejection_directory"`
}

func (c Config) Validate() error {
	if !c.Enabled {
		if c.AppID != "" || c.CredentialFile != "" || c.RejectionDirectory != "" {
			return ports.ErrInvalid
		}
		return nil
	}
	if !appPattern.MatchString(c.AppID) || !filepath.IsAbs(c.CredentialFile) || c.RejectionDirectory != "" && !filepath.IsAbs(c.RejectionDirectory) {
		return ports.ErrInvalid
	}
	return nil
}

type HTTPClient interface {
	Do(*http.Request) (*http.Response, error)
}
type Service struct {
	db                 *sql.DB
	source             *auth.MySQLSource
	credentials        *Credentials
	environment, appID string
	client             HTTPClient
	gate               chan struct{}
	timeout            time.Duration
}

func New(db *sql.DB, environment string, c Config, concurrency int, timeout time.Duration, client HTTPClient) (*Service, error) {
	if db == nil || !c.Enabled || c.Validate() != nil || !validEnvironment(environment) || concurrency < 1 || concurrency > 16 || timeout < 100*time.Millisecond || timeout > 2*time.Second {
		return nil, ports.ErrInvalid
	}
	credentials, e := NewCredentials(c.CredentialFile, environment, c.AppID)
	if e != nil {
		return nil, e
	}
	if credentials.setRejectionDirectory(c.RejectionDirectory) != nil {
		return nil, ports.ErrInvalid
	}
	source, e := auth.NewMySQLSource(db)
	if e != nil {
		return nil, e
	}
	if client == nil {
		client = &http.Client{Timeout: timeout, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }, Transport: &http.Transport{Proxy: nil, DialContext: (&net.Dialer{Timeout: timeout / 2, KeepAlive: 30 * time.Second}).DialContext, MaxConnsPerHost: concurrency, MaxIdleConns: concurrency, MaxIdleConnsPerHost: concurrency, IdleConnTimeout: 30 * time.Second, ResponseHeaderTimeout: timeout}}
	}
	return &Service{db, source, credentials, environment, c.AppID, client, make(chan struct{}, concurrency), timeout}, nil
}
func (s *Service) CheckText(ctx context.Context, q ports.TextRequest) (ports.SafetyResult, error) {
	if q.Actor.Environment != s.environment || q.Actor.Kind != "mini" || !validID(q.IntentID) || !validID(q.ConversationID) || !validText(q.Text) || !hexPattern.MatchString(q.Fingerprint) {
		return ports.SafetyResult{}, ports.ErrInvalid
	}
	select {
	case s.gate <- struct{}{}:
		defer func() { <-s.gate }()
	default:
		return ports.SafetyResult{}, ports.ErrBusy
	}
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	identity, e := s.identity(ctx, q.Actor, nil)
	if e != nil {
		return ports.SafetyResult{}, ports.ErrUnavailable
	}
	contextItems, e := s.prepare(ctx, q)
	if e != nil {
		return ports.SafetyResult{}, ports.ErrUnavailable
	}
	previous := make([]string, 0, len(contextItems))
	for _, item := range contextItems {
		previous = append(previous, item.NormalizedText)
	}
	if code := analyze(q.Text, previous); code != "" {
		evidence := ""
		if strings.HasPrefix(code, "split_") {
			snapshot := append(contextItems, contextItem{IntentID: q.IntentID, Fingerprint: q.Fingerprint, NormalizedText: normalize(q.Text)})
			raw, _ := json.Marshal(struct {
				Schema  int           `json:"schema"`
				Actions []contextItem `json:"actions"`
			}{1, snapshot})
			evidence = string(raw)
		}
		return ports.SafetyResult{Decision: "blocked", RuleVersion: RuleVersion, AuthorityProof: identity.proof, Finding: &ports.SafetyFinding{Provider: "local.anti_diversion", Code: code, Level: 2, Confirmed: true, ContextEvidence: evidence}}, nil
	}
	credential, e := s.credentials.Get()
	if e != nil {
		return ports.SafetyResult{}, ports.ErrUnavailable
	}
	key := hash(strings.Join([]string{s.environment, s.appID, identity.proof, q.Actor.SessionVersion, "scene:1", RuleVersion, q.Fingerprint}, "\n"))
	var cached int
	e = s.db.QueryRowContext(ctx, `SELECT 1 FROM chat_text_checks WHERE cache_key=? AND pass_until>UTC_TIMESTAMP(6) LIMIT 1`, key).Scan(&cached)
	if e != nil && e != sql.ErrNoRows {
		return ports.SafetyResult{}, ports.ErrUnavailable
	}
	if cached > 0 {
		return ports.SafetyResult{Decision: "passed", RuleVersion: RuleVersion, AuthorityProof: identity.proof}, nil
	}
	result, e := s.inspect(ctx, credential, identity.openid, q.Text)
	if e != nil {
		return ports.SafetyResult{}, ports.ErrUnavailable
	}
	result.RuleVersion = RuleVersion
	result.AuthorityProof = identity.proof
	if result.Decision == "passed" {
		// Only an explicit official pass is cached. Local contextual rules always
		// ran first and cannot be bypassed by a prior hash-only cache hit.
		if _, e = s.db.ExecContext(ctx, `UPDATE chat_text_checks SET cache_key=?,pass_until=UTC_TIMESTAMP(6)+INTERVAL 5 MINUTE WHERE intent_id=? AND fingerprint=?`, key, string(q.IntentID), q.Fingerprint); e != nil {
			return ports.SafetyResult{}, ports.ErrUnavailable
		}
	}
	return result, nil
}
func validID(id ports.ID) bool { return id.Valid() && id != "0" }

var tracePattern = regexp.MustCompile(`^[A-Za-z0-9_-]{0,128}$`)

func (s *Service) inspect(ctx context.Context, c Credential, openid, text string) (ports.SafetyResult, error) {
	body, e := json.Marshal(map[string]any{"version": 2, "scene": 1, "openid": openid, "content": text})
	if e != nil {
		return ports.SafetyResult{}, ports.ErrUnavailable
	}
	endpoint := "https://api.weixin.qq.com/wxa/msg_sec_check?" + url.Values{"access_token": []string{c.Token}}.Encode()
	req, e := http.NewRequestWithContext(ctx, "POST", endpoint, strings.NewReader(string(body)))
	if e != nil {
		return ports.SafetyResult{}, ports.ErrUnavailable
	}
	req.Header.Set("Content-Type", "application/json; charset=utf-8")
	req.Header.Set("Accept", "application/json")
	res, e := s.client.Do(req)
	if e != nil || res == nil || res.Body == nil {
		return ports.SafetyResult{}, ports.ErrUnavailable
	}
	defer res.Body.Close()
	raw, e := io.ReadAll(io.LimitReader(res.Body, (32<<10)+1))
	if e != nil || len(raw) > 32<<10 || res.StatusCode != 200 {
		return ports.SafetyResult{}, ports.ErrUnavailable
	}
	var response struct {
		Code    *int   `json:"errcode"`
		Message string `json:"errmsg"`
		Trace   string `json:"trace_id"`
		Result  *struct {
			Suggest string `json:"suggest"`
			Label   *int   `json:"label"`
		} `json:"result"`
		Detail json.RawMessage `json:"detail"`
	}
	if !strictObject(raw, &response) || response.Code == nil || !tracePattern.MatchString(response.Trace) {
		return ports.SafetyResult{}, ports.ErrUnavailable
	}
	if *response.Code == 40001 || *response.Code == 40014 || *response.Code == 42001 {
		s.credentials.Reject(c.Generation)
		return ports.SafetyResult{}, ports.ErrUnavailable
	}
	if *response.Code == 87014 {
		return ports.SafetyResult{Decision: "blocked", Finding: &ports.SafetyFinding{Provider: "wechat.msg_sec_check.v2", Code: "official_risky", TraceID: response.Trace, Level: 2, Confirmed: true}}, nil
	}
	if *response.Code != 0 || response.Result == nil {
		return ports.SafetyResult{}, ports.ErrUnavailable
	}
	switch response.Result.Suggest {
	case "pass":
		return ports.SafetyResult{Decision: "passed"}, nil
	case "risky":
		return ports.SafetyResult{Decision: "blocked", Finding: &ports.SafetyFinding{Provider: "wechat.msg_sec_check.v2", Code: "official_risky", TraceID: response.Trace, Level: 2, Confirmed: true}}, nil
	case "review":
		return ports.SafetyResult{Decision: "review", Finding: &ports.SafetyFinding{Provider: "wechat.msg_sec_check.v2", Code: "official_review", TraceID: response.Trace, Level: 1, Confirmed: false}}, nil
	default:
		return ports.SafetyResult{}, ports.ErrUnavailable
	}
}

type binding struct{ openid, proof string }

func (s *Service) identity(ctx context.Context, p ports.Principal, tx *sql.Tx) (binding, error) {
	owned := tx == nil
	if owned {
		var e error
		tx, e = s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
		if e != nil {
			return binding{}, ports.ErrUnavailable
		}
		defer tx.Rollback()
	}
	if p.Environment != s.environment || p.Kind != "mini" {
		return binding{}, ports.ErrInvalid
	}
	if e := s.source.ValidatePrincipalLocked(ctx, tx, p); e != nil {
		return binding{}, e
	}
	rows, e := tx.QueryContext(ctx, `SELECT CAST(id AS CHAR),openid,CAST(session_version AS CHAR) FROM wechat_identities WHERE user_id=? AND app_id=? AND status='active' ORDER BY id LIMIT 2 LOCK IN SHARE MODE`, string(p.ActorID), s.appID)
	if e != nil {
		return binding{}, ports.ErrUnavailable
	}
	count := 0
	var id, openid, version string
	for rows.Next() {
		if e = rows.Scan(&id, &openid, &version); e != nil {
			break
		}
		count++
	}
	failure := rows.Err()
	rows.Close()
	if e != nil || failure != nil || count != 1 || !openIDPattern.MatchString(openid) || !ports.ID(version).Valid() || version == "0" {
		return binding{}, ports.ErrUnavailable
	}
	proof := hash(strings.Join([]string{s.environment, s.appID, string(p.ActorID), id, version, openid}, "\n"))
	if owned && tx.Commit() != nil {
		return binding{}, ports.ErrUnavailable
	}
	return binding{openid, proof}, nil
}

// ValidateTextLocked runs after PHP authority/current login and before the
// stable restriction/conversation lock. No network call occurs in this tx.
func (s *Service) ValidateTextLocked(ctx context.Context, tx *sql.Tx, p ports.Principal, checked ports.SafetyResult) error {
	if checked.RuleVersion != RuleVersion || !hexPattern.MatchString(checked.AuthorityProof) {
		return ports.ErrUnavailable
	}
	identity, e := s.identity(ctx, p, tx)
	if e != nil || identity.proof != checked.AuthorityProof {
		return ports.ErrUnavailable
	}
	return nil
}

type contextItem struct {
	IntentID       ports.ID `json:"intent_id"`
	Fingerprint    string   `json:"fingerprint"`
	NormalizedText string   `json:"normalized_text"`
}

func (s *Service) prepare(ctx context.Context, q ports.TextRequest) ([]contextItem, error) {
	tx, e := s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if e != nil {
		return nil, ports.ErrUnavailable
	}
	defer tx.Rollback()
	var actor string
	if e = tx.QueryRowContext(ctx, `SELECT CAST(actor_id AS CHAR) FROM chat_restrictions WHERE actor_id=? FOR UPDATE`, string(q.Actor.ActorID)).Scan(&actor); e != nil {
		return nil, ports.ErrUnavailable
	}
	var intent string
	e = tx.QueryRowContext(ctx, `SELECT CAST(id AS CHAR) FROM chat_send_intents WHERE id=? AND sender_id=? AND conversation_id=? AND fingerprint=? AND state='checking' LOCK IN SHARE MODE`, string(q.IntentID), string(q.Actor.ActorID), string(q.ConversationID), q.Fingerprint).Scan(&intent)
	if e != nil {
		return nil, ports.ErrUnavailable
	}
	_, e = tx.ExecContext(ctx, `INSERT INTO chat_text_checks(intent_id,sender_id,conversation_id,fingerprint,normalized_text,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE intent_id=intent_id`, intent, string(q.Actor.ActorID), string(q.ConversationID), q.Fingerprint, normalize(q.Text))
	if e != nil {
		return nil, ports.ErrUnavailable
	}
	var fp string
	if e = tx.QueryRowContext(ctx, `SELECT fingerprint FROM chat_text_checks WHERE intent_id=? FOR UPDATE`, intent).Scan(&fp); e != nil || fp != q.Fingerprint {
		return nil, ports.ErrUnavailable
	}
	rows, e := tx.QueryContext(ctx, `SELECT CAST(intent_id AS CHAR),fingerprint,normalized_text FROM chat_text_checks WHERE sender_id=? AND conversation_id=? AND intent_id<>? AND created_at>=UTC_TIMESTAMP(6)-INTERVAL 2 MINUTE ORDER BY created_at DESC,id DESC LIMIT 4 FOR UPDATE`, string(q.Actor.ActorID), string(q.ConversationID), intent)
	if e != nil {
		return nil, ports.ErrUnavailable
	}
	previous := []contextItem{}
	for rows.Next() {
		var item contextItem
		if e = rows.Scan(&item.IntentID, &item.Fingerprint, &item.NormalizedText); e != nil {
			break
		}
		previous = append(previous, item)
	}
	failure := rows.Err()
	rows.Close()
	if e != nil || failure != nil {
		return nil, ports.ErrUnavailable
	}
	for i, j := 0, len(previous)-1; i < j; i, j = i+1, j-1 {
		previous[i], previous[j] = previous[j], previous[i]
	}
	if tx.Commit() != nil {
		return nil, ports.ErrUnavailable
	}
	return previous, nil
}

// Context/cache data is ephemeral; minimal intents and risk evidence are not
// deleted here. The worker sweeps at most 100 expired context rows per tick.
func (s *Service) Clean(ctx context.Context) error {
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	_, e := s.db.ExecContext(ctx, `DELETE FROM chat_text_checks WHERE created_at<UTC_TIMESTAMP(6)-INTERVAL 10 MINUTE ORDER BY created_at LIMIT 100`)
	if e != nil {
		return ports.ErrUnavailable
	}
	return nil
}
func (s *Service) Maintain(ctx context.Context) {
	timer := time.NewTicker(30 * time.Second)
	defer timer.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-timer.C:
			_ = s.Clean(ctx)
		}
	}
}

var _ ports.Safety = (*Service)(nil)

func (s *Service) Close() {
	if c, ok := s.client.(interface{ CloseIdleConnections() }); ok {
		c.CloseIdleConnections()
	}
}
