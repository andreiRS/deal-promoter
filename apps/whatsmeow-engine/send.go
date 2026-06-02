package engine

import (
	"context"
	"encoding/json"
	"fmt"
	"log"
	"net/http"

	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/types"
	"google.golang.org/protobuf/proto"
)

// defaultWarnLog is the package's default warn-level logger, shared by the
// production Send path and the test stub constructor.
func defaultWarnLog(format string, args ...any) {
	log.Printf("[WARN] "+format, args...)
}

// BuildExtendedTextMessage constructs a waE2E.Message with an ExtendedTextMessage
// carrying all link-preview fields. When thumbnail is nil or empty, JPEGThumbnail
// is omitted; all other fields are always set.
func BuildExtendedTextMessage(text string, preview PreviewMeta, thumbnail []byte) *waE2E.Message {
	previewType := waE2E.ExtendedTextMessage_IMAGE
	ext := &waE2E.ExtendedTextMessage{
		Text:        proto.String(text),
		MatchedText: proto.String(preview.URL),
		Title:       proto.String(preview.Title),
		Description: proto.String(""),
		PreviewType: &previewType,
	}
	if len(thumbnail) > 0 {
		ext.JPEGThumbnail = thumbnail
	}
	return &waE2E.Message{ExtendedTextMessage: ext}
}

// sendFunc is the injectable function signature for actually dispatching a
// message to WhatsApp. In production this wraps client.SendMessage; in tests it
// is replaced by a stub so no network call is made.
type sendFunc func(chatID string, msg *waE2E.Message) (string, error)

// RealEngineSend holds the injectable collaborators for Send, kept separate from
// RealEngine's SQLite/pairing fields so the logic can be unit-tested without a
// real whatsmeow client.
type sendDeps struct {
	fetchThumbnail func(url string) ([]byte, error)
	sendMessage    sendFunc
	warnLog        func(format string, args ...any)
}

// sendWithDeps is the pure send logic extracted so it can be shared by both
// RealEngineStub (tests) and RealEngine (production).
func sendWithDeps(deps sendDeps, req SendRequest) (string, error) {
	thumb, err := deps.fetchThumbnail(req.Preview.Image)
	if err != nil {
		deps.warnLog("thumbnail fetch failed for %s: %v; sending without thumbnail", req.Preview.Image, err)
		thumb = nil
	}

	msg := BuildExtendedTextMessage(req.Text, req.Preview, thumb)

	id, err := deps.sendMessage(req.ChatID, msg)
	if err != nil {
		return "", fmt.Errorf("send message: %w", err)
	}
	return id, nil
}

// Send implements Engine.Send on RealEngine using the live whatsmeow client.
func (e *RealEngine) Send(req SendRequest) (string, error) {
	deps := sendDeps{
		fetchThumbnail: FetchThumbnail,
		sendMessage: func(chatID string, msg *waE2E.Message) (string, error) {
			jid, err := types.ParseJID(chatID)
			if err != nil {
				return "", fmt.Errorf("parse JID %q: %w", chatID, err)
			}
			resp, err := e.client.SendMessage(context.Background(), jid, msg)
			if err != nil {
				return "", err
			}
			return resp.ID, nil
		},
		warnLog: defaultWarnLog,
	}
	return sendWithDeps(deps, req)
}

// ---------- HTTP handler ----------

// sendRequestBody is the JSON shape for POST /send.
type sendRequestBody struct {
	ChatID  string `json:"chatId"`
	Text    string `json:"text"`
	Preview struct {
		URL   string `json:"url"`
		Title string `json:"title"`
		Image string `json:"image"`
	} `json:"preview"`
}

func handleSend(e Engine) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		var body sendRequestBody
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
			http.Error(w, "bad request: "+err.Error(), http.StatusBadRequest)
			return
		}

		req := SendRequest{
			ChatID: body.ChatID,
			Text:   body.Text,
			Preview: PreviewMeta{
				URL:   body.Preview.URL,
				Title: body.Preview.Title,
				Image: body.Preview.Image,
			},
		}

		id, err := e.Send(req)
		if err != nil {
			http.Error(w, "send failed: "+err.Error(), http.StatusInternalServerError)
			return
		}

		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(struct {
			ID string `json:"id"`
		}{ID: id})
	}
}
