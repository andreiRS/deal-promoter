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

	storePath := os.Getenv("WHATSMEOW_STORE_PATH")
	if storePath == "" {
		storePath = "store.db"
	}

	eng, err := engine.NewRealEngine(storePath)
	if err != nil {
		log.Fatalf("engine init error: %v", err)
	}
	srv := engine.NewServer(eng)

	log.Printf("whatsmeow-engine listening on :%s", port)
	if err := http.ListenAndServe(":"+port, srv); err != nil {
		log.Fatalf("server error: %v", err)
	}
}
