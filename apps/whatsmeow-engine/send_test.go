package engine_test

import (
	"bytes"
	"encoding/json"
	"errors"
	"image"
	"image/color"
	"image/jpeg"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	engine "github.com/surdu/deal-promoter/apps/whatsmeow-engine"
	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/types"
)

// makeSourceJPEG returns a JPEG-encoded image of the given dimensions, used as
// the raw source the high-res path fetches and derives both thumbnails from.
func makeSourceJPEG(t *testing.T, w, h int) []byte {
	t.Helper()
	img := image.NewRGBA(image.Rect(0, 0, w, h))
	for y := 0; y < h; y++ {
		for x := 0; x < w; x++ {
			img.Set(x, y, color.RGBA{R: 200, G: 100, B: 50, A: 255})
		}
	}
	var buf bytes.Buffer
	if err := jpeg.Encode(&buf, img, nil); err != nil {
		t.Fatalf("makeSourceJPEG: %v", err)
	}
	return buf.Bytes()
}

// ---------- pure builder tests ----------

func TestBuildExtendedTextMessage_AllFieldsSet(t *testing.T) {
	thumb := []byte{0xFF, 0xD8, 0x01}
	preview := engine.PreviewMeta{
		URL:   "https://example.com/product",
		Title: "Great Product",
		Image: "https://example.com/img.jpg",
	}

	msg := engine.BuildExtendedTextMessage("Hello world", preview, thumb)

	ext := msg.GetExtendedTextMessage()
	if ext == nil {
		t.Fatal("ExtendedTextMessage is nil")
	}

	if got := ext.GetText(); got != "Hello world" {
		t.Errorf("Text = %q, want %q", got, "Hello world")
	}
	if got := ext.GetMatchedText(); got != "https://example.com/product" {
		t.Errorf("MatchedText = %q, want %q", got, "https://example.com/product")
	}
	if got := ext.GetTitle(); got != "Great Product" {
		t.Errorf("Title = %q, want %q", got, "Great Product")
	}
	if got := ext.GetDescription(); got != "" {
		t.Errorf("Description = %q, want empty", got)
	}
	if got := ext.GetPreviewType(); got != waE2E.ExtendedTextMessage_IMAGE {
		t.Errorf("PreviewType = %v, want IMAGE", got)
	}
	if !bytes.Equal(ext.GetJPEGThumbnail(), thumb) {
		t.Errorf("JPEGThumbnail = %v, want %v", ext.GetJPEGThumbnail(), thumb)
	}
}

func TestBuildExtendedTextMessage_NilThumbnail_JPEGThumbnailOmitted(t *testing.T) {
	preview := engine.PreviewMeta{
		URL:   "https://example.com/product",
		Title: "Great Product",
		Image: "https://example.com/img.jpg",
	}

	msg := engine.BuildExtendedTextMessage("Hello world", preview, nil)

	ext := msg.GetExtendedTextMessage()
	if ext == nil {
		t.Fatal("ExtendedTextMessage is nil")
	}

	if got := ext.GetJPEGThumbnail(); got != nil {
		t.Errorf("JPEGThumbnail should be nil when thumbnail is nil, got %v", got)
	}
	// All other fields must still be set.
	if got := ext.GetText(); got != "Hello world" {
		t.Errorf("Text = %q, want %q", got, "Hello world")
	}
	if got := ext.GetMatchedText(); got != "https://example.com/product" {
		t.Errorf("MatchedText = %q, want %q", got, "https://example.com/product")
	}
	if got := ext.GetTitle(); got != "Great Product" {
		t.Errorf("Title = %q, want %q", got, "Great Product")
	}
	if got := ext.GetPreviewType(); got != waE2E.ExtendedTextMessage_IMAGE {
		t.Errorf("PreviewType = %v, want IMAGE", got)
	}
}

