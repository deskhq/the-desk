// Package guard decides whether this service will open a connection to a URL a
// member typed into a message.
//
// It is a second implementation of App\Support\Http\OutboundUrlGuard, which
// still fronts webhook delivery and the image proxy on the PHP side. The rule is
// specified once, in tests/Fixtures/egress-verdict-cases.json, and read by both
// languages' suites; tests/Unit/SsrfVerdictParityTest.php fails if either stops
// reading it. See ADR-0016.
//
// The reason for the port is Control. PHP resolves the hostname, hands curl a
// CURLOPT_RESOLVE pin, and then depends on curl honouring it and on the caller
// redoing the whole sequence on every redirect hop. Control runs after the
// resolver has answered and immediately before connect(2), on every connection
// the transport opens. There is no window between the check and the connection,
// and no per-hop ritual to forget.
package guard

import (
	"errors"
	"fmt"
	"net"
	"net/netip"
	"net/url"
	"strings"
	"syscall"
	"time"

	"github.com/deskhq/the-desk/services/unfurler/internal/preview"
)

// ErrBlocked is returned by Control when the address about to be dialled is not
// publicly routable. It travels back up through net/http as a *url.Error, which
// is why Reason unwraps for it rather than matching on the message.
var ErrBlocked = errors.New("guard: destination address is not public")

// blockedError carries the reason a URL was refused, so a caller can log why
// without re-deriving it.
type blockedError struct{ reason preview.Reason }

func (e *blockedError) Error() string { return "guard: refused (" + string(e.reason) + ")" }

// Reason maps any error out of this package, or out of a transport whose dialer
// this package guards, to the reason it should be reported as.
func Reason(err error, fallback preview.Reason) preview.Reason {
	var blocked *blockedError
	if errors.As(err, &blocked) {
		return blocked.reason
	}

	if errors.Is(err, ErrBlocked) {
		return preview.ReasonBlockedAddress
	}

	return fallback
}

// blockedHosts always resolve to the local machine, and are refused by name so
// no lookup is needed. Mirrors OutboundUrlGuard::BLOCKED_HOSTS.
var blockedHosts = []string{"localhost", "ip6-localhost", "ip6-loopback"}

// blockedSuffixes are reserved for local and private use: RFC 6762's .local,
// plus the conventional .internal and .localhost. Mirrors
// OutboundUrlGuard::BLOCKED_SUFFIXES.
var blockedSuffixes = []string{".localhost", ".local", ".internal"}

// blockedPrefixes are the ranges netip's own predicates do not cover.
//
// netip.Addr answers loopback, private (10/8, 172.16/12, 192.168/16, fc00::/7),
// link-local, multicast and unspecified on its own. Everything below is a range
// that is reserved, special-purpose or non-routable and that netip has no
// question for, so it is spelled out here rather than rested on.
//
// Several of these are ranges the PHP guard still allows; they are the rows
// carrying scope "go" in the shared case table, and ADR-0016 records them as a
// real gap on the webhook and image-proxy paths rather than a disagreement.
// 100.64.0.0/10 is the one that matters most in practice: carrier-grade NAT is
// live infrastructure address space, not a documentation range.
var blockedPrefixes = []netip.Prefix{
	netip.MustParsePrefix("0.0.0.0/8"),       // "this network" (RFC 1122)
	netip.MustParsePrefix("100.64.0.0/10"),   // carrier-grade NAT (RFC 6598)
	netip.MustParsePrefix("192.0.0.0/24"),    // IETF protocol assignments
	netip.MustParsePrefix("192.0.2.0/24"),    // TEST-NET-1
	netip.MustParsePrefix("198.18.0.0/15"),   // benchmarking (RFC 2544)
	netip.MustParsePrefix("198.51.100.0/24"), // TEST-NET-2
	netip.MustParsePrefix("203.0.113.0/24"),  // TEST-NET-3
	netip.MustParsePrefix("240.0.0.0/4"),     // reserved, and the broadcast address with it
	netip.MustParsePrefix("64:ff9b::/96"),    // NAT64 well-known prefix
	netip.MustParsePrefix("64:ff9b:1::/48"),  // NAT64 local-use prefix
	netip.MustParsePrefix("100::/64"),        // discard-only (RFC 6666)
	netip.MustParsePrefix("2001:db8::/32"),   // documentation
	netip.MustParsePrefix("fec0::/10"),       // site-local, deprecated but still routed by some stacks
}

