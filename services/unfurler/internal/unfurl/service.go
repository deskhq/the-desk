// Package unfurl turns URLs into link previews: the batch fan-out, and the
// parser that reads what came back.
package unfurl

import (
	"context"
	"log/slog"
	"sync"
	"time"

	"github.com/deskhq/the-desk/services/unfurler/internal/fetch"
	"github.com/deskhq/the-desk/services/unfurler/internal/preview"
)

// Fetcher reads the bytes at a vetted URL. fetch.Fetcher is the production
// implementation; the interface is here so the fan-out can be tested without a
// network.
type Fetcher interface {
	Fetch(ctx context.Context, url string) (*fetch.Response, preview.Reason)
}

// perURLTimeout bounds one URL's whole redirect walk, so three hops of five
// seconds each cannot hold a batch for fifteen.
const perURLTimeout = 8 * time.Second

// Service unfurls batches of URLs.
type Service struct {
	fetcher   Fetcher
	logger    *slog.Logger
	semaphore chan struct{}
}

// New builds a service that runs at most maxConcurrency fetches at once across
// every request it is handling.
//
// The cap is global rather than per-batch on purpose. A batch is at most three
// URLs, but the queue worker can have several unfurl jobs in flight at once
// across retries and restarts, and nothing else would bound the sockets that
// opens.
func New(fetcher Fetcher, logger *slog.Logger, maxConcurrency int) *Service {
	if maxConcurrency < 1 {
		maxConcurrency = 1
	}

	return &Service{
		fetcher:   fetcher,
		logger:    logger,
		semaphore: make(chan struct{}, maxConcurrency),
	}
}

// Unfurl resolves every URL, concurrently, and returns one result per input in
// the order they were given.
//
// The concurrency is the point: the PHP this replaces walked the same three URLs
// one after another, so a message linking three slow hosts cost the sum of their
// timeouts rather than the longest of them.
func (s *Service) Unfurl(ctx context.Context, urls []string) []preview.Result {
	results := make([]preview.Result, len(urls))

	var wg sync.WaitGroup

	for i, url := range urls {
		wg.Add(1)

		go func() {
			defer wg.Done()

			// Checked before the select rather than as a case in it: with a
			// free semaphore slot and a done context both ready, select picks
			// between them at random, so half the time an abandoned batch would
			// go on to fetch anyway.
			if ctx.Err() != nil {
				results[i] = preview.Failed(url, preview.ReasonTimeout)

				return
			}

			// A plain WaitGroup rather than an errgroup: one URL failing is not
			// a reason to abandon its siblings, and every failure is already a
			// result rather than an error.
			select {
			case s.semaphore <- struct{}{}:
				defer func() { <-s.semaphore }()
			case <-ctx.Done():
				results[i] = preview.Failed(url, preview.ReasonTimeout)

				return
			}

			results[i] = s.one(ctx, url)
		}()
	}

	wg.Wait()

	return results
}

// one resolves a single URL to its preview, or names why it has none.
func (s *Service) one(ctx context.Context, url string) preview.Result {
	ctx, cancel := context.WithTimeout(ctx, perURLTimeout)
	defer cancel()

	response, reason := s.fetcher.Fetch(ctx, url)
	if reason != "" {
		s.log(url, reason)

		return preview.Failed(url, reason)
	}

	defer func() { _ = response.Body.Close() }()

	result, ok := parse(response.Body, response.ContentType, response.URL)
	if !ok {
		s.log(url, preview.ReasonNoTitle)

		return preview.Failed(url, preview.ReasonNoTitle)
	}

	return preview.OK(url, result)
}

// log records a refusal by host, never by URL.
//
// The URL is text a member typed into a message, and these lines go into the
// operator's aggregated container logs. The host is enough to debug with and is
// not the private half. App\Support\Http\BlockedEgress already sets this
// convention on the PHP side.
func (s *Service) log(url string, reason preview.Reason) {
	if s.logger == nil {
		return
	}

	s.logger.Debug("no preview", "host", hostOf(url), "reason", string(reason))
}
