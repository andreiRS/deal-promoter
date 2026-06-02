package engine_test

import (
	"bytes"
	"image/png"
	"path/filepath"
	"testing"

	engine "github.com/surdu/deal-promoter/apps/whatsmeow-engine"
)

// newEngine builds a RealEngine on a throwaway store (no device, STOPPED).
func newEngine(t *testing.T) *engine.RealEngine {
	t.Helper()
	e, err := engine.NewRealEngine(filepath.Join(t.TempDir(), "store.db"))
	if err != nil {
		t.Fatalf("NewRealEngine: %v", err)
	}
	t.Cleanup(func() { _ = e.Close() })
	return e
}

func TestQRImage_NoHeldQR_NotOK(t *testing.T) {
	e := newEngine(t)

	if got, ok := e.QRImage(); ok || got != nil {
		t.Errorf("QRImage with no held QR = (%d bytes, ok=%v), want (nil, false)", len(got), ok)
	}
}

func TestQRImage_HeldQR_ReturnsScannablePNG(t *testing.T) {
	e := newEngine(t)
	engine.SetHeldQRForTest(e, "2@some-pairing-code")

	got, ok := e.QRImage()
	if !ok {
		t.Fatal("QRImage with a held QR = ok false, want true")
	}
	img, err := png.Decode(bytes.NewReader(got))
	if err != nil {
		t.Fatalf("QRImage bytes are not a valid PNG: %v", err)
	}
	if img.Bounds().Dx() <= 0 || img.Bounds().Dy() <= 0 {
		t.Fatal("QRImage PNG has non-positive dimensions")
	}
}

func TestQRImage_ClearedQR_NotOK(t *testing.T) {
	e := newEngine(t)
	engine.SetHeldQRForTest(e, "2@some-pairing-code")
	engine.SetHeldQRForTest(e, "") // clear

	if _, ok := e.QRImage(); ok {
		t.Error("QRImage after clearing held QR = ok true, want false")
	}
}
