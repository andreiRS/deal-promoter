package engine

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"log"
	"net/http"

	"go.mau.fi/whatsmeow"
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

// HighResThumbnail carries an uploaded high-res thumbnail's reference fields
// onto the message. DirectPath/SHA256 come from the UploadNewsletter response;
// Width/Height are the actual resized dimensions; Handle is carried through to
// SendRequestExtra.MediaHandle on send (slice 2). Newsletter media is
// unencrypted, so there is no MediaKey/EncSHA256 by design.
type HighResThumbnail struct {
	DirectPath string
	SHA256     []byte
	Width      int
	Height     int
	Handle     string
}

// BuildHighResExtendedTextMessage builds the same card as
// BuildExtendedTextMessage but references an uploaded high-res thumbnail (which
// drives WhatsApp's large card) while keeping the inline JPEGThumbnail as a
// fallback. PreviewType stays IMAGE, matching the low-res path.
func BuildHighResExtendedTextMessage(text string, preview PreviewMeta, inlineThumb []byte, hr HighResThumbnail) *waE2E.Message {
	msg := BuildExtendedTextMessage(text, preview, inlineThumb)
	ext := msg.GetExtendedTextMessage()
	ext.ThumbnailDirectPath = proto.String(hr.DirectPath)
	ext.ThumbnailSHA256 = hr.SHA256
	ext.ThumbnailWidth = proto.Uint32(uint32(hr.Width))
	ext.ThumbnailHeight = proto.Uint32(uint32(hr.Height))
	return msg
}

// sendFunc is the injectable function signature for actually dispatching a
// message to WhatsApp. In production this wraps client.SendMessage; in tests it
// is replaced by a stub so no network call is made. mediaHandle is the upload
// handle for a high-res thumbnail; the low-res path passes "" (no handle).
type sendFunc func(chatID string, msg *waE2E.Message, mediaHandle string) (string, error)

// uploadResult is the subset of whatsmeow.UploadResponse the high-res path uses.
// Newsletter media is unencrypted, so MediaKey/FileEncSHA256 are empty by design.
type uploadResult struct {
	DirectPath string
	SHA256     []byte
	Handle     string
}

// uploadFunc is the injectable seam for uploading a high-res thumbnail to
// WhatsApp's media servers. In production it wraps client.UploadNewsletter; in
// tests it is replaced by a stub so no network call is made.
type uploadFunc func(jpeg []byte) (uploadResult, error)

// buildUploadedHighRes uploads the high-res JPEG via the injected seam and maps
// the result (with the actual resized dims) onto a high-res message, keeping the
// inline bytes as the fallback. It returns the message and the upload handle to
// carry through SendRequestExtra.MediaHandle on send.
func buildUploadedHighRes(deps sendDeps, req SendRequest, inline, highResJPEG []byte, w, h int) (*waE2E.Message, string, error) {
	res, err := deps.uploadThumbnail(highResJPEG)
	if err != nil {
		return nil, "", err
	}
	hr := HighResThumbnail{
		DirectPath: res.DirectPath,
		SHA256:     res.SHA256,
		Width:      w,
		Height:     h,
		Handle:     res.Handle,
	}
	return BuildHighResExtendedTextMessage(req.Text, req.Preview, inline, hr), res.Handle, nil
}

// RealEngineSend holds the injectable collaborators for Send, kept separate from
// RealEngine's SQLite/pairing fields so the logic can be unit-tested without a
// real whatsmeow client.
type sendDeps struct {
	fetchThumbnail  func(url string) ([]byte, error)
	fetchSource     func(url string) ([]byte, error)
	uploadThumbnail uploadFunc
	sendMessage     sendFunc
	warnLog         func(format string, args ...any)
}

