package engine

import (
	"errors"

	qrcode "github.com/skip2/go-qrcode"
)

// qrPNGSize is the QR PNG side length in pixels (square image).
const qrPNGSize = 256

// errEmptyQR is returned by RenderQRPNG when there is no QR content to render.
var errEmptyQR = errors.New("empty QR content")

// RenderQRPNG renders a WhatsApp pairing QR string to PNG bytes. It is pure
// (string -> bytes) so it can be unit-tested without any whatsmeow client.
// It returns an error for empty content rather than emitting a meaningless code.
func RenderQRPNG(content string) ([]byte, error) {
	if content == "" {
		return nil, errEmptyQR
	}
	return qrcode.Encode(content, qrcode.Medium, qrPNGSize)
}
