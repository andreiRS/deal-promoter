package engine_test

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	engine "github.com/surdu/deal-promoter/apps/whatsmeow-engine"
)

func TestHealthEndpoint_Returns200(t *testing.T) {
	srv := engine.NewServer(&engine.FakeEngine{State: engine.ConnStateStopped})
	req := httptest.NewRequest(http.MethodGet, "/health", nil)
	rec := httptest.NewRecorder()

	srv.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Errorf("GET /health: got %d, want 200", rec.Code)
	}
}

// connStateFromWord is a test helper that maps a status word back to ConnState.
func connStateFromWord(word string) engine.ConnState {
	switch word {
	case "STARTING":
		return engine.ConnStateStarting
	case "SCAN_QR_CODE":
		return engine.ConnStateScanQR
	case "WORKING":
		return engine.ConnStateWorking
	case "FAILED":
		return engine.ConnStateFailed
	default:
		return engine.ConnStateStopped
	}
}

func TestSessionEndpoint_ReturnsStatusJSON(t *testing.T) {
	cases := []string{"STOPPED", "STARTING", "SCAN_QR_CODE", "WORKING", "FAILED"}
	for _, want := range cases {
		state := connStateFromWord(want)
		srv := engine.NewServer(&engine.FakeEngine{State: state})
		req := httptest.NewRequest(http.MethodGet, "/session", nil)
		rec := httptest.NewRecorder()

		srv.ServeHTTP(rec, req)

		if rec.Code != http.StatusOK {
			t.Errorf("GET /session status=%s: got HTTP %d, want 200", want, rec.Code)
		}
		gotBody := strings.TrimSpace(rec.Body.String())
		wantJSON := `{"status":"` + want + `"}`
		if gotBody != wantJSON {
			t.Errorf("GET /session status=%s: body = %q, want %q", want, gotBody, wantJSON)
		}
		gotCT := rec.Header().Get("Content-Type")
		if gotCT != "application/json" {
			t.Errorf("GET /session: Content-Type = %q, want application/json", gotCT)
		}
	}
}
