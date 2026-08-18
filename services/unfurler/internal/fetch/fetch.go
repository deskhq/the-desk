// Package fetch reads the bytes at a member-typed URL, following only redirects
// the guard clears.
//
// It mirrors App\Support\Http\GuardedEgress::fetch() closely enough that a
// reviewer who knows one knows the other: the same hop bound, the same
// re-guarding of every hop, the same refusal to let the transport follow a
// redirect itself, the same Content-Length pre-check.
//
// The one thing it does differently is the thing it was ported for. PHP checks
// the size cap *after* Guzzle has buffered the response, so the cap bounds what
// it keeps rather than what it reads and a chunked reply can make a worker
// allocate arbitrarily (#1202). Here the body is never buffered at all: it is
// handed to the caller behind an io.LimitReader, and the parser consumes it as a
// stream and stops at </head>.
package fetch

import (
	"context"
	"errors"
	"io"
	"mime"
	"net"
	"net/http"
	"strings"
	"time"

	"github.com/deskhq/the-desk/services/unfurler/internal/guard"
	"github.com/deskhq/the-desk/services/unfurler/internal/preview"
)

const (
	// How many redirect hops to follow. Each is re-guarded and re-pinned, so a
	// public URL cannot bounce onto an internal one.
	maxRedirects = 3

	// Total time budget for a single hop.
	hopTimeout = 5 * time.Second

	// Hard cap on the HTML read and parsed. A truncated document is fine here:
	// everything an unfurl reads is in <head>.
	maxBytes int64 = 2 << 20 // 2 MiB
)

// Response is a vetted document, ready to be read.
//
// Body is bounded: reading it to EOF yields at most maxBytes however much the
// remote host sends. The caller closes it.
//
// It is a live stream, not a buffer, which is the whole point — so the context
// passed to Fetch must stay alive until the body has been read. Cancelling it
// first fails the read with `context canceled`.
type Response struct {
	URL         string
	ContentType string
	Body        io.ReadCloser
}

// Guard is the vetting this fetcher depends on: a pre-flight verdict it asks on
// every hop, and a dialer that refuses a non-public destination at connect time.
//
// It is an interface so this package's own contract — *is CheckURL asked on
// every hop, and does a refusal stop the walk?* — can be tested without a
// network the real guard would rightly refuse to touch. guard.Guard is the only
// production implementation, asserted below.
type Guard interface {
	CheckURL(string) error
	Dialer(time.Duration) *net.Dialer
}

var _ Guard = guard.Guard{}

// Fetcher walks a URL to its document.
type Fetcher struct {
	guard  Guard
	client *http.Client
}

// New builds a fetcher whose every connection is vetted at dial time.
func New(g Guard) *Fetcher {
	return &Fetcher{
		guard: g,
		client: &http.Client{
			Timeout: hopTimeout,
			// The redirect walk is done by hand so each new target is re-checked
			// rather than handed to the transport. Handing it over is how a
			// vetted URL ends up dialling somewhere nobody vetted.
			CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
			Transport: &http.Transport{
				DialContext: g.Dialer(hopTimeout).DialContext,
				// A pooled connection outlives the Control decision that opened
				// it, so there is no pooling. Nothing here makes a second
				// request to the same host anyway.
				DisableKeepAlives:     true,
				MaxIdleConns:          0,
				TLSHandshakeTimeout:   hopTimeout,
				ResponseHeaderTimeout: hopTimeout,
				ForceAttemptHTTP2:     false,
			},
		},
	}
}

