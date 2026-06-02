package main

import (
	"log"
	"net/http"
	"os"

	engine "github.com/surdu/deal-promoter/apps/whatsmeow-engine"
)

func main() {
	port := os.Getenv("PORT")
	if port == "" {
		port = "8080"
	}

	fake := &engine.FakeEngine{State: engine.ConnStateStopped}
	srv := engine.NewServer(fake)

	log.Printf("whatsmeow-engine listening on :%s", port)
	if err := http.ListenAndServe(":"+port, srv); err != nil {
		log.Fatalf("server error: %v", err)
	}
}
