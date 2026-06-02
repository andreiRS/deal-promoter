package engine_test

import (
	"errors"
	"net/http"
	"net/http/httptest"
	"testing"

	engine "github.com/surdu/deal-promoter/apps/whatsmeow-engine"
)

func TestSessionStartHandler_Success_Returns200(t *testing.T) {
	fake := &engine.FakeEngine{}
	srv := engine.NewServer(fake)

	req := httptest.NewRequest(http.MethodPost, "/session/start", nil)
	rec := httptest.NewRecorder()

	srv.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Errorf("POST /session/start: got %d, want 200", rec.Code)
	}
}

func TestSessionStartHandler_EngineError_Returns500(t *testing.T) {
	fake := &engine.FakeEngine{StartPairingError: errors.New("already paired")}
	srv := engine.NewServer(fake)

	req := httptest.NewRequest(http.MethodPost, "/session/start", nil)
	rec := httptest.NewRecorder()

	srv.ServeHTTP(rec, req)

	if rec.Code != http.StatusInternalServerError {
		t.Errorf("POST /session/start error: got %d, want 500", rec.Code)
	}
}

func TestSessionLogoutHandler_Success_Returns200(t *testing.T) {
	fake := &engine.FakeEngine{}
	srv := engine.NewServer(fake)

	req := httptest.NewRequest(http.MethodPost, "/session/logout", nil)
	rec := httptest.NewRecorder()

	srv.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Errorf("POST /session/logout: got %d, want 200", rec.Code)
	}
}

func TestSessionLogoutHandler_EngineError_Returns500(t *testing.T) {
	fake := &engine.FakeEngine{LogoutError: errors.New("logout failed")}
	srv := engine.NewServer(fake)

	req := httptest.NewRequest(http.MethodPost, "/session/logout", nil)
	rec := httptest.NewRecorder()

	srv.ServeHTTP(rec, req)

	if rec.Code != http.StatusInternalServerError {
		t.Errorf("POST /session/logout error: got %d, want 500", rec.Code)
	}
}
