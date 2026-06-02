package engine

import (
	"context"
	"errors"
	"sync"

	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/store"
	"go.mau.fi/whatsmeow/store/sqlstore"
	"go.mau.fi/whatsmeow/types/events"
	"google.golang.org/protobuf/proto"

	_ "modernc.org/sqlite" // pure-Go SQLite driver, registers driver name "sqlite"
)

// companionDisplayName is shown in WhatsApp's linked-devices list.
const companionDisplayName = "Deal Promoter"

// sqliteDSN builds a SQLite DSN for the pure-Go driver with foreign keys on.
func sqliteDSN(path string) string {
	return "file:" + path + "?_pragma=foreign_keys(1)"
}

// errNotImplemented is returned by Engine methods whose slices land later.
var errNotImplemented = errors.New("not implemented")

// ShouldConnectOnBoot decides whether the engine connects on startup: it
// connects only when a device is already stored (a previous pairing). With no
// stored device it stays disconnected until the operator pairs (slice 4).
func ShouldConnectOnBoot(hasStoredDevice bool) bool {
	return hasStoredDevice
}

// LiveConnState maps a live whatsmeow client's observable state onto our
// ConnState enum. It is pure so the heart of Status() can be unit-tested
// without a real WhatsApp connection.
//
//   - connected && loggedIn          -> WORKING (authenticated wins always)
//   - pendingQR && !loggedIn         -> SCAN_QR_CODE (a code is awaiting a scan)
//   - connecting / socket-up-only    -> STARTING (handshake / post-scan)
//   - a connect error, no socket     -> FAILED
//   - otherwise (idle/logged out)    -> STOPPED
//
// pendingQR is true while StartPairing holds a QR string that has not yet been
// scanned; once the scan completes the held code is cleared, so the same
// handshake state then derives STARTING until the LoggedOut/Connected events
// settle into WORKING.
func LiveConnState(connected, loggedIn, connecting, pendingQR bool, lastErr error) ConnState {
	switch {
	case connected && loggedIn:
		return ConnStateWorking
	case pendingQR:
		return ConnStateScanQR
	case connected:
		return ConnStateStarting
	case lastErr != nil:
		return ConnStateFailed
	case connecting:
		return ConnStateStarting
	default:
		return ConnStateStopped
	}
}

// RealEngine is the whatsmeow-backed Engine. Slice 3 implements the
// store/boot-connect/status foundation; pairing, channels and send land in
// later slices and currently return errNotImplemented.
type RealEngine struct {
	container *sqlstore.Container
	client    *whatsmeow.Client

	mu         sync.Mutex
	connecting bool
	lastErr    error
	qrCode     string // latest unscanned QR string; empty when none is pending
}

// NewRealEngine opens the SQLite store at the given path, constructs a
// whatsmeow client with auto-reconnect enabled, and connects on boot when a
// device is already stored. With no stored device it stays disconnected.
func NewRealEngine(storePath string) (*RealEngine, error) {
	ctx := context.Background()

	store.DeviceProps.Os = proto.String(companionDisplayName)

	container, err := sqlstore.New(ctx, "sqlite", sqliteDSN(storePath), nil)
	if err != nil {
		return nil, err
	}

	device, err := container.GetFirstDevice(ctx)
	if err != nil {
		return nil, err
	}

	client := whatsmeow.NewClient(device, nil)
	client.EnableAutoReconnect = true

	e := &RealEngine{container: container, client: client}
	client.AddEventHandler(e.onEvent)

	if ShouldConnectOnBoot(device.ID != nil) {
		e.mu.Lock()
		e.connecting = true
		e.mu.Unlock()
		if err := client.Connect(); err != nil {
			e.mu.Lock()
			e.connecting = false
			e.lastErr = err
			e.mu.Unlock()
		}
	}

	return e, nil
}