func TestBuildHighResExtendedTextMessage_MapsUploadAndKeepsInlineFallback(t *testing.T) {
	inline := []byte{0xFF, 0xD8, 0x99}
	preview := engine.PreviewMeta{
		URL:   "https://example.com/product",
		Title: "Great Product",
		Image: "https://example.com/img.jpg",
	}
	hr := engine.HighResThumbnail{
		DirectPath: "/v/t62.1234/high-res-thumb",
		SHA256:     []byte{0x01, 0x02, 0x03},
		Width:      800,
		Height:     800,
	}

	msg := engine.BuildHighResExtendedTextMessage("Deal text", preview, inline, hr)

	ext := msg.GetExtendedTextMessage()
	if ext == nil {
		t.Fatal("ExtendedTextMessage is nil")
	}
	if got := ext.GetThumbnailDirectPath(); got != hr.DirectPath {
		t.Errorf("ThumbnailDirectPath = %q, want %q", got, hr.DirectPath)
	}
	if !bytes.Equal(ext.GetThumbnailSHA256(), hr.SHA256) {
		t.Errorf("ThumbnailSHA256 = %v, want %v", ext.GetThumbnailSHA256(), hr.SHA256)
	}
	if got := ext.GetThumbnailWidth(); got != 800 {
		t.Errorf("ThumbnailWidth = %d, want 800", got)
	}
	if got := ext.GetThumbnailHeight(); got != 800 {
		t.Errorf("ThumbnailHeight = %d, want 800", got)
	}
	// Inline thumbnail is kept as the fallback.
	if !bytes.Equal(ext.GetJPEGThumbnail(), inline) {
		t.Errorf("JPEGThumbnail = %v, want inline fallback %v", ext.GetJPEGThumbnail(), inline)
	}
	// PreviewType stays IMAGE (verification risk tracked in slice 4).
	if got := ext.GetPreviewType(); got != waE2E.ExtendedTextMessage_IMAGE {
		t.Errorf("PreviewType = %v, want IMAGE", got)
	}
	// The base preview fields are still set.
	if got := ext.GetText(); got != "Deal text" {
		t.Errorf("Text = %q, want %q", got, "Deal text")
	}
	if got := ext.GetTitle(); got != "Great Product" {
		t.Errorf("Title = %q, want %q", got, "Great Product")
	}
	if got := ext.GetMatchedText(); got != preview.URL {
		t.Errorf("MatchedText = %q, want %q", got, preview.URL)
	}
}

// ---------- newsletter JID parsing ----------

func TestParseNewsletterJID(t *testing.T) {
	jid, err := types.ParseJID("123456789@newsletter")
	if err != nil {
		t.Fatalf("ParseJID: %v", err)
	}
	if jid.Server != types.NewsletterServer {
		t.Errorf("Server = %q, want %q", jid.Server, types.NewsletterServer)
	}
}

// ---------- degradation logic (injectable fetcher) ----------

func TestRealEngineDegrade_ThumbnailSuccess_MessageCarriesThumbnail(t *testing.T) {
	thumb := []byte{0xFF, 0xD8, 0x42}
	var capturedMsg *waE2E.Message
	var capturedJID string

	e := engine.NewRealEngineWithStubs(
		func(url string) ([]byte, error) { return thumb, nil },
		func(jid string, msg *waE2E.Message, mediaHandle string) (string, error) {
			capturedJID = jid
			capturedMsg = msg
			return "msg-id-123", nil
		},
	)

	id, err := e.Send(engine.SendRequest{
		ChatID: "123456@newsletter",
		Text:   "Deal text",
		Preview: engine.PreviewMeta{
			URL:   "https://example.com",
			Title: "Prod",
			Image: "https://img.example.com/img.jpg",
		},
	})

	if err != nil {
		t.Fatalf("Send returned error: %v", err)
	}
	if id != "msg-id-123" {
		t.Errorf("message id = %q, want %q", id, "msg-id-123")
	}
	if capturedJID != "123456@newsletter" {
		t.Errorf("JID passed to sender = %q, want %q", capturedJID, "123456@newsletter")
	}
	if capturedMsg == nil {
		t.Fatal("no message passed to sender")
	}
	ext := capturedMsg.GetExtendedTextMessage()
	if !bytes.Equal(ext.GetJPEGThumbnail(), thumb) {
		t.Error("thumbnail not set on message when fetch succeeded")
	}
}

