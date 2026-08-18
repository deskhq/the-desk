// Package server is the service's HTTP surface: one authenticated batch
// endpoint and one unauthenticated health probe.
package server

import (
	"context"
	"crypto/hmac"
	"encoding/json"
	"log/slog"
	"net/http"
	"strings"
	"sync/atomic"
	"time"

	"github.com/deskhq/the-desk/services/unfurler/internal/preview"
)

// maxURLsPerBatch mirrors App\Actions\Channels\SyncLinkPreviews::MAX_LINKS.
//
// The caller already caps what it sends; this refuses a larger batch outright
// rather than trusting it, because "how many URLs may one request fan out to" is
// this service's own resource question and not the caller's to answer.
const maxURLsPerBatch = 3

// maxBodyBytes bounds the request body. A batch of three URLs is a few hundred
// bytes; this is generous and still finite.
const maxBodyBytes = 64 << 10

// Unfurler resolves a batch of URLs. unfurl.Service is the production
// implementation.
type Unfurler interface {
	Unfurl(ctx context.Context, urls []string) []preview.Result
}

type batchRequest struct {
	URLs []string `json:"urls"`
}

type batchResponse struct {
	Results []preview.Result `json:"results"`
}

type errorResponse struct {
	Error string `json:"error"`
}

// Server is the HTTP handler plus the draining flag its health probe reports.
type Server struct {
	unfurler Unfurler
	token    string
	logger   *slog.Logger
	draining atomic.Bool
}

// New builds the HTTP surface.
func New(unfurler Unfurler, token string, logger *slog.Logger) *Server {
	return &Server{unfurler: unfurler, token: token, logger: logger}
}

// Handler returns the routes.
func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()

	// The only unauthenticated route. It carries no information about the
	// service beyond whether it is taking work.
	mux.HandleFunc("GET /healthz", s.health)
	mux.Handle("POST /unfurl", s.authenticated(http.HandlerFunc(s.unfurl)))

	return mux
}

// Drain flips the health probe to unhealthy so an orchestrator stops sending
// work before the server stops accepting it.
func (s *Server) Drain() { s.draining.Store(true) }

func (s *Server) health(w http.ResponseWriter, _ *http.Request) {
	if s.draining.Load() {
		writeJSON(w, http.StatusServiceUnavailable, map[string]string{"status": "draining"})

		return
	}

	writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

// authenticated rejects anything not carrying the shared secret.
//
// The service is reachable from every container on the compose network and
// publishes no host port, so this is the second of two controls rather than the
// only one — but it is the one that stops another container on that network
// using this as an unauthenticated fetcher.
func (s *Server) authenticated(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		presented := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")

		// Constant time, so a wrong token cannot be found a byte at a time.
		if !hmac.Equal([]byte(presented), []byte(s.token)) {
			writeJSON(w, http.StatusUnauthorized, errorResponse{Error: "unauthorized"})

			return
		}

		next.ServeHTTP(w, r)
	})
}

func (s *Server) unfurl(w http.ResponseWriter, r *http.Request) {
	started := time.Now()

	var request batchRequest

	if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, maxBodyBytes)).Decode(&request); err != nil {
		writeJSON(w, http.StatusBadRequest, errorResponse{Error: "malformed_request"})

		return
	}

	if len(request.URLs) == 0 {
		writeJSON(w, http.StatusOK, batchResponse{Results: []preview.Result{}})

		return
	}

	if len(request.URLs) > maxURLsPerBatch {
		writeJSON(w, http.StatusBadRequest, errorResponse{Error: "too_many_urls"})

		return
	}

	results := s.unfurler.Unfurl(r.Context(), request.URLs)

	ok := 0

	for _, result := range results {
		if result.Status == preview.StatusOK {
			ok++
		}
	}

	if s.logger != nil {
		s.logger.Info("unfurled",
			"request_id", r.Header.Get("X-Request-Id"),
			"urls", len(request.URLs),
			"previews", ok,
			"duration_ms", time.Since(started).Milliseconds(),
		)
	}

	writeJSON(w, http.StatusOK, batchResponse{Results: results})
}

func writeJSON(w http.ResponseWriter, status int, body any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)

	_ = json.NewEncoder(w).Encode(body)
}
