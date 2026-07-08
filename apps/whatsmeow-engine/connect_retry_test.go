package engine_test

import (
	"errors"
	"testing"
	"time"

	engine "github.com/surdu/deal-promoter/apps/whatsmeow-engine"
)

// A failing Connect must be retried, not given up after one attempt, and the
// loop must stop as soon as Connect succeeds.
func TestRetryLoop_RetriesUntilConnectSucceeds(t *testing.T) {
	errConn := errors.New("websocket not connected")

	const failures = 3
	var (
		attempts int
		connects int
		errs     []error
		sleeps   []time.Duration
	)

	connect := func() error {
		connects++
		if connects <= failures {
			return errConn
		}
		return nil
	}
	onAttempt := func() { attempts++ }
	onErr := func(err error) { errs = append(errs, err) }
	sleep := func(d time.Duration) { sleeps = append(sleeps, d) }

	engine.RetryLoopForTest(connect, onAttempt, onErr, sleep, time.Second, 30*time.Second)

	// (a) Connect was retried past the first failure and (b) the loop stopped
	// on the first success: failures failing calls + 1 succeeding call.
	if want := failures + 1; connects != want {
		t.Errorf("connect calls = %d, want %d", connects, want)
	}
	if attempts != connects {
		t.Errorf("onAttempt calls = %d, want %d (once per connect)", attempts, connects)
	}
	if len(errs) != failures {
		t.Errorf("onErr calls = %d, want %d", len(errs), failures)
	}
	// It slept between failures (once per failure) but not after the success.
	if len(sleeps) != failures {
		t.Errorf("sleeps = %d, want %d (one per failure, none after success)", len(sleeps), failures)
	}
}

// The backoff grows exponentially and is capped so the loop never busy-spins
// nor lets the delay run away unbounded.
func TestRetryLoop_BackoffGrowsAndCaps(t *testing.T) {
	errConn := errors.New("nope")

	const failures = 8
	var (
		connects int
		sleeps   []time.Duration
	)

	connect := func() error {
		connects++
		if connects <= failures {
			return errConn
		}
		return nil
	}
	sleep := func(d time.Duration) { sleeps = append(sleeps, d) }

	engine.RetryLoopForTest(connect, func() {}, func(error) {}, sleep, time.Second, 30*time.Second)

	if len(sleeps) != failures {
		t.Fatalf("sleeps = %d, want %d", len(sleeps), failures)
	}
	// First sleep is the initial backoff.
	if sleeps[0] != time.Second {
		t.Errorf("first sleep = %v, want %v", sleeps[0], time.Second)
	}
	// Each sleep is >= the previous (monotonic non-decreasing) and never exceeds the cap.
	for i, d := range sleeps {
		if d > 30*time.Second {
			t.Errorf("sleep[%d] = %v exceeds cap 30s", i, d)
		}
		if i > 0 && d < sleeps[i-1] {
			t.Errorf("sleep[%d] = %v < previous %v; backoff must not shrink", i, d, sleeps[i-1])
		}
	}
	// It did reach the cap given enough failures.
	if last := sleeps[len(sleeps)-1]; last != 30*time.Second {
		t.Errorf("last sleep = %v, want cap 30s reached", last)
	}
}
