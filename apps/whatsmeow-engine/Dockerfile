# Build image for the whatsmeow engine. The module is self-contained (no monorepo
# path deps) and cgo-free (modernc.org/sqlite is pure Go), so it compiles to a
# static binary and runs on a minimal alpine image. Build context is this dir.
FROM golang:1.26 AS build

WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY . .
# CGO off → fully static binary that needs no libc at runtime.
RUN CGO_ENABLED=0 go build -o /out/whatsmeow-engine ./cmd/whatsmeow-engine

FROM alpine:3.20
# busybox wget powers the compose healthcheck against /health.
RUN apk add --no-cache wget ca-certificates
COPY --from=build /out/whatsmeow-engine /usr/local/bin/whatsmeow-engine

# SQLite session store lives on a volume mounted here (see compose).
ENV WHATSMEOW_STORE_PATH=/data/store.db
ENV PORT=8080
EXPOSE 8080

CMD ["whatsmeow-engine"]
