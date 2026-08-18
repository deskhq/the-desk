package unfurl

import (
	"net/url"
	"strings"
)

// absoluteURL resolves a possibly-relative URL against a base.
//
// A port of App\Support\Http\AbsoluteUrl, and deliberately a literal one rather
// than a delegation to url.ResolveReference: the two callers feed their result
// straight back into the guard, so a difference in how a relative reference
// resolves is a difference in what gets dialled. Matching the PHP exactly keeps
// the two languages answering the same question, and the behaviour it encodes is
// its own decision: everything that is not absolute and not protocol-relative is
// hung off the base *origin*, not off the base path.
func absoluteURL(baseURL, raw string) string {
	if lower := strings.ToLower(raw); strings.HasPrefix(lower, "http://") || strings.HasPrefix(lower, "https://") {
		return raw
	}

	scheme, host := "https", ""

	if base, err := url.Parse(baseURL); err == nil {
		if base.Scheme != "" {
			scheme = base.Scheme
		}

		host = base.Host
	}

	if strings.HasPrefix(raw, "//") {
		return scheme + ":" + raw
	}

	return scheme + "://" + host + "/" + strings.TrimLeft(raw, "/")
}
