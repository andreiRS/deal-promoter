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
	return mux
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
