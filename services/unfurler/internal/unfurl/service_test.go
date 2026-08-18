package unfurl

import (
	"context"
	"io"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/deskhq/the-desk/services/unfurler/internal/fetch"
	"github.com/deskhq/the-desk/services/unfurler/internal/preview"
)

// fakeFetcher answers from a table, so the fan-out can be exercised without a
// network. What is asserted here is the batch contract — one result per input,
// in order, concurrently — not what a fetch does, which is fetch's own suite.
type fakeFetcher struct {
	bodies  map[string]string
	reasons map[string]preview.Reason
	delay   time.Duration

	mu       sync.Mutex
	inFlight int
	peak     int
	calls    atomic.Int32
}

func (f *fakeFetcher) Fetch(ctx context.Context, url string) (*fetch.Response, preview.Reason) {
	f.calls.Add(1)

	f.mu.Lock()
	f.inFlight++
	if f.inFlight > f.peak {
		f.peak = f.inFlight
	}
	f.mu.Unlock()

	defer func() {
		f.mu.Lock()
		f.inFlight--
		f.mu.Unlock()
	}()

	if f.delay > 0 {
		select {
		case <-time.After(f.delay):
		case <-ctx.Done():
			return nil, preview.ReasonTimeout
		}
	}

	if reason, ok := f.reasons[url]; ok {
		return nil, reason
	}

	body, ok := f.bodies[url]
	if !ok {
		return nil, preview.ReasonTransportError
	}

	return &fetch.Response{
		URL:         url,
		ContentType: "text/html",
		Body:        io.NopCloser(strings.NewReader(body)),
	}, ""
}

func document(title string) string {
	return `<html><head><title>` + title + `</title></head></html>`
}

func TestUnfurlReturnsOneResultPerUrlInOrder(t *testing.T) {
	fetcher := &fakeFetcher{
		bodies:  map[string]string{"https://a.test": document("A"), "https://c.test": document("C")},
		reasons: map[string]preview.Reason{"https://b.test": preview.ReasonBlockedAddress},
	}

	urls := []string{"https://a.test", "https://b.test", "https://c.test"}

	results := New(fetcher, nil, 16).Unfurl(t.Context(), urls)

	if len(results) != len(urls) {
		t.Fatalf("got %d results for %d urls", len(results), len(urls))
	}

	for i, url := range urls {
		if results[i].URL != url {
			t.Fatalf("result %d is for %q, want %q — the order is the caller's index", i, results[i].URL, url)
		}
	}

	if results[0].Status != preview.StatusOK || results[0].Preview.Title != "A" {
		t.Fatalf("first result = %+v", results[0])
	}

	if results[1].Status != preview.StatusFailed || results[1].Reason != preview.ReasonBlockedAddress {
		t.Fatalf("second result = %+v", results[1])
	}

	if results[1].Preview != nil {
		t.Fatal("a failed result must carry no preview")
	}

	if results[2].Preview.Title != "C" {
		t.Fatalf("third result = %+v", results[2])
	}
}

// The reason the service exists. Three slow URLs cost the longest, not the sum,
// which is exactly what the serial PHP loop could not do.
func TestUnfurlFetchesConcurrently(t *testing.T) {
	fetcher := &fakeFetcher{
		bodies: map[string]string{
			"https://a.test": document("A"),
			"https://b.test": document("B"),
			"https://c.test": document("C"),
		},
		delay: 120 * time.Millisecond,
	}

	started := time.Now()

	results := New(fetcher, nil, 16).Unfurl(t.Context(),
		[]string{"https://a.test", "https://b.test", "https://c.test"})

	elapsed := time.Since(started)

	for i, r := range results {
		if r.Status != preview.StatusOK {
			t.Fatalf("result %d failed: %+v", i, r)
		}
	}

	// Serial would be 360ms. Generous headroom so a loaded runner does not
	// fabricate a failure, while still being far below the serial cost.
	if elapsed > 300*time.Millisecond {
		t.Fatalf("three 120ms fetches took %s, which is the serial cost", elapsed)
	}

	if fetcher.peak < 2 {
		t.Fatalf("peak concurrency was %d, so nothing overlapped", fetcher.peak)
	}
}

// The global cap is what stops several in-flight jobs opening unbounded sockets.
func TestUnfurlHonoursTheConcurrencyCap(t *testing.T) {
	fetcher := &fakeFetcher{
		bodies: map[string]string{
			"https://a.test": document("A"),
			"https://b.test": document("B"),
			"https://c.test": document("C"),
		},
		delay: 40 * time.Millisecond,
	}

	New(fetcher, nil, 1).Unfurl(t.Context(),
		[]string{"https://a.test", "https://b.test", "https://c.test"})

	if fetcher.peak != 1 {
		t.Fatalf("peak concurrency was %d with a cap of 1", fetcher.peak)
	}
}

func TestNewFloorsTheConcurrencyCapAtOne(t *testing.T) {
	fetcher := &fakeFetcher{bodies: map[string]string{"https://a.test": document("A")}}

	results := New(fetcher, nil, 0).Unfurl(t.Context(), []string{"https://a.test"})

	if results[0].Status != preview.StatusOK {
		t.Fatalf("a cap of zero deadlocked or failed: %+v", results[0])
	}
}

// A document that fetched fine but holds no title is a failure with its own
// reason, not a preview with an empty name.
func TestUnfurlReportsADocumentWithNothingWorthShowing(t *testing.T) {
	fetcher := &fakeFetcher{bodies: map[string]string{"https://a.test": `<html><head></head></html>`}}

	results := New(fetcher, nil, 16).Unfurl(t.Context(), []string{"https://a.test"})

	if results[0].Status != preview.StatusFailed || results[0].Reason != preview.ReasonNoTitle {
		t.Fatalf("result = %+v", results[0])
	}
}

func TestUnfurlHandlesAnEmptyBatch(t *testing.T) {
	if got := New(&fakeFetcher{}, nil, 16).Unfurl(t.Context(), nil); len(got) != 0 {
		t.Fatalf("got %d results for no urls", len(got))
	}
}

// A cancelled context must not leave a caller waiting on a semaphore slot that
// will never free.
func TestUnfurlGivesUpWhenTheContextIsAlreadyDone(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	cancel()

	fetcher := &fakeFetcher{bodies: map[string]string{"https://a.test": document("A")}}

	results := New(fetcher, nil, 1).Unfurl(ctx, []string{"https://a.test"})

	if results[0].Status != preview.StatusFailed {
		t.Fatalf("result = %+v", results[0])
	}
}
