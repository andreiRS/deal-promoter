package engine_test

import (
	"errors"
	"os"
	"path/filepath"
	"testing"

	engine "github.com/surdu/deal-promoter/apps/whatsmeow-engine"
)

func TestNewRealEngine_NoDevice_StatusStopped(t *testing.T) {
	path := filepath.Join(t.TempDir(), "store.db")

	e, err := engine.NewRealEngine(path)
	if err != nil {
		t.Fatalf("NewRealEngine: %v", err)
	}
	t.Cleanup(func() { _ = e.Close() })

	// Real type must satisfy the Engine interface.
	var _ engine.Engine = e

	if got := e.Status(); got != "STOPPED" {
		t.Errorf("Status() with no stored device = %q, want STOPPED", got)
	}
}

func TestNewRealEngine_StoreOpensAndPersistsAcrossRestarts(t *testing.T) {
	path := filepath.Join(t.TempDir(), "store.db")

	// First boot: store opens cleanly and the DB file is created.
	e1, err := engine.NewRealEngine(path)
	if err != nil {
		t.Fatalf("first NewRealEngine: %v", err)
	}
	if _, err := os.Stat(path); err != nil {
		t.Fatalf("store file not created at %s: %v", path, err)
	}
	if err := e1.Close(); err != nil {
		t.Fatalf("Close: %v", err)
	}

	// Reopen the same path: the persisted store opens cleanly (the testable
	// core of "persists across restarts" without a live device).
	e2, err := engine.NewRealEngine(path)
	if err != nil {
		t.Fatalf("reopen NewRealEngine: %v", err)
	}
	t.Cleanup(func() { _ = e2.Close() })
}

func TestShouldConnectOnBoot(t *testing.T) {
	if !engine.ShouldConnectOnBoot(true) {
		t.Errorf("ShouldConnectOnBoot(hasStoredDevice=true) = false, want true")
	}
	if engine.ShouldConnectOnBoot(false) {
		t.Errorf("ShouldConnectOnBoot(hasStoredDevice=false) = true, want false")
	}
}

func TestLiveConnState_AllCombinations(t *testing.T) {
	errConn := errors.New("connect failed")

	cases := []struct {
		name       string
		connected  bool
		loggedIn   bool
		connecting bool
		lastErr    error
		want       engine.ConnState
	}{
		// Connected + authenticated wins over everything else.
		{"connected and logged in", true, true, false, nil, engine.ConnStateWorking},
		{"connected and logged in despite stale connecting flag", true, true, true, nil, engine.ConnStateWorking},
		{"connected and logged in despite stale error", true, true, false, errConn, engine.ConnStateWorking},

		// Mid-handshake: a connect is in progress but not yet authenticated.
		{"connecting, not yet connected", false, false, true, nil, engine.ConnStateStarting},
		{"connected socket but not yet logged in (handshake)", true, false, true, nil, engine.ConnStateStarting},
		{"connected socket, not logged in, no connecting flag", true, false, false, nil, engine.ConnStateStarting},

		// Connect error with no live connection.
		{"connect error, not connected", false, false, false, errConn, engine.ConnStateFailed},
		{"connect error while still connecting", false, false, true, errConn, engine.ConnStateFailed},

		// No device / logged out / idle.
		{"idle, nothing happening", false, false, false, nil, engine.ConnStateStopped},
		{"logged in flag but socket down, no error", false, true, false, nil, engine.ConnStateStopped},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got := engine.LiveConnState(tc.connected, tc.loggedIn, tc.connecting, tc.lastErr)
			if got != tc.want {
				t.Errorf("LiveConnState(%v,%v,%v,err=%v) = %v, want %v",
					tc.connected, tc.loggedIn, tc.connecting, tc.lastErr, got, tc.want)
			}
		})
	}
}
