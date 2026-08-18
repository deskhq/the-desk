package fetch

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/deskhq/the-desk/services/unfurler/internal/preview"
)

// testGuard stands in for the real one so this package's own contract can be
// exercised against an httptest server, which listens on loopback and which the
// real guard would rightly refuse to dial. What is asserted here is the walk —
// is the verdict asked on every hop, does a refusal stop it — not the verdict
// itself, which is the guard package's own suite and the shared case table.
type testGuard struct {
	refuse string
	asked  []string
}

func (g *testGuard) CheckURL(raw string) error {
	g.asked = append(g.asked, raw)

	if g.refuse != "" && strings.Contains(raw, g.refuse) {
		return errors.New("refused")
	}

	return nil
}

func (g *testGuard) Dialer(timeout time.Duration) *net.Dialer {
	return &net.Dialer{Timeout: timeout}
}

func html(body string) http.HandlerFunc {
	return func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "text/html; charset=utf-8")
		_, _ = io.WriteString(w, body)
	}
}

// t.Context() rather than a locally-cancelled one: the body is a live stream, so
// the context has to outlive this helper or reading it fails with
// `context canceled`. That is the caller's contract, not a quirk of the test.
func fetchFrom(t *testing.T, g Guard, url string) (*Response, preview.Reason) {
	t.Helper()

	return New(g).Fetch(t.Context(), url)
}

func TestFetchReturnsABoundedHtmlBody(t *testing.T) {
	server := httptest.NewServer(html(`<html><head><title>T</title></head></html>`))
	defer server.Close()

	got, reason := fetchFrom(t, &testGuard{}, server.URL)

	if reason != "" {
		t.Fatalf("reason = %q, want a document", reason)
	}
	defer got.Body.Close()

	body, _ := io.ReadAll(got.Body)

	if !strings.Contains(string(body), "<title>T</title>") {
		t.Fatalf("body = %q", body)
	}

	if got.URL != server.URL {
		t.Fatalf("url = %q, want %q", got.URL, server.URL)
	}
}

func TestFetchFollowsARedirectAndReportsWhereItLanded(t *testing.T) {
	var server *httptest.Server

	server = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/moved" {
			http.Redirect(w, r, server.URL+"/final", http.StatusFound)

			return
		}

		html(`<html><head><title>Landed</title></head></html>`)(w, r)
	}))
	defer server.Close()

	got, reason := fetchFrom(t, &testGuard{}, server.URL+"/moved")

	if reason != "" {
		t.Fatalf("reason = %q", reason)
	}
	defer got.Body.Close()

	if got.URL != server.URL+"/final" {
		t.Fatalf("url = %q, want the URL it landed on", got.URL)
	}
}

// The whole point of walking redirects by hand: every hop is re-vetted, so a
// public URL cannot bounce the fetch onto somewhere nobody checked.
func TestFetchReGuardsEveryHop(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, "http://blocked.test/internal", http.StatusFound)
	}))
	defer server.Close()

	g := &testGuard{refuse: "blocked.test"}

	got, reason := fetchFrom(t, g, server.URL)

	if got != nil {
		t.Fatal("the fetch followed a redirect onto a refused target")
	}

	if reason == "" {
		t.Fatal("expected a refusal reason")
	}

	if len(g.asked) != 2 {
		t.Fatalf("the guard was asked %d times, want once per hop: %v", len(g.asked), g.asked)
	}

	if g.asked[1] != "http://blocked.test/internal" {
		t.Fatalf("the second hop asked about %q", g.asked[1])
	}
}

func TestFetchRefusesTheFirstUrlWithoutDialling(t *testing.T) {
	g := &testGuard{refuse: "blocked.test"}

	got, reason := fetchFrom(t, g, "http://blocked.test/x")

	if got != nil || reason == "" {
		t.Fatalf("got %+v, reason %q", got, reason)
	}

	if len(g.asked) != 1 {
		t.Fatalf("the guard was asked %d times, want once", len(g.asked))
	}
}

