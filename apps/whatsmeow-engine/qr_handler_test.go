package engine_test

import (
	"bytes"
	"net/http"
	"net/http/httptest"
	"testing"

	engine "github.com/surdu/deal-promoter/apps/whatsmeow-engine"
)

func TestQRHandler_QRAvailable_Returns200WithPNGBytes(t *testing.T) {
	pngBytes := []byte{0x89, 0x50, 0x4E, 0x47} // minimal PNG header
	fake := &engine.FakeEngine{QRImageBytes: pngBytes, QRImageOK: true}
	srv := engine.NewServer(fake)

	req := httptest.NewRequest(http.MethodGet, "/qr", nil)
	rec := httptest.NewRecorder()

	srv.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Errorf("GET /qr: got %d, want 200", rec.Code)
	}
	if ct := rec.Header().Get("Content-Type"); ct != "image/png" {
		t.Errorf("GET /qr Content-Type = %q, want image/png", ct)
	}
	if !bytes.Equal(rec.Body.Bytes(), pngBytes) {
		t.Errorf("GET /qr body = %v, want %v", rec.Body.Bytes(), pngBytes)
	}
}

func TestQRHandler_QRNotAvailable_Returns404(t *testing.T) {
	fake := &engine.FakeEngine{QRImageOK: false}
	srv := engine.NewServer(fake)

	req := httptest.NewRequest(http.MethodGet, "/qr", nil)
	rec := httptest.NewRecorder()

	srv.ServeHTTP(rec, req)

	if rec.Code != http.StatusNotFound {
		t.Errorf("GET /qr (unavailable): got %d, want 404", rec.Code)
	}
}
