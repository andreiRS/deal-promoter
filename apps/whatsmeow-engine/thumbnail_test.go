package engine

import (
	"bytes"
	"errors"
	"image"
	"image/color"
	"image/jpeg"
	"image/png"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
)

// makeJPEG returns a JPEG-encoded image of the given dimensions.
func makeJPEG(t *testing.T, w, h int) []byte {
	t.Helper()
	img := image.NewRGBA(image.Rect(0, 0, w, h))
	for y := 0; y < h; y++ {
		for x := 0; x < w; x++ {
			img.Set(x, y, color.RGBA{R: 200, G: 100, B: 50, A: 255})
		}
	}
	var buf bytes.Buffer
	if err := jpeg.Encode(&buf, img, nil); err != nil {
		t.Fatalf("makeJPEG: %v", err)
	}
	return buf.Bytes()
}

// makePNG returns a PNG-encoded image of the given dimensions.
func makePNG(t *testing.T, w, h int) []byte {
	t.Helper()
	img := image.NewRGBA(image.Rect(0, 0, w, h))
	for y := 0; y < h; y++ {
		for x := 0; x < w; x++ {
			img.Set(x, y, color.RGBA{R: 50, G: 100, B: 200, A: 255})
		}
	}
	var buf bytes.Buffer
	if err := png.Encode(&buf, img); err != nil {
		t.Fatalf("makePNG: %v", err)
	}
	return buf.Bytes()
}

// --- Behavior 1: TransformThumbnail resizes to ~256px longest side ---

func TestTransformThumbnail_JPEG_ResizesLongestSide(t *testing.T) {
	// 800x400 JPEG — longest side is 800, should become ~256; short side ~128
	src := makeJPEG(t, 800, 400)
	got, err := TransformThumbnail(bytes.NewReader(src))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	img, _, err := image.Decode(bytes.NewReader(got))
	if err != nil {
		t.Fatalf("output is not a valid image: %v", err)
	}
	b := img.Bounds()
	w, h := b.Max.X, b.Max.Y
	if w != 256 {
		t.Errorf("expected width 256, got %d", w)
	}
	// aspect: 800/400 = 2:1, so height should be 128
	if h != 128 {
		t.Errorf("expected height 128 (aspect preserved), got %d", h)
	}
}

func TestTransformThumbnail_PNG_ResizesLongestSide(t *testing.T) {
	// 400x800 PNG — longest side is 800 (height), should become ~256; short side ~128
	src := makePNG(t, 400, 800)
	got, err := TransformThumbnail(bytes.NewReader(src))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	img, _, err := image.Decode(bytes.NewReader(got))
	if err != nil {
		t.Fatalf("output is not a valid image: %v", err)
	}
	b := img.Bounds()
	w, h := b.Max.X, b.Max.Y
	// aspect: 400/800 = 1:2, longest side (h) => 256; width => 128
	if h != 256 {
		t.Errorf("expected height 256, got %d", h)
	}
	if w != 128 {
		t.Errorf("expected width 128 (aspect preserved), got %d", w)
	}
}

func TestTransformThumbnail_AlreadySmall_NotUpscaled(t *testing.T) {
	// 100x50 — smaller than 256, should stay 100x50 (no upscale)
	src := makeJPEG(t, 100, 50)
	got, err := TransformThumbnail(bytes.NewReader(src))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	img, _, err := image.Decode(bytes.NewReader(got))
	if err != nil {
		t.Fatalf("output is not a valid image: %v", err)
	}
	b := img.Bounds()
	w, h := b.Max.X, b.Max.Y
	if w != 100 || h != 50 {
		t.Errorf("expected 100x50 (no upscale), got %dx%d", w, h)
	}
}

func TestTransformThumbnail_InvalidBytes_ReturnsError(t *testing.T) {
	_, err := TransformThumbnail(bytes.NewReader([]byte("not an image")))
	if err == nil {
		t.Fatal("expected error for invalid image bytes, got nil")
	}
	var te *ThumbnailError
	if !errors.As(err, &te) {
		t.Errorf("expected ThumbnailError, got %T: %v", err, err)
	}
}

// --- High-res transform: resizes to 800px longest side, returns actual dims ---