// sendWithDeps is the pure send logic extracted so it can be shared by both
// RealEngineStub (tests) and RealEngine (production). On the opt-in high-res
// path it produces a large uploaded thumbnail; otherwise it keeps today's
// inline-only behaviour byte-for-byte.
func sendWithDeps(deps sendDeps, req SendRequest) (string, error) {
	if req.Preview.HighRes {
		return sendHighRes(deps, req)
	}

	thumb, err := deps.fetchThumbnail(req.Preview.Image)
	if err != nil {
		deps.warnLog("thumbnail fetch failed for %s: %v; sending without thumbnail", req.Preview.Image, err)
		thumb = nil
	}

	msg := BuildExtendedTextMessage(req.Text, req.Preview, thumb)
	return dispatch(deps, req.ChatID, msg, "")
}

// sendHighRes fetches the source once, derives the inline 256px fallback and the
// 800px uploaded thumbnail, references the uploaded one on the message, and sends
// with its media handle.
func sendHighRes(deps sendDeps, req SendRequest) (string, error) {
	source, err := deps.fetchSource(req.Preview.Image)
	if err != nil {
		// Fetch failed: post the card with no image at all.
		deps.warnLog("source fetch failed for %s: %v; sending without image", req.Preview.Image, err)
		msg := BuildExtendedTextMessage(req.Text, req.Preview, nil)
		return dispatch(deps, req.ChatID, msg, "")
	}

	inline, err := TransformThumbnail(bytes.NewReader(source))
	if err != nil {
		// The source decoded for neither size: post with no image.
		deps.warnLog("inline thumbnail transform failed for %s: %v; sending without image", req.Preview.Image, err)
		msg := BuildExtendedTextMessage(req.Text, req.Preview, nil)
		return dispatch(deps, req.ChatID, msg, "")
	}

	highRes, w, h, err := TransformHighResThumbnail(bytes.NewReader(source))
	if err == nil {
		var msg *waE2E.Message
		var handle string
		msg, handle, err = buildUploadedHighRes(deps, req, inline, highRes, w, h)
		if err == nil {
			return dispatch(deps, req.ChatID, msg, handle)
		}
	}

	// High-res transform or upload failed: fall back to the small inline card,
	// still post. (Inline already succeeded above.)
	deps.warnLog("high-res thumbnail failed for %s: %v; sending inline card", req.Preview.Image, err)
	inlineMsg := BuildExtendedTextMessage(req.Text, req.Preview, inline)
	return dispatch(deps, req.ChatID, inlineMsg, "")
}

// dispatch hands the built message to the send seam, wrapping the error.
func dispatch(deps sendDeps, chatID string, msg *waE2E.Message, mediaHandle string) (string, error) {
	id, err := deps.sendMessage(chatID, msg, mediaHandle)
	if err != nil {
		return "", fmt.Errorf("send message: %w", err)
	}
	return id, nil
}

// Send implements Engine.Send on RealEngine using the live whatsmeow client.
func (e *RealEngine) Send(req SendRequest) (string, error) {
	deps := sendDeps{
		fetchThumbnail: FetchThumbnail,
		fetchSource:    FetchImageBytes,
		uploadThumbnail: func(jpeg []byte) (uploadResult, error) {
			resp, err := e.client.UploadNewsletter(context.Background(), jpeg, whatsmeow.MediaLinkThumbnail)
			if err != nil {
				return uploadResult{}, err
			}
			return uploadResult{
				DirectPath: resp.DirectPath,
				SHA256:     resp.FileSHA256,
				Handle:     resp.Handle,
			}, nil
		},
		sendMessage: func(chatID string, msg *waE2E.Message, mediaHandle string) (string, error) {
			jid, err := types.ParseJID(chatID)
			if err != nil {
				return "", fmt.Errorf("parse JID %q: %w", chatID, err)
			}
			var resp whatsmeow.SendResponse
			if mediaHandle != "" {
				resp, err = e.client.SendMessage(context.Background(), jid, msg, whatsmeow.SendRequestExtra{MediaHandle: mediaHandle})
			} else {
				resp, err = e.client.SendMessage(context.Background(), jid, msg)
			}
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
		URL     string `json:"url"`
		Title   string `json:"title"`
		Image   string `json:"image"`
		HighRes bool   `json:"highRes"`
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
				URL:     body.Preview.URL,
				Title:   body.Preview.Title,
				Image:   body.Preview.Image,
				HighRes: body.Preview.HighRes,
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
