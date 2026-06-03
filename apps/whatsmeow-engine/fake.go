package engine

// FakeEngine is an in-memory Engine for the dev/boot server and tests.
// Real implementations (slice 3+) replace it.
type FakeEngine struct {
	State             ConnState
	SendID            string    // returned by Send
	SendError         error     // returned by Send if non-nil
	StartPairingError error     // returned by StartPairing if non-nil
	LogoutError       error     // returned by Logout if non-nil
	QRImageBytes      []byte    // PNG bytes returned by QRImage
	QRImageOK         bool      // ok flag returned by QRImage
	ChannelsList      []Channel // returned by Channels
	ChannelsError     error     // returned by Channels if non-nil

	LastSendRequest SendRequest // captures the last request passed to Send
}

func (f *FakeEngine) Status() string {
	return MapConnState(f.State)
}

func (f *FakeEngine) StartPairing() error {
	if f.StartPairingError != nil {
		return f.StartPairingError
	}
	f.State = ConnStateScanQR
	return nil
}

func (f *FakeEngine) Logout() error {
	if f.LogoutError != nil {
		return f.LogoutError
	}
	f.State = ConnStateStopped
	return nil
}

func (f *FakeEngine) QRImage() ([]byte, bool) {
	return f.QRImageBytes, f.QRImageOK
}

func (f *FakeEngine) Channels() ([]Channel, error) {
	return f.ChannelsList, f.ChannelsError
}

func (f *FakeEngine) Send(req SendRequest) (string, error) {
	f.LastSendRequest = req
	return f.SendID, f.SendError
}
