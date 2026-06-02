package engine

// FakeEngine is an in-memory Engine for the dev/boot server and tests.
// Real implementations (slice 3+) replace it.
type FakeEngine struct {
	State     ConnState
	SendID    string // returned by Send
	SendError error  // returned by Send if non-nil
}

func (f *FakeEngine) Status() string {
	return MapConnState(f.State)
}

func (f *FakeEngine) StartPairing() error {
	f.State = ConnStateScanQR
	return nil
}

func (f *FakeEngine) Logout() error {
	f.State = ConnStateStopped
	return nil
}

func (f *FakeEngine) QRImage() ([]byte, bool) {
	return nil, false
}

func (f *FakeEngine) Channels() ([]Channel, error) {
	return nil, nil
}

func (f *FakeEngine) Send(req SendRequest) (string, error) {
	return f.SendID, f.SendError
}