func TestRealEngineDegrade_ThumbnailError_MessageSentWithoutThumbnail(t *testing.T) {
	fetchErr := errors.New("fetch failed")
	var logBuf strings.Builder
	var capturedMsg *waE2E.Message

	e := engine.NewRealEngineWithStubs(
		func(url string) ([]byte, error) { return nil, fetchErr },
		func(jid string, msg *waE2E.Message, mediaHandle string) (string, error) {
			capturedMsg = msg
			return "msg-id-456", nil
		},
	)
	e.SetWarnLogger(func(format string, args ...any) {
		logBuf.WriteString("WARN")
	})

	id, err := e.Send(engine.SendRequest{
		ChatID: "123456@newsletter",
		Text:   "Deal text",
		Preview: engine.PreviewMeta{
			URL:   "https://example.com",
			Title: "Prod",
			Image: "https://img.example.com/img.jpg",
		},
	})

	if err != nil {
		t.Fatalf("Send returned error on thumbnail failure (should degrade): %v", err)
	}
	if id != "msg-id-456" {
		t.Errorf("message id = %q, want %q", id, "msg-id-456")
	}
	if capturedMsg == nil {
		t.Fatal("no message passed to sender")
	}
	ext := capturedMsg.GetExtendedTextMessage()
	if ext.GetJPEGThumbnail() != nil {
		t.Error("JPEGThumbnail should be nil when thumbnail fetch fails")
	}
	if !strings.Contains(logBuf.String(), "WARN") {
		t.Error("expected a warning to be logged on thumbnail failure")
	}
	// Other fields must still be set.
	if got := ext.GetText(); got != "Deal text" {
		t.Errorf("Text = %q, want %q", got, "Deal text")
	}
	if got := ext.GetTitle(); got != "Prod" {
		t.Errorf("Title = %q, want %q", got, "Prod")
	}
}

// ---------- high-res send path (injected fetch/upload/send seams) ----------

func TestSend_HighRes_UploadSuccess_CarriesUploadedThumbnailAndHandle(t *testing.T) {
	source := makeSourceJPEG(t, 1000, 1000) // square -> 800x800 high-res
	var uploadedJPEG []byte
	var capturedMsg *waE2E.Message
	var capturedHandle string

	e := engine.NewRealEngineWithStubs(
		func(url string) ([]byte, error) {
			t.Fatal("low-res fetchThumbnail must not be called on the high-res path")
			return nil, nil
		},
		func(jid string, msg *waE2E.Message, mediaHandle string) (string, error) {
			capturedMsg = msg
			capturedHandle = mediaHandle
			return "msg-hr-1", nil
		},
	)
	e.SetSourceFetcher(func(url string) ([]byte, error) { return source, nil })
	e.SetUploadThumbnail(func(jpeg []byte) (engine.UploadResult, error) {
		uploadedJPEG = jpeg
		return engine.UploadResult{DirectPath: "/v/high-res", SHA256: []byte{0x0A, 0x0B}, Handle: "h-xyz"}, nil
	})

	id, err := e.Send(engine.SendRequest{
		ChatID:  "123@newsletter",
		Text:    "Deal",
		Preview: engine.PreviewMeta{URL: "https://ex.com", Title: "Prod", Image: "https://img/x.jpg", HighRes: true},
	})
	if err != nil {
		t.Fatalf("Send returned error: %v", err)
	}
	if id != "msg-hr-1" {
		t.Errorf("id = %q, want %q", id, "msg-hr-1")
	}
	if capturedHandle != "h-xyz" {
		t.Errorf("media handle passed to sender = %q, want %q", capturedHandle, "h-xyz")
	}
	if len(uploadedJPEG) == 0 {
		t.Error("upload seam was not called with the high-res jpeg")
	}

	ext := capturedMsg.GetExtendedTextMessage()
	if got := ext.GetThumbnailDirectPath(); got != "/v/high-res" {
		t.Errorf("ThumbnailDirectPath = %q, want %q", got, "/v/high-res")
	}
	if !bytes.Equal(ext.GetThumbnailSHA256(), []byte{0x0A, 0x0B}) {
		t.Errorf("ThumbnailSHA256 = %v, want %v", ext.GetThumbnailSHA256(), []byte{0x0A, 0x0B})
	}
	if got := ext.GetThumbnailWidth(); got != 800 {
		t.Errorf("ThumbnailWidth = %d, want 800", got)
	}
	if got := ext.GetThumbnailHeight(); got != 800 {
		t.Errorf("ThumbnailHeight = %d, want 800", got)
	}
	if len(ext.GetJPEGThumbnail()) == 0 {
		t.Error("inline JPEGThumbnail fallback should still be set on the high-res path")
	}
}

