package engine_test

import (
	"testing"

	engine "github.com/surdu/deal-promoter/apps/whatsmeow-engine"
)

func TestMapConnState_AllStates(t *testing.T) {
	cases := []struct {
		state engine.ConnState
		want  string
	}{
		{engine.ConnStateStopped, "STOPPED"},
		{engine.ConnStateStarting, "STARTING"},
		{engine.ConnStateScanQR, "SCAN_QR_CODE"},
		{engine.ConnStateWorking, "WORKING"},
		{engine.ConnStateFailed, "FAILED"},
	}
	for _, tc := range cases {
		got := engine.MapConnState(tc.state)
		if got != tc.want {
			t.Errorf("MapConnState(%v) = %q, want %q", tc.state, got, tc.want)
		}
	}
}
