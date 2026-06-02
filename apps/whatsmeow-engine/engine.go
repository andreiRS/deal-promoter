package engine

// ConnState models the whatsmeow connection state as our own type
// so the pure mapper can be unit-tested without a real whatsmeow dependency.
type ConnState int

const (
	ConnStateStopped  ConnState = iota // no device / logged out
	ConnStateStarting                  // handshake in progress
	ConnStateScanQR                    // waiting for QR scan
	ConnStateWorking                   // connected and authenticated
	ConnStateFailed                    // connect error
)

// MapConnState maps our connection state to the five status words
// the PHP gateway expects.
func MapConnState(s ConnState) string {
	switch s {
	case ConnStateStarting:
		return "STARTING"
	case ConnStateScanQR:
		return "SCAN_QR_CODE"
	case ConnStateWorking:
		return "WORKING"
	case ConnStateFailed:
		return "FAILED"
	default:
		return "STOPPED"
	}
}

// Channel represents a WhatsApp newsletter channel.
type Channel struct {
	ID   string
	Name string
	Role string
}

// SendRequest is the payload for sending a message with an optional preview.
type SendRequest struct {
	ChatID  string
	Text    string
	Preview PreviewMeta
}

// PreviewMeta holds the link-preview fields for a send request.
type PreviewMeta struct {
	URL   string
	Title string
	Image string
}

// Engine is the interface all HTTP handlers depend on.
// Slice 1 wires a fake; slices 3-6 replace it with the real whatsmeow client.
type Engine interface {
	Status() string      // one of the five status words
	StartPairing() error // begin first-time pairing (no stored device)
	Logout() error
	QRImage() ([]byte, bool) // PNG bytes, ok=false when no QR available
	Channels() ([]Channel, error)
	Send(req SendRequest) (string, error) // returns message id
}
