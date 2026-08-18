// Package preview holds the vocabulary every other package in this service
// speaks: what an unfurled link preview is, and the closed set of reasons one
// can fail to be produced.
//
// It imports nothing, deliberately. The guard, the fetcher, the parser and the
// HTTP surface all need to name the same things, and a shared vocabulary at the
// bottom of the import graph is what stops each of them inventing its own.
package preview

// Preview is what a link unfurls to.
//
// The JSON field names are load-bearing: they are the array shape
// App\Jobs\UnfurlMessageLinks already destructures on the PHP side, so a rename
// here is a wire break, not a tidy-up.
type Preview struct {
	Title       string  `json:"title"`
	Description *string `json:"description"`
	Image       *string `json:"image"`
	SiteName    *string `json:"siteName"`
}

// Reason names why a URL produced no preview.
//
// It is a closed set, and it exists for logs and metrics only. Nothing here is
// ever rendered: the caller collapses every one of them to the same empty card,
// exactly as the PHP it replaces collapsed every failure to one null. Naming
// them anyway is what makes an operator's logs answer "why is this link not
// unfurling" without a debugger.
type Reason string

const (
	// The URL never survived the guard.
	ReasonInvalidURL     Reason = "invalid_url"
	ReasonBlockedScheme  Reason = "blocked_scheme"
	ReasonBlockedHost    Reason = "blocked_host"
	ReasonBlockedAddress Reason = "blocked_address"

	// The URL was allowed but the fetch did not produce usable bytes.
	ReasonDNSFailure           Reason = "dns_failure"
	ReasonTransportError       Reason = "transport_error"
	ReasonTimeout              Reason = "timeout"
	ReasonTooManyRedirects     Reason = "too_many_redirects"
	ReasonBadStatus            Reason = "bad_status"
	ReasonUnsupportedMediaType Reason = "unsupported_content_type"
	ReasonOversize             Reason = "oversize"

	// The bytes arrived and held nothing worth showing.
	ReasonNoTitle Reason = "no_title"
)

// Status is the per-URL outcome in a batch response.
type Status string

const (
	StatusOK     Status = "ok"
	StatusFailed Status = "failed"
)

// Result is one URL's entry in a batch response.
//
// Preview is set when Status is StatusOK, Reason when it is StatusFailed, and
// never both. Callers that only want "did this unfurl" can read Status alone.
type Result struct {
	URL     string   `json:"url"`
	Status  Status   `json:"status"`
	Preview *Preview `json:"preview,omitempty"`
	Reason  Reason   `json:"reason,omitempty"`
}

// Failed builds a result for a URL that produced no preview.
func Failed(url string, reason Reason) Result {
	return Result{URL: url, Status: StatusFailed, Reason: reason}
}

// OK builds a result for a URL that unfurled.
func OK(url string, p Preview) Result {
	return Result{URL: url, Status: StatusOK, Preview: &p}
}
