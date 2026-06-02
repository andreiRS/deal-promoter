package engine

// RealEngineStub is a thin wrapper around sendDeps used exclusively in tests
// (via NewRealEngineWithStubs). It exercises the same Send logic path without
// requiring a live whatsmeow connection. Living in a _test.go file keeps it out
// of the production package API.
type RealEngineStub struct {
	deps sendDeps
}

// NewRealEngineWithStubs constructs a RealEngineStub with injected fetcher and
// sender stubs. Intended only for unit tests.
func NewRealEngineWithStubs(
	fetcher func(url string) ([]byte, error),
	sender sendFunc,
) *RealEngineStub {
	return &RealEngineStub{
		deps: sendDeps{
			fetchThumbnail: fetcher,
			sendMessage:    sender,
			warnLog:        defaultWarnLog,
		},
	}
}

// SetWarnLogger replaces the warn logging function (for test assertions).
func (s *RealEngineStub) SetWarnLogger(fn func(format string, args ...any)) {
	s.deps.warnLog = fn
}

// Send implements the send-with-degrade logic using injected stubs.
func (s *RealEngineStub) Send(req SendRequest) (string, error) {
	return sendWithDeps(s.deps, req)
}