func TestSend_HighRes_UploadFails_DegradesToInlineCard(t *testing.T) {
	source := makeSourceJPEG(t, 1000, 1000)
	var logBuf strings.Builder
	var capturedMsg *waE2E.Message
	var capturedHandle string

	e := engine.NewRealEngineWithStubs(
		func(url string) ([]byte, error) {
			t.Fatal("low-res fetchThumbnail must not be called on the high-res path")
			return nil, nil
		},
		func(jid string, msg *waE2E.Message, mediaHandle string) (string, error) {
			capturedMsg = msg
			capturedHandle = mediaHandle
			return "msg-degraded", nil
		},
	)
	e.SetSourceFetcher(func(url string) ([]byte, error) { return source, nil })
	e.SetUploadThumbnail(func(jpeg []byte) (engine.UploadResult, error) {
		return engine.UploadResult{}, errors.New("upload boom")
	})
	e.SetWarnLogger(func(format string, args ...any) { logBuf.WriteString("WARN") })

	id, err := e.Send(engine.SendRequest{
		ChatID:  "123@newsletter",
		Text:    "Deal",
		Preview: engine.PreviewMeta{URL: "https://ex.com", Title: "Prod", Image: "https://img/x.jpg", HighRes: true},
	})
	if err != nil {
		t.Fatalf("Send must succeed on upload failure (degrade), got: %v", err)
	}
	if id != "msg-degraded" {
		t.Errorf("id = %q, want %q", id, "msg-degraded")
	}
	if capturedHandle != "" {
		t.Errorf("media handle = %q, want empty on the degraded inline path", capturedHandle)
	}
	ext := capturedMsg.GetExtendedTextMessage()
	if got := ext.GetThumbnailDirectPath(); got != "" {
		t.Errorf("ThumbnailDirectPath = %q, want empty when upload failed", got)
	}
	if len(ext.GetJPEGThumbnail()) == 0 {
		t.Error("inline JPEGThumbnail should be set on the degraded card")
	}
	if !strings.Contains(logBuf.String(), "WARN") {
		t.Error("expected a warning to be logged on upload failure")
	}
}

func TestSend_HighRes_FetchFails_SendsWithoutImage(t *testing.T) {
	var logBuf strings.Builder
	var capturedMsg *waE2E.Message

	e := engine.NewRealEngineWithStubs(
		func(url string) ([]byte, error) {
			t.Fatal("low-res fetchThumbnail must not be called on the high-res path")
			return nil, nil
		},
		func(jid string, msg *waE2E.Message, mediaHandle string) (string, error) {
			capturedMsg = msg
			return "msg-noimage", nil
		},
	)
	e.SetSourceFetcher(func(url string) ([]byte, error) { return nil, errors.New("fetch boom") })
	e.SetUploadThumbnail(func(jpeg []byte) (engine.UploadResult, error) {
		t.Fatal("upload must not be called when the source fetch fails")
		return engine.UploadResult{}, nil
	})
	e.SetWarnLogger(func(format string, args ...any) { logBuf.WriteString("WARN") })

	id, err := e.Send(engine.SendRequest{
		ChatID:  "123@newsletter",
		Text:    "Deal",
		Preview: engine.PreviewMeta{URL: "https://ex.com", Title: "Prod", Image: "https://img/x.jpg", HighRes: true},
	})
	if err != nil {
		t.Fatalf("Send must succeed on fetch failure (degrade), got: %v", err)
	}
	if id != "msg-noimage" {
		t.Errorf("id = %q, want %q", id, "msg-noimage")
	}
	ext := capturedMsg.GetExtendedTextMessage()
	if ext.GetJPEGThumbnail() != nil {
		t.Error("JPEGThumbnail should be nil when the source fetch fails")
	}
	if got := ext.GetThumbnailDirectPath(); got != "" {
		t.Errorf("ThumbnailDirectPath = %q, want empty when fetch failed", got)
	}
	// Other fields still set.
	if got := ext.GetTitle(); got != "Prod" {
		t.Errorf("Title = %q, want %q", got, "Prod")
	}
	if !strings.Contains(logBuf.String(), "WARN") {
		t.Error("expected a warning to be logged on fetch failure")
	}
}

