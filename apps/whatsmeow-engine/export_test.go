package engine

import "go.mau.fi/whatsmeow"

// SetHeldQRForTest sets or clears the engine's held QR string. It is a test-only
// seam so QRImage() gating can be driven without faking a live WhatsApp
// connection. An empty string clears the held code.
func SetHeldQRForTest(e *RealEngine, code string) {
	e.setQR(code)
}

// ApplyQRItemForTest feeds one GetQRChannel item to the engine's consumer logic,
// so the held-QR/status effects of the QR stream can be unit-tested without a
// live socket or a faked phone scan.
func ApplyQRItemForTest(e *RealEngine, item whatsmeow.QRChannelItem) {
	e.applyQRItem(item)
}
