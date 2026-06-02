package engine

import (
	"encoding/json"
	"net/http"
)

// NewServer returns an http.Handler wired to the given Engine.
func NewServer(e Engine) http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/health", handleHealth)
	mux.HandleFunc("/session", handleSession(e))
	mux.HandleFunc("/session/start", handleSessionStart(e))
	mux.HandleFunc("/session/logout", handleSessionLogout(e))
	mux.HandleFunc("/qr", handleQR(e))
	mux.HandleFunc("/channels", handleChannels(e))
	mux.HandleFunc("/send", handleSend(e))
	return mux
}

func handleSessionStart(e Engine) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if err := e.StartPairing(); err != nil {
			http.Error(w, err.Error(), http.StatusInternalServerError)
			return
		}
		w.WriteHeader(http.StatusOK)
	}
}

func handleSessionLogout(e Engine) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if err := e.Logout(); err != nil {
			http.Error(w, err.Error(), http.StatusInternalServerError)
			return
		}
		w.WriteHeader(http.StatusOK)
	}
}

func handleQR(e Engine) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		png, ok := e.QRImage()
		if !ok {
			w.WriteHeader(http.StatusNotFound)
			return
		}
		w.Header().Set("Content-Type", "image/png")
		w.Write(png)
	}
}

// channelJSON is the wire shape for one channel in GET /channels.
type channelJSON struct {
	ID   string `json:"id"`
	Name string `json:"name"`
	Role string `json:"role"`
}

func handleChannels(e Engine) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		channels, err := e.Channels()
		if err != nil {
			http.Error(w, err.Error(), http.StatusInternalServerError)
			return
		}
		out := make([]channelJSON, len(channels))
		for i, ch := range channels {
			out[i] = channelJSON{ID: ch.ID, Name: ch.Name, Role: ch.Role}
		}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(out)
	}
}

func handleHealth(w http.ResponseWriter, r *http.Request) {
	w.WriteHeader(http.StatusOK)
}

func handleSession(e Engine) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(struct {
			Status string `json:"status"`
		}{Status: e.Status()})
	}
}
