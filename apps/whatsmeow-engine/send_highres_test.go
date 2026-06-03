package engine

import (
	"bytes"
	"errors"
	"testing"
)

// buildUploadedHighRes drives the injected uploadThumbnail seam and maps its
// result (plus the resized dims) onto the high-res message. These tests exercise
// the seam with a stub, so no live whatsmeow client is needed.

func TestBuildUploadedHighRes_UsesUploadSeamAndMapsResult(t *testing.T) {
	var gotJPEG []byte
	deps := sendDeps{
		uploadThumbnail: func(jpeg []byte) (uploadResult, error) {
			gotJPEG = jpeg
			return uploadResult{DirectPath: "/dp", SHA256: []byte{0x01, 0x02}, Handle: "h-1"}, nil
		},
		warnLog: defaultWarnLog,
	}
	req := SendRequest{
		Text:    "Deal text",
		Preview: PreviewMeta{URL: "https://ex.com", Title: "Prod"},
	}
	highRes := []byte{0xAA, 0xBB}
	inline := []byte{0xCC, 0xDD}

	msg, handle, err := buildUploadedHighRes(deps, req, inline, highRes, 800, 600)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if !bytes.Equal(gotJPEG, highRes) {
		t.Errorf("upload seam got %v, want the high-res jpeg %v", gotJPEG, highRes)
	}
	if handle != "h-1" {
		t.Errorf("returned handle = %q, want %q", handle, "h-1")
	}

	ext := msg.GetExtendedTextMessage()
	if got := ext.GetThumbnailDirectPath(); got != "/dp" {
		t.Errorf("ThumbnailDirectPath = %q, want %q", got, "/dp")
	}
	if !bytes.Equal(ext.GetThumbnailSHA256(), []byte{0x01, 0x02}) {
		t.Errorf("ThumbnailSHA256 = %v, want %v", ext.GetThumbnailSHA256(), []byte{0x01, 0x02})
	}
	if got := ext.GetThumbnailWidth(); got != 800 {
		t.Errorf("ThumbnailWidth = %d, want 800", got)
	}
	if got := ext.GetThumbnailHeight(); got != 600 {
		t.Errorf("ThumbnailHeight = %d, want 600", got)
	}
	if !bytes.Equal(ext.GetJPEGThumbnail(), inline) {
		t.Errorf("JPEGThumbnail = %v, want inline fallback %v", ext.GetJPEGThumbnail(), inline)
	}
}

func TestBuildUploadedHighRes_UploadError_Propagates(t *testing.T) {
	deps := sendDeps{
		uploadThumbnail: func(jpeg []byte) (uploadResult, error) {
			return uploadResult{}, errors.New("upload boom")
		},
		warnLog: defaultWarnLog,
	}

	_, _, err := buildUploadedHighRes(deps, SendRequest{}, nil, []byte{0x01}, 100, 100)
	if err == nil {
		t.Fatal("expected the upload error to propagate, got nil")
	}
}