func TestFetchResolvesARelativeLocation(t *testing.T) {
	var server *httptest.Server

	server = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/moved" {
			w.Header().Set("Location", "/final")
			w.WriteHeader(http.StatusMovedPermanently)

			return
		}

		html(`<html><head><title>Landed</title></head></html>`)(w, r)
	}))
	defer server.Close()

	got, reason := fetchFrom(t, &testGuard{}, server.URL+"/moved")

	if reason != "" {
		t.Fatalf("reason = %q", reason)
	}
	defer got.Body.Close()

	if got.URL != server.URL+"/final" {
		t.Fatalf("url = %q", got.URL)
	}
}

func TestFetchGivesUpAfterTooManyRedirects(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, r.URL.Path+"/again", http.StatusFound)
	}))
	defer server.Close()

	_, reason := fetchFrom(t, &testGuard{}, server.URL+"/loop")

	if reason != preview.ReasonTooManyRedirects {
		t.Fatalf("reason = %q, want %q", reason, preview.ReasonTooManyRedirects)
	}
}

func TestFetchRefusesARedirectWithNoLocation(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusFound)
	}))
	defer server.Close()

	_, reason := fetchFrom(t, &testGuard{}, server.URL)

	if reason != preview.ReasonBadStatus {
		t.Fatalf("reason = %q, want %q", reason, preview.ReasonBadStatus)
	}
}

func TestFetchRefusesAnErrorStatus(t *testing.T) {
	for _, status := range []int{http.StatusNotFound, http.StatusInternalServerError, http.StatusNoContent} {
		t.Run(fmt.Sprint(status), func(t *testing.T) {
			server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
				w.Header().Set("Content-Type", "text/html")
				w.WriteHeader(status)
			}))
			defer server.Close()

			_, reason := fetchFrom(t, &testGuard{}, server.URL)

			if status == http.StatusNoContent {
				// 204 is successful, so it passes the status check and fails on
				// having nothing to parse — which is the parser's verdict, not
				// this package's.
				return
			}

			if reason != preview.ReasonBadStatus {
				t.Fatalf("reason = %q, want %q", reason, preview.ReasonBadStatus)
			}
		})
	}
}

func TestFetchRefusesANonHtmlContentType(t *testing.T) {
	for name, contentType := range map[string]string{
		"json":  "application/json",
		"plain": "text/plain; charset=utf-8",
		"image": "image/png",
		"none":  "",
	} {
		t.Run(name, func(t *testing.T) {
			server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
				if contentType != "" {
					w.Header().Set("Content-Type", contentType)
				} else {
					w.Header()["Content-Type"] = nil
				}

				_, _ = io.WriteString(w, "{}")
			}))
			defer server.Close()

			_, reason := fetchFrom(t, &testGuard{}, server.URL)

			if reason != preview.ReasonUnsupportedMediaType {
				t.Fatalf("reason = %q, want %q", reason, preview.ReasonUnsupportedMediaType)
			}
		})
	}
}

func TestFetchAcceptsHtmlWhateverTheCaseAndParameters(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "TEXT/HTML ; charset=UTF-8")
		_, _ = io.WriteString(w, "<html><head><title>T</title></head></html>")
	}))
	defer server.Close()

	got, reason := fetchFrom(t, &testGuard{}, server.URL)

	if reason != "" {
		t.Fatalf("reason = %q", reason)
	}

	_ = got.Body.Close()
}

// A host that declares itself oversize is refused without a read at all, which
// is the behaviour being replaced and is inherited deliberately.
func TestFetchRefusesADeclaredOversizeBody(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "text/html")
		w.Header().Set("Content-Length", fmt.Sprint(maxBytes+1))
		_, _ = w.Write(make([]byte, maxBytes+1))
	}))
	defer server.Close()

	_, reason := fetchFrom(t, &testGuard{}, server.URL)

	if reason != preview.ReasonOversize {
		t.Fatalf("reason = %q, want %q", reason, preview.ReasonOversize)
	}
}

