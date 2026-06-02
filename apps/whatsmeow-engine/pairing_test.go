package engine_test

import (
	"testing"

	engine "github.com/surdu/deal-promoter/apps/whatsmeow-engine"
	"go.mau.fi/whatsmeow"
)

// A "code" QR item is held and drives Status to SCAN_QR_CODE while not logged in.
func TestApplyQRItem_CodeHeldAndScanState(t *testing.T) {
	e := newEngine(t)

	engine.ApplyQRItemForTest(e, whatsmeow.QRChannelItem{
		Event: "code",
		Code:  "2@first-code",
	})

	if got, ok := e.QRImage(); !ok || got == nil {
		t.Fatalf("after code item, QRImage ok=%v bytes=%d, want a PNG", ok, len(got))
	}
	if got := e.Status(); got != "SCAN_QR_CODE" {
		t.Errorf("after code item, Status = %q, want SCAN_QR_CODE", got)
	}
}

// A later "code" item replaces the held code with the latest one.
func TestApplyQRItem_LatestCodeWins(t *testing.T) {
	e := newEngine(t)

	engine.ApplyQRItemForTest(e, whatsmeow.QRChannelItem{Event: "code", Code: "2@first"})
	engine.ApplyQRItemForTest(e, whatsmeow.QRChannelItem{Event: "code", Code: "2@second"})

	a, _ := engine.RenderQRPNG("2@second")
	got, ok := e.QRImage()
	if !ok {
		t.Fatal("QRImage ok=false, want true")
	}
	if string(got) != string(a) {
		t.Error("held QR is not the latest code")
	}
}

// A "success" terminal item clears the held QR (the scan happened; the live
// Connected/WORKING path takes over). Status leaves SCAN_QR_CODE.
func TestApplyQRItem_SuccessClearsHeldQR(t *testing.T) {
	e := newEngine(t)

	engine.ApplyQRItemForTest(e, whatsmeow.QRChannelItem{Event: "code", Code: "2@code"})
	engine.ApplyQRItemForTest(e, whatsmeow.QRChannelItem{Event: "success"})

	if _, ok := e.QRImage(); ok {
		t.Error("after success item, QRImage ok=true, want false (held QR cleared)")
	}
	if got := e.Status(); got == "SCAN_QR_CODE" {
		t.Error("after success item, Status still SCAN_QR_CODE, want it to leave scan state")
	}
}

// A "timeout" terminal item also clears the held QR.
func TestApplyQRItem_TimeoutClearsHeldQR(t *testing.T) {
	e := newEngine(t)

	engine.ApplyQRItemForTest(e, whatsmeow.QRChannelItem{Event: "code", Code: "2@code"})
	engine.ApplyQRItemForTest(e, whatsmeow.QRChannelItem{Event: "timeout"})

	if _, ok := e.QRImage(); ok {
		t.Error("after timeout item, QRImage ok=true, want false (held QR cleared)")
	}
}
