package engine_test

import (
	"bytes"
	"image/png"
	"testing"

	engine "github.com/surdu/deal-promoter/apps/whatsmeow-engine"
)

func TestRenderQRPNG_ValidNonEmptyPNG(t *testing.T) {
	png1, err := engine.RenderQRPNG("2@abc,def,ghi==")
	if err != nil {
		t.Fatalf("RenderQRPNG: %v", err)
	}
	if len(png1) == 0 {
		t.Fatal("RenderQRPNG returned empty bytes")
	}

	img, err := png.Decode(bytes.NewReader(png1))
	if err != nil {
		t.Fatalf("decode rendered bytes as PNG: %v", err)
	}
	b := img.Bounds()
	if b.Dx() <= 0 || b.Dy() <= 0 {
		t.Fatalf("rendered PNG has non-positive dimensions: %dx%d", b.Dx(), b.Dy())
	}
}

func TestRenderQRPNG_Deterministic(t *testing.T) {
	a, err := engine.RenderQRPNG("same-content")
	if err != nil {
		t.Fatalf("first render: %v", err)
	}
	b, err := engine.RenderQRPNG("same-content")
	if err != nil {
		t.Fatalf("second render: %v", err)
	}
	if !bytes.Equal(a, b) {
		t.Error("RenderQRPNG is not deterministic for identical content")
	}
}

func TestRenderQRPNG_EmptyContentErrors(t *testing.T) {
	if _, err := engine.RenderQRPNG(""); err == nil {
		t.Error("RenderQRPNG(\"\") = nil error, want error for empty content")
	}
}