func TestSend_LowRes_DoesNotUploadAndPassesNoHandle(t *testing.T) {
	thumb := []byte{0xFF, 0xD8, 0x42}
	var capturedHandle string

	e := engine.NewRealEngineWithStubs(
		func(url string) ([]byte, error) { return thumb, nil },
		func(jid string, msg *waE2E.Message, mediaHandle string) (string, error) {
			capturedHandle = mediaHandle
			return "msg-low", nil
		},
	)
	e.SetSourceFetcher(func(url string) ([]byte, error) {
		t.Fatal("source fetch must not be called on the low-res path")
		return nil, nil
	})
	e.SetUploadThumbnail(func(jpeg []byte) (engine.UploadResult, error) {
		t.Fatal("upload must not be called on the low-res path")
		return engine.UploadResult{}, nil
	})

	id, err := e.Send(engine.SendRequest{
		ChatID:  "123@newsletter",
		Text:    "Deal",
		Preview: engine.PreviewMeta{URL: "https://ex.com", Title: "Prod", Image: "https://img/x.jpg", HighRes: false},
	})
	if err != nil {
		t.Fatalf("Send returned error: %v", err)
	}
	if id != "msg-low" {
		t.Errorf("id = %q, want %q", id, "msg-low")
	}
	if capturedHandle != "" {
		t.Errorf("media handle = %q, want empty on the low-res path", capturedHandle)
	}
}

// ---------- /send HTTP handler tests ----------

func TestSendHandler_ValidBody_Returns200WithMessageID(t *testing.T) {
	fake := &engine.FakeEngine{
		State:     engine.ConnStateWorking,
		SendID:    "fake-msg-id",
		SendError: nil,
	}
	srv := engine.NewServer(fake)

	body := `{"chatId":"123@newsletter","text":"hello","preview":{"url":"https://ex.com","title":"Ex","image":"https://img.example.com/i.jpg"}}`
	req := httptest.NewRequest(http.MethodPost, "/send", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	rec := httptest.NewRecorder()

	srv.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Errorf("POST /send: got %d, want 200; body: %s", rec.Code, rec.Body.String())
	}
	var resp struct {
		ID string `json:"id"`
	}
	if err := json.NewDecoder(rec.Body).Decode(&resp); err != nil {
		t.Fatalf("decode response: %v", err)
	}
	if resp.ID != "fake-msg-id" {
		t.Errorf("response id = %q, want %q", resp.ID, "fake-msg-id")
	}
}

func TestSendHandler_DecodesHighResFlag(t *testing.T) {
	fake := &engine.FakeEngine{State: engine.ConnStateWorking, SendID: "fake-id"}
	srv := engine.NewServer(fake)

	body := `{"chatId":"123@newsletter","text":"hello","preview":{"url":"https://ex.com","title":"Ex","image":"https://img/i.jpg","highRes":true}}`
	req := httptest.NewRequest(http.MethodPost, "/send", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	srv.ServeHTTP(httptest.NewRecorder(), req)

	if !fake.LastSendRequest.Preview.HighRes {
		t.Error("handler did not map preview.highRes=true onto the SendRequest")
	}
}

func TestSendHandler_HighResDefaultsFalseWhenAbsent(t *testing.T) {
	fake := &engine.FakeEngine{State: engine.ConnStateWorking, SendID: "fake-id"}
	srv := engine.NewServer(fake)

	body := `{"chatId":"123@newsletter","text":"hello","preview":{"url":"https://ex.com","title":"Ex","image":"https://img/i.jpg"}}`
	req := httptest.NewRequest(http.MethodPost, "/send", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	srv.ServeHTTP(httptest.NewRecorder(), req)

	if fake.LastSendRequest.Preview.HighRes {
		t.Error("preview.highRes should default to false when omitted")
	}
}

func TestSendHandler_MalformedJSON_Returns400(t *testing.T) {
	fake := &engine.FakeEngine{State: engine.ConnStateWorking}
	srv := engine.NewServer(fake)

	req := httptest.NewRequest(http.MethodPost, "/send", strings.NewReader("{bad json"))
	req.Header.Set("Content-Type", "application/json")
	rec := httptest.NewRecorder()

	srv.ServeHTTP(rec, req)

	if rec.Code != http.StatusBadRequest {
		t.Errorf("POST /send malformed: got %d, want 400", rec.Code)
	}
}

func TestSendHandler_EngineError_Returns500(t *testing.T) {
	fake := &engine.FakeEngine{
		State:     engine.ConnStateWorking,
		SendError: errors.New("send failed"),
	}
	srv := engine.NewServer(fake)

	body := `{"chatId":"123@newsletter","text":"hello","preview":{"url":"https://ex.com","title":"Ex","image":"https://img.example.com/i.jpg"}}`
	req := httptest.NewRequest(http.MethodPost, "/send", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	rec := httptest.NewRecorder()

	srv.ServeHTTP(rec, req)

	if rec.Code != http.StatusInternalServerError {
		t.Errorf("POST /send engine error: got %d, want 500", rec.Code)
	}
}