// Guard vets destinations. The zero value blocks nothing, which is why it is
// never the zero value in main: New takes the decision explicitly.
type Guard struct {
	// enabled is the WEBHOOKS_BLOCK_PRIVATE_URLS master switch. It keeps its
	// webhook-era name across the whole application deliberately, so an operator
	// who turned the guard off for one fetch has it off for all of them; a second
	// flag name would silently re-arm it for exactly those instances.
	enabled bool
}

// New builds a guard. Pass the value of WEBHOOKS_BLOCK_PRIVATE_URLS.
func New(enabled bool) Guard { return Guard{enabled: enabled} }

// CheckURL is the pre-flight half: everything that can be decided from the URL
// text alone, with no lookup. It runs before the first request and again on
// every redirect target, because a hostname suffix is not something an address
// check can see.
//
// It returns nil when the URL may be dialled.
func (g Guard) CheckURL(raw string) error {
	if !g.enabled {
		return nil
	}

	parsed, err := url.Parse(raw)
	if err != nil {
		return &blockedError{preview.ReasonInvalidURL}
	}

	switch strings.ToLower(parsed.Scheme) {
	case "http", "https":
	default:
		return &blockedError{preview.ReasonBlockedScheme}
	}

	host := parsed.Hostname()

	if host == "" {
		return &blockedError{preview.ReasonInvalidURL}
	}

	// A zone index is never part of a public destination, and left in place it
	// fails address parsing — so the address would fall through to the hostname
	// branch below and skip the ranges entirely. url.Parse has already decoded
	// %25 to %, so one check covers both spellings.
	if strings.Contains(host, "%") {
		return &blockedError{preview.ReasonBlockedHost}
	}

	if addr, err := netip.ParseAddr(host); err == nil {
		if !IsPublicAddr(addr) {
			return &blockedError{preview.ReasonBlockedAddress}
		}

		return nil
	}

	if isBlockedHostname(host) {
		return &blockedError{preview.ReasonBlockedHost}
	}

	return nil
}

// Control is the connect-time half, and the reason this service exists in Go.
//
// net.Dialer calls it after the resolver has answered and immediately before
// connect(2), with the address actually about to be dialled. Every connection
// the transport opens goes through it, including every redirect hop, so a
// hostname whose authoritative DNS answers the guard with a public address and
// the connection with an internal one has nowhere to put the second answer.
func (g Guard) Control(_, address string, _ syscall.RawConn) error {
	if !g.enabled {
		return nil
	}

	addrPort, err := netip.ParseAddrPort(address)
	if err != nil {
		// Nothing legitimate reaches here: the dialer is handed a resolved
		// host:port. Refuse rather than guess.
		return fmt.Errorf("%w: unparseable dial address %q", ErrBlocked, address)
	}

	if !IsPublicAddr(addrPort.Addr()) {
		return fmt.Errorf("%w: %s", ErrBlocked, addrPort.Addr())
	}

	return nil
}

// Dialer returns a dialer that refuses a non-public destination at connect time.
func (g Guard) Dialer(timeout time.Duration) *net.Dialer {
	return &net.Dialer{
		Timeout: timeout,
		// Nothing here reuses a connection across requests, and a pooled one
		// would outlive the Control decision that opened it.
		KeepAlive: -1,
		Control:   g.Control,
	}
}

// IsPublicAddr reports whether an address sits in publicly-routable space.
//
// An IPv4-mapped IPv6 address is unmapped first: ::ffff:169.254.169.254 is the
// metadata endpoint wearing a v6 costume, and every predicate below would miss
// it in mapped form.
func IsPublicAddr(addr netip.Addr) bool {
	if !addr.IsValid() {
		return false
	}

	addr = addr.Unmap()

	switch {
	case addr.IsUnspecified(),
		addr.IsLoopback(),
		addr.IsPrivate(),
		addr.IsLinkLocalUnicast(),
		addr.IsLinkLocalMulticast(),
		addr.IsInterfaceLocalMulticast(),
		addr.IsMulticast():
		return false
	}

	for _, prefix := range blockedPrefixes {
		if prefix.Contains(addr) {
			return false
		}
	}

	return true
}

// isBlockedHostname reports whether a name is a known local or private one that
// needs no DNS lookup to refuse.
func isBlockedHostname(host string) bool {
	host = strings.ToLower(strings.TrimSuffix(host, "."))

	for _, blocked := range blockedHosts {
		if host == blocked {
			return true
		}
	}

	for _, suffix := range blockedSuffixes {
		if strings.HasSuffix(host, suffix) {
			return true
		}
	}

	return false
}
