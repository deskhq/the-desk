package fetch

import (
	"net/url"
	"strings"
)

// resolveLocation resolves a redirect's Location against the URL it came from.
//
// The same resolution the og:image path uses, and for the same reason: the
// result goes straight back into the guard on the next hop, so the two callers
// must agree on what a relative reference means.
func resolveLocation(base, location string) string {
	if lower := strings.ToLower(location); strings.HasPrefix(lower, "http://") || strings.HasPrefix(lower, "https://") {
		return location
	}

	scheme, host := "https", ""

	if parsed, err := url.Parse(base); err == nil {
		if parsed.Scheme != "" {
			scheme = parsed.Scheme
		}

		host = parsed.Host
	}

	if strings.HasPrefix(location, "//") {
		return scheme + ":" + location
	}

	return scheme + "://" + host + "/" + strings.TrimLeft(location, "/")
}