// Fetch walks rawURL to a document, or names why it could not.
//
// Every way this fails is a reason and a nil response, because a caller
// unfurling a link a member typed has the same answer for all of them: no
// preview. The reasons exist for the operator's logs, not for the reader.
func (f *Fetcher) Fetch(ctx context.Context, rawURL string) (*Response, preview.Reason) {
	current := rawURL

	for hop := 0; hop <= maxRedirects; hop++ {
		if err := f.guard.CheckURL(current); err != nil {
			return nil, guard.Reason(err, preview.ReasonBlockedHost)
		}

		request, err := http.NewRequestWithContext(ctx, http.MethodGet, current, nil)
		if err != nil {
			return nil, preview.ReasonInvalidURL
		}

		response, err := f.client.Do(request)
		if err != nil {
			return nil, transportReason(err)
		}

		if location := redirectTarget(response); location != "" {
			// The body of a redirect is nothing, but it still holds the
			// connection until it is closed.
			_ = response.Body.Close()

			current = resolveLocation(current, location)

			continue
		}

		if isRedirect(response.StatusCode) {
			// A 3xx with no Location header, or an empty one. There is nowhere
			// to go and nothing to read.
			_ = response.Body.Close()

			return nil, preview.ReasonBadStatus
		}

		return f.read(response, current)
	}

	return nil, preview.ReasonTooManyRedirects
}

// read turns a non-redirect response into a bounded, correctly-typed body.
func (f *Fetcher) read(response *http.Response, url string) (*Response, preview.Reason) {
	if response.StatusCode < 200 || response.StatusCode > 299 {
		_ = response.Body.Close()

		return nil, preview.ReasonBadStatus
	}

	contentType := mediaType(response.Header.Get("Content-Type"))

	if contentType != "text/html" {
		_ = response.Body.Close()

		return nil, preview.ReasonUnsupportedMediaType
	}

	// The Content-Length pre-check, kept from the PHP so a host that declares
	// itself oversize is refused without a read at all.
	//
	// It is deliberately a *refusal* rather than a truncation, which is the
	// behaviour being replaced: a declared-oversize page gets no preview, while
	// an undeclared one is truncated and parsed. That asymmetry is inherited on
	// purpose. Making the two agree would change which pages unfurl, and
	// smuggling a behaviour change inside a port is exactly what the shared case
	// table exists to prevent.
	if response.ContentLength > maxBytes {
		_ = response.Body.Close()

		return nil, preview.ReasonOversize
	}

	return &Response{
		URL:         url,
		ContentType: response.Header.Get("Content-Type"),
		// This is #1202. The cap is the read, not a check after the read: the
		// caller cannot see more than maxBytes however much the host sends, and
		// a chunked reply that declares nothing has nothing to declare its way
		// past.
		Body: boundedBody{Reader: io.LimitReader(response.Body, maxBytes), Closer: response.Body},
	}, ""
}

// boundedBody is a ReadCloser whose read is capped and whose close still reaches
// the real connection.
type boundedBody struct {
	io.Reader
	io.Closer
}

// redirectTarget returns the Location a redirect points at, or "" when the
// response is not a usable redirect.
func redirectTarget(response *http.Response) string {
	if !isRedirect(response.StatusCode) {
		return ""
	}

	return strings.TrimSpace(response.Header.Get("Location"))
}

func isRedirect(status int) bool {
	switch status {
	case http.StatusMovedPermanently,
		http.StatusFound,
		http.StatusSeeOther,
		http.StatusTemporaryRedirect,
		http.StatusPermanentRedirect:
		return true
	}

	return false
}

// mediaType strips parameters and case from a Content-Type, so
// `TEXT/HTML; charset=utf-8` reads as `text/html`.
func mediaType(header string) string {
	parsed, _, err := mime.ParseMediaType(header)
	if err != nil {
		// Fall back to the crude split the PHP does, so a header this parser
		// dislikes but a browser would accept is not refused on a technicality.
		return strings.ToLower(strings.TrimSpace(strings.Split(header, ";")[0]))
	}

	return strings.ToLower(parsed)
}

// transportReason names why a request never produced a response.
func transportReason(err error) preview.Reason {
	if reason := guard.Reason(err, ""); reason != "" {
		return reason
	}

	if errors.Is(err, context.DeadlineExceeded) {
		return preview.ReasonTimeout
	}

	var dnsErr *net.DNSError
	if errors.As(err, &dnsErr) {
		return preview.ReasonDNSFailure
	}

	var netErr net.Error
	if errors.As(err, &netErr) && netErr.Timeout() {
		return preview.ReasonTimeout
	}

	return preview.ReasonTransportError
}
