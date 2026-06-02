package engine_test

import (
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"testing"

	engine "github.com/surdu/deal-promoter/apps/whatsmeow-engine"
)

func TestChannelsHandler_ReturnsJSONArrayWithIDNameRole(t *testing.T) {
	fake := &engine.FakeEngine{
		ChannelsList: []engine.Channel{
			{ID: "111@newsletter", Name: "Deals", Role: "OWNER"},
		},
	}
	srv := engine.NewServer(fake)

	req := httptest.NewRequest(http.MethodGet, "/channels", nil)
	rec := httptest.NewRecorder()

	srv.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Errorf("GET /channels: got %d, want 200", rec.Code)
	}
	if ct := rec.Header().Get("Content-Type"); ct != "application/json" {
		t.Errorf("GET /channels Content-Type = %q, want application/json", ct)
	}
	var got []map[string]string
	if err := json.NewDecoder(rec.Body).Decode(&got); err != nil {
		t.Fatalf("decode channels response: %v", err)
	}
	if len(got) != 1 {
		t.Fatalf("expected 1 channel, got %d", len(got))
	}
	if got[0]["id"] != "111@newsletter" {
		t.Errorf("id = %q, want %q", got[0]["id"], "111@newsletter")
	}
	if got[0]["name"] != "Deals" {
		t.Errorf("name = %q, want %q", got[0]["name"], "Deals")
	}
	if got[0]["role"] != "OWNER" {
		t.Errorf("role = %q, want %q", got[0]["role"], "OWNER")
	}
}

func TestChannelsHandler_EmptyList_ReturnsEmptyJSONArray(t *testing.T) {
	fake := &engine.FakeEngine{ChannelsList: []engine.Channel{}}
	srv := engine.NewServer(fake)

	req := httptest.NewRequest(http.MethodGet, "/channels", nil)
	rec := httptest.NewRecorder()

	srv.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Errorf("GET /channels (empty): got %d, want 200", rec.Code)
	}
	var got []map[string]string
	if err := json.NewDecoder(rec.Body).Decode(&got); err != nil {
		t.Fatalf("decode channels response: %v", err)
	}
	if got == nil || len(got) != 0 {
		t.Errorf("expected empty array [], got %v", got)
	}
}

func TestChannelsHandler_EngineError_Returns500(t *testing.T) {
	fake := &engine.FakeEngine{ChannelsError: errors.New("rpc failure")}
	srv := engine.NewServer(fake)

	req := httptest.NewRequest(http.MethodGet, "/channels", nil)
	rec := httptest.NewRecorder()

	srv.ServeHTTP(rec, req)

	if rec.Code != http.StatusInternalServerError {
		t.Errorf("GET /channels error: got %d, want 500", rec.Code)
	}
}
