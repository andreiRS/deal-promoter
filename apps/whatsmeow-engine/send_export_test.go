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

// SetSourceFetcher injects the raw-source fetch seam used by the high-res path.
func (s *RealEngineStub) SetSourceFetcher(fn func(url string) ([]byte, error)) {
	s.deps.fetchSource = fn
}

// SetUploadThumbnail injects the high-res upload seam. The stub returns the
// upload's DirectPath/SHA256/Handle so the path can be tested without a live
// whatsmeow client.
func (s *RealEngineStub) SetUploadThumbnail(fn func(jpeg []byte) (UploadResult, error)) {
	s.deps.uploadThumbnail = func(jpeg []byte) (uploadResult, error) {
		r, err := fn(jpeg)
		return uploadResult(r), err
	}
}

// UploadResult is the exported mirror of uploadResult for tests that inject the
// upload seam via SetUploadThumbnail.
type UploadResult struct {
	DirectPath string
	SHA256     []byte
	Handle     string
}

// Send implements the send-with-degrade logic using injected stubs.
func (s *RealEngineStub) Send(req SendRequest) (string, error) {
	return sendWithDeps(s.deps, req)
}