// This is #1202, and it is the case the PHP cannot pass.
//
// A chunked response declares no Content-Length, so there is nothing to
// pre-check against; PHP therefore buffers the whole thing before its cap is
// consulted, and a hostile host can make a worker allocate arbitrarily. Here the
// cap is the read, so the caller sees exactly maxBytes however much is sent.
func TestFetchBoundsAChunkedBodyThatDeclaresNothing(t *testing.T) {
	const sent = 6 << 20 // three times the cap

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "text/html")
		// No Content-Length, so net/http chunks it and the pre-check has
		// nothing to refuse on.
		flusher, _ := w.(http.Flusher)
		chunk := make([]byte, 64<<10)

		for written := 0; written < sent; written += len(chunk) {
			if _, err := w.Write(chunk); err != nil {
				return
			}

			if flusher != nil {
				flusher.Flush()
			}
		}
	}))
	defer server.Close()

	got, reason := fetchFrom(t, &testGuard{}, server.URL)

	if reason != "" {
		t.Fatalf("reason = %q, want a truncated document", reason)
	}
	defer got.Body.Close()

	read, err := io.Copy(io.Discard, got.Body)
	if err != nil {
		t.Fatalf("reading the bounded body: %v", err)
	}

	if read != maxBytes {
		t.Fatalf("read %d bytes, want the body bounded at %d", read, maxBytes)
	}
}

func TestFetchNamesWhyAHostCouldNotBeReached(t *testing.T) {
	// A port nothing is listening on, obtained by starting a server and
	// stopping it, so the address is real and definitely closed.
	server := httptest.NewServer(html("<html></html>"))
	url := server.URL
	server.Close()

	_, reason := fetchFrom(t, &testGuard{}, url)

	if reason != preview.ReasonTransportError && reason != preview.ReasonTimeout {
		t.Fatalf("reason = %q, want a transport failure", reason)
	}
}

func TestFetchNamesADnsFailure(t *testing.T) {
	_, reason := fetchFrom(t, &testGuard{}, "http://no-such-host.invalid/x")

	if reason != preview.ReasonDNSFailure {
		t.Fatalf("reason = %q, want %q", reason, preview.ReasonDNSFailure)
	}
}

func TestFetchRefusesAnUnbuildableRequest(t *testing.T) {
	_, reason := fetchFrom(t, &testGuard{}, "http://example.test/\x7f")

	if reason != preview.ReasonInvalidURL {
		t.Fatalf("reason = %q, want %q", reason, preview.ReasonInvalidURL)
	}
}

func TestFetchReportsATimeout(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		<-r.Context().Done()
	}))
	defer server.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 150*time.Millisecond)
	defer cancel()

	_, reason := New(&testGuard{}).Fetch(ctx, server.URL)

	if reason != preview.ReasonTimeout {
		t.Fatalf("reason = %q, want %q", reason, preview.ReasonTimeout)
	}
}

func TestMediaTypeFallsBackWhenTheHeaderIsUnparseable(t *testing.T) {
	if got := mediaType("text/html; charset="); got != "text/html" {
		t.Fatalf("mediaType = %q", got)
	}
}

func TestResolveLocationHandlesEveryShape(t *testing.T) {
	cases := map[string][3]string{
		"absolute":          {"https://a.test/x", "http://b.test/y", "http://b.test/y"},
		"protocol-relative": {"https://a.test/x", "//b.test/y", "https://b.test/y"},
		"root-relative":     {"https://a.test/x/y", "/z", "https://a.test/z"},
		"path-relative":     {"https://a.test/x/y", "z", "https://a.test/z"},
		"unusable base":     {"://nonsense", "/z", "https:///z"},
	}

	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			if got := resolveLocation(c[0], c[1]); got != c[2] {
				t.Fatalf("got %q, want %q", got, c[2])
			}
		})
	}
}
