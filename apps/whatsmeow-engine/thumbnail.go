package engine

import (
	"bytes"
	"fmt"
	"image"
	"image/jpeg"
	_ "image/png" // register PNG decoder
	"io"
	"net/http"
	"strings"
	"time"

	"golang.org/x/image/draw"
)

const (
	thumbnailMaxSide = 256
	highResMaxSide   = 800
	thumbnailQuality = 80
	fetchSizeCap     = 5 * 1024 * 1024 // 5 MB
	fetchTimeout     = 10 * time.Second
	fetchUserAgent   = "Mozilla/5.0 (compatible; deal-promoter/1.0)"
)

// ThumbnailError is the typed error returned by the thumbnail pipeline.
type ThumbnailError struct {
	Reason string
	Cause  error
}

func (e *ThumbnailError) Error() string {
	if e.Cause != nil {
		return fmt.Sprintf("thumbnail: %s: %v", e.Reason, e.Cause)
	}
	return fmt.Sprintf("thumbnail: %s", e.Reason)
}

func (e *ThumbnailError) Unwrap() error { return e.Cause }

func thumbErr(reason string, cause error) *ThumbnailError {
	return &ThumbnailError{Reason: reason, Cause: cause}
}

// TransformThumbnail decodes a JPEG or PNG from r, resizes the longest side to
// thumbnailMaxSide (preserving aspect ratio, no upscaling), and re-encodes as
// JPEG at thumbnailQuality. This is the small inline thumbnail.
func TransformThumbnail(r io.Reader) ([]byte, error) {
	b, _, _, err := transformToMaxSide(r, thumbnailMaxSide)
	return b, err
}

// TransformHighResThumbnail decodes a JPEG or PNG from r, resizes the longest
// side to highResMaxSide (preserving aspect ratio, no upscaling), re-encodes as
// JPEG at thumbnailQuality, and returns the bytes plus the actual resized
// dimensions. The dimensions are declared on the message so WhatsApp draws the
// large card at the right size.
func TransformHighResThumbnail(r io.Reader) ([]byte, int, int, error) {
	return transformToMaxSide(r, highResMaxSide)
}

// transformToMaxSide decodes, resizes the longest side to maxSide (aspect
// preserved, never upscaled), and re-encodes as JPEG. It returns the bytes and
// the actual resized dimensions, shared by both the inline and high-res paths.
func transformToMaxSide(r io.Reader, maxSide int) ([]byte, int, int, error) {
	src, _, err := image.Decode(r)
	if err != nil {
		return nil, 0, 0, thumbErr("decode failed", err)
	}

	bounds := src.Bounds()
	w := bounds.Max.X - bounds.Min.X
	h := bounds.Max.Y - bounds.Min.Y

	newW, newH := resizeDims(w, h, maxSide)

	var dst draw.Image
	if newW == w && newH == h {
		// already within bounds — just re-encode
		dst = image.NewRGBA(bounds)
		draw.Copy(dst, image.Point{}, src, bounds, draw.Src, nil)
	} else {
		dstBounds := image.Rect(0, 0, newW, newH)
		dstImg := image.NewRGBA(dstBounds)
		draw.BiLinear.Scale(dstImg, dstBounds, src, bounds, draw.Src, nil)
		dst = dstImg
	}

	var buf bytes.Buffer
	if err := jpeg.Encode(&buf, dst, &jpeg.Options{Quality: thumbnailQuality}); err != nil {
		return nil, 0, 0, thumbErr("encode failed", err)
	}
	return buf.Bytes(), newW, newH, nil
}

// resizeDims calculates the new width and height such that the longest side is
// at most maxSide, preserving aspect ratio. Returns original dims if already small enough.
func resizeDims(w, h, maxSide int) (int, int) {
	if w <= maxSide && h <= maxSide {
		return w, h
	}
	if w >= h {
		// width is longest side
		newH := h * maxSide / w
		return maxSide, newH
	}
	// height is longest side
	newW := w * maxSide / h
	return newW, maxSide
}

// FetchImageBytes fetches the raw image at imageURL, enforces the size cap, and
// checks the content-type, returning the undecoded source bytes. The high-res
// path fetches the source once and derives both thumbnails from these bytes.
// It returns a *ThumbnailError on any failure so callers can degrade gracefully.
func FetchImageBytes(imageURL string) ([]byte, error) {
	client := &http.Client{Timeout: fetchTimeout}

	req, err := http.NewRequest(http.MethodGet, imageURL, nil)
	if err != nil {
		return nil, thumbErr("build request failed", err)
	}
	req.Header.Set("User-Agent", fetchUserAgent)

	resp, err := client.Do(req)
	if err != nil {
		return nil, thumbErr("fetch failed", err)
	}
	defer resp.Body.Close()

	ct := resp.Header.Get("Content-Type")
	if !strings.HasPrefix(ct, "image/") {
		return nil, thumbErr(fmt.Sprintf("non-image content-type: %s", ct), nil)
	}

	// Read up to the cap + 1 byte to detect oversized bodies.
	limited := io.LimitReader(resp.Body, int64(fetchSizeCap+1))
	data, err := io.ReadAll(limited)
	if err != nil {
		return nil, thumbErr("read body failed", err)
	}
	if len(data) > fetchSizeCap {
		return nil, thumbErr(fmt.Sprintf("image exceeds size cap (%d bytes)", fetchSizeCap), nil)
	}

	return data, nil
}

// FetchThumbnail fetches the image at imageURL and returns the resized inline
// JPEG thumbnail bytes. It returns a *ThumbnailError on any failure so callers
// can degrade gracefully.
func FetchThumbnail(imageURL string) ([]byte, error) {
	data, err := FetchImageBytes(imageURL)
	if err != nil {
		return nil, err
	}
	return TransformThumbnail(bytes.NewReader(data))
}