// onEvent updates the connecting/lastErr bookkeeping from client events so
// Status() can report STARTING/WORKING/FAILED accurately.
func (e *RealEngine) onEvent(evt any) {
	switch evt.(type) {
	case *events.Connected:
		e.mu.Lock()
		e.connecting = false
		e.lastErr = nil
		e.mu.Unlock()
	case *events.Disconnected:
		e.mu.Lock()
		// A disconnect kicks off whatsmeow's auto-reconnect, so we're
		// handshaking again rather than idle.
		e.connecting = true
		e.mu.Unlock()
	case *events.LoggedOut:
		e.mu.Lock()
		e.connecting = false
		e.lastErr = nil
		e.mu.Unlock()
	}
}

// Status derives the gateway status word from the live client state.
func (e *RealEngine) Status() string {
	e.mu.Lock()
	connecting := e.connecting
	lastErr := e.lastErr
	pendingQR := e.qrCode != ""
	e.mu.Unlock()

	return MapConnState(LiveConnState(
		e.client.IsConnected(),
		e.client.IsLoggedIn(),
		connecting,
		pendingQR,
		lastErr,
	))
}

// Close shuts down the client and the underlying store.
func (e *RealEngine) Close() error {
	if e.client != nil {
		e.client.Disconnect()
	}
	if e.container != nil {
		return e.container.Close()
	}
	return nil
}

// setQR stores or clears the latest unscanned QR string under the mutex.
// An empty string clears the held code (no pending QR).
func (e *RealEngine) setQR(code string) {
	e.mu.Lock()
	e.qrCode = code
	e.mu.Unlock()
}

// QRImage renders the currently-held QR string to a PNG. It returns
// (nil, false) when no QR string is held (e.g. already WORKING or never
// started pairing).
func (e *RealEngine) QRImage() ([]byte, bool) {
	e.mu.Lock()
	code := e.qrCode
	e.mu.Unlock()

	if code == "" {
		return nil, false
	}
	png, err := RenderQRPNG(code)
	if err != nil {
		return nil, false
	}
	return png, true
}

// applyQRItem updates the held QR from one item off the GetQRChannel stream.
// A "code" item holds the latest code (driving Status to SCAN_QR_CODE); any
// terminal item ("success", "timeout" or an error) clears the held code so the
// live Connected/LoggedOut path takes over the status.
func (e *RealEngine) applyQRItem(item whatsmeow.QRChannelItem) {
	if item.Event == whatsmeow.QRChannelEventCode {
		e.setQR(item.Code)
		return
	}
	// success / timeout / error: pairing is no longer awaiting a scan.
	e.setQR("")
}

// consumeQR drains the GetQRChannel stream, holding the latest QR until a
// terminal event closes the channel.
func (e *RealEngine) consumeQR(ch <-chan whatsmeow.QRChannelItem) {
	for item := range ch {
		e.applyQRItem(item)
	}
}

// StartPairing begins first-time pairing when there is no stored device: it
// opens the QR channel (which must happen before Connect), connects, and spawns
// a goroutine that holds the latest QR while codes flow. It refuses when the
// client is already logged in (already paired/working).
func (e *RealEngine) StartPairing() error {
	if e.client.IsLoggedIn() {
		return errors.New("already paired")
	}

	ctx := context.Background()
	ch, err := e.client.GetQRChannel(ctx)
	if err != nil {
		return err
	}

	e.mu.Lock()
	e.connecting = true
	e.lastErr = nil
	e.mu.Unlock()

	if err := e.client.Connect(); err != nil {
		e.mu.Lock()
		e.connecting = false
		e.lastErr = err
		e.mu.Unlock()
		return err
	}

	go e.consumeQR(ch)
	return nil
}

// Logout clears the device from WhatsApp servers, drops any held QR, and
// returns the engine to STOPPED.
func (e *RealEngine) Logout() error {
	err := e.client.Logout(context.Background())
	e.mu.Lock()
	e.qrCode = ""
	e.connecting = false
	e.lastErr = nil
	e.mu.Unlock()
	return err
}

func (e *RealEngine) Channels() ([]Channel, error) { return nil, errNotImplemented }
func (e *RealEngine) Send(req SendRequest) (string, error) {
	return "", errNotImplemented
}
