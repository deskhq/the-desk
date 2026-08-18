// Command unfurler serves link previews for The Desk.
//
// It exists because unfurling is the one thing the application does entirely on
// a member's say-so, and two of the defences that needs cannot be built in PHP:
// bounding a response while reading it rather than after the HTTP client has
// buffered it, and checking the destination address on the connect path rather
// than asking curl to honour a pin. See dev-docs/adr/0016.
package main

import (
	"context"
	"errors"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/deskhq/the-desk/services/unfurler/internal/config"
	"github.com/deskhq/the-desk/services/unfurler/internal/fetch"
	"github.com/deskhq/the-desk/services/unfurler/internal/guard"
	"github.com/deskhq/the-desk/services/unfurler/internal/server"
	"github.com/deskhq/the-desk/services/unfurler/internal/unfurl"
)

// shutdownGrace is how long in-flight batches get to finish once SIGTERM
// arrives. Comfortably longer than one batch's own budget.
const shutdownGrace = 15 * time.Second

func main() {
	if err := run(); err != nil {
		slog.Error("unfurler stopped", "error", err)
		os.Exit(1)
	}
}

func run() error {
	cfg, err := config.Load()
	if err != nil {
		return err
	}

	logger := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: level(cfg.LogLevel)}))

	srv := server.New(
		unfurl.New(fetch.New(guard.New(cfg.GuardEnabled)), logger, cfg.MaxConcurrency),
		cfg.Token,
		logger,
	)

	httpServer := &http.Server{
		Addr:    cfg.Listen,
		Handler: srv.Handler(),
		// Bounded so a client that opens a connection and never finishes its
		// headers cannot hold one open indefinitely.
		ReadHeaderTimeout: 5 * time.Second,
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer stop()

	listening := make(chan error, 1)

	go func() {
		logger.Info("listening", "addr", cfg.Listen, "guard", cfg.GuardEnabled, "concurrency", cfg.MaxConcurrency)

		if err := httpServer.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			listening <- err
		}

		close(listening)
	}()

	select {
	case err := <-listening:
		return err
	case <-ctx.Done():
	}

	// Report unhealthy first, so an orchestrator stops routing work here before
	// the listener closes under a request already on its way.
	srv.Drain()
	logger.Info("draining")

	shutdownCtx, cancel := context.WithTimeout(context.Background(), shutdownGrace)
	defer cancel()

	return httpServer.Shutdown(shutdownCtx)
}

func level(name string) slog.Level {
	switch name {
	case "debug":
		return slog.LevelDebug
	case "warn":
		return slog.LevelWarn
	case "error":
		return slog.LevelError
	default:
		return slog.LevelInfo
	}
}