func TestTransformHighResThumbnail_ResizesLongestSideTo800(t *testing.T) {
	// 1200x600 JPEG — longest side 1200 -> 800; short side -> 400.
	src := makeJPEG(t, 1200, 600)
	got, w, h, err := TransformHighResThumbnail(bytes.NewReader(src))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if w != 800 {
		t.Errorf("returned width = %d, want 800", w)
	}
	if h != 400 {
		t.Errorf("returned height = %d, want 400 (aspect preserved)", h)
	}
	img, _, err := image.Decode(bytes.NewReader(got))
	if err != nil {
		t.Fatalf("output is not a valid image: %v", err)
	}
	b := img.Bounds()
	if b.Max.X != 800 || b.Max.Y != 400 {
		t.Errorf("decoded image = %dx%d, want 800x400", b.Max.X, b.Max.Y)
	}
}

func TestTransformHighResThumbnail_SquareSource_SquareResult(t *testing.T) {
	// Amazon images are square -> a square large card.
	src := makeJPEG(t, 1000, 1000)
	_, w, h, err := TransformHighResThumbnail(bytes.NewReader(src))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if w != 800 || h != 800 {
		t.Errorf("square source produced %dx%d, want 800x800", w, h)
	}
}

func TestTransformHighResThumbnail_AlreadySmall_NotUpscaled(t *testing.T) {
	src := makeJPEG(t, 300, 150)
	_, w, h, err := TransformHighResThumbnail(bytes.NewReader(src))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if w != 300 || h != 150 {
		t.Errorf("small source produced %dx%d, want 300x150 (no upscale)", w, h)
	}
}

// --- Behavior 2: FetchThumbnail sends browser-like User-Agent ---

func TestFetchThumbnail_SendsBrowserUserAgent(t *testing.T) {
	var gotUA string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotUA = r.Header.Get("User-Agent")
		w.Header().Set("Content-Type", "image/jpeg")
		src := makeJPEG(t, 400, 300)
		w.Write(src)
	}))
	defer srv.Close()

	_, err := FetchThumbnail(srv.URL)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if gotUA == "" || gotUA == "Go-http-client/1.1" {
		t.Errorf("expected browser-like UA, got %q", gotUA)
	}
}

// --- Behavior 3: FetchThumbnail enforces source-size cap ---

func TestFetchThumbnail_RejectsOversizedBody(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "image/jpeg")
		// write more than the cap (10 MB should be well over)
		w.Write(bytes.Repeat([]byte("x"), 11*1024*1024))
	}))
	defer srv.Close()

	_, err := FetchThumbnail(srv.URL)
	if err == nil {
		t.Fatal("expected error for oversized body, got nil")
	}
	var te *ThumbnailError
	if !errors.As(err, &te) {
		t.Errorf("expected ThumbnailError, got %T: %v", err, err)
	}
}

// --- Behavior 4: FetchThumbnail rejects non-image content-type ---

func TestFetchThumbnail_RejectsNonImageContentType(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/html")
		io.WriteString(w, "<html>not an image</html>")
	}))
	defer srv.Close()

	_, err := FetchThumbnail(srv.URL)
	if err == nil {
		t.Fatal("expected error for non-image content-type, got nil")
	}
	var te *ThumbnailError
	if !errors.As(err, &te) {
		t.Errorf("expected ThumbnailError, got %T: %v", err, err)
	}
}

// --- Behavior 5: FetchThumbnail returns typed error on fetch failure ---

func TestFetchThumbnail_FetchFailure_ReturnsTypedError(t *testing.T) {
	// Use a closed server to simulate a connection failure
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {}))
	url := srv.URL
	srv.Close()

	_, err := FetchThumbnail(url)
	if err == nil {
		t.Fatal("expected error for unreachable server, got nil")
	}
	var te *ThumbnailError
	if !errors.As(err, &te) {
		t.Errorf("expected ThumbnailError, got %T: %v", err, err)
	}
}

// --- Behavior 6: FetchThumbnail end-to-end: fetches, decodes, resizes, re-encodes ---

func TestFetchThumbnail_EndToEnd_ReturnsResizedJPEG(t *testing.T) {
	src := makeJPEG(t, 800, 600)
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "image/jpeg")
		w.Write(src)
	}))
	defer srv.Close()

	got, err := FetchThumbnail(srv.URL)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	img, _, err := image.Decode(bytes.NewReader(got))
	if err != nil {
		t.Fatalf("output is not valid JPEG: %v", err)
	}
	b := img.Bounds()
	w, h := b.Max.X, b.Max.Y
	// 800x600 -> longest side 800 -> 256; height -> 192
	if w != 256 {
		t.Errorf("expected width 256, got %d", w)
	}
	if h != 192 {
		t.Errorf("expected height 192 (aspect preserved), got %d", h)
	}
}
