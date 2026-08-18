package guard_test

import (
	"errors"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"testing"
	"time"

	"github.com/deskhq/the-desk/services/unfurler/internal/guard"
	"github.com/deskhq/the-desk/services/unfurler/internal/preview"
)

// This is the test the whole port exists for.
//
// httptest.Server listens on loopback, and its URL names it by address, so
// CheckURL alone would refuse it. To prove Control rather than the pre-flight
// check, dial the server through a hostname the guard has no complaint about and
// let the resolver point it at loopback. The PHP guard's defence at this point is
// a CURLOPT_RESOLVE pin that curl is asked to honour; this one is a refusal on
// the connect path, which is why there is nothing to honour and nothing to
// forget on the next hop.
func TestControlRefusesAConnectionToALoopbackAddress(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusOK)
	}))
	defer server.Close()

	g := guard.New(true)

	// The URL passes the pre-flight check: `rebind.test` is an ordinary
	// hostname with no blocked suffix, exactly as a hostile nameserver's domain
	// would be.
	if err := g.CheckURL("http://rebind.test/page"); err != nil {
		t.Fatalf("pre-flight refused the hostname, so this test would prove nothing: %v", err)
	}

	dialer := g.Dialer(2 * time.Second)

	// Dial the address the pre-flight check never saw, which is what a DNS
	// rebind delivers.
	_, err := dialer.Dial("tcp", server.Listener.Addr().String())

	if err == nil {
		t.Fatal("the dialer connected to a loopback address; Control did not run or did not refuse")
	}

	if !errors.Is(err, guard.ErrBlocked) {
		t.Fatalf("connection failed for the wrong reason: %v", err)
	}

	if got := guard.Reason(err, preview.ReasonTransportError); got != preview.ReasonBlockedAddress {
		t.Fatalf("reason = %q, want %q", got, preview.ReasonBlockedAddress)
	}
}

// The same dialer must still reach a public address, or the check above is
// satisfied by a guard that simply refuses to connect to anything.
func TestControlAllowsAConnectionToAPublicAddress(t *testing.T) {
	g := guard.New(true)

	if err := g.Control("tcp", "93.184.216.34:443", nil); err != nil {
		t.Fatalf("Control refused a public address: %v", err)
	}
}

func TestControlRefusesAnUnparseableDialAddress(t *testing.T) {
	err := guard.New(true).Control("tcp", "not-an-address", nil)

	if !errors.Is(err, guard.ErrBlocked) {
		t.Fatalf("err = %v, want ErrBlocked", err)
	}
}

// With the master switch off, the guard passes everything — including the
// connect-time check, which must not keep refusing addresses the pre-flight
// check has been told to allow.
func TestTheMasterSwitchTurnsBothHalvesOff(t *testing.T) {
	g := guard.New(false)

	if err := g.CheckURL("http://169.254.169.254/latest/meta-data"); err != nil {
		t.Fatalf("pre-flight still refused with the guard disabled: %v", err)
	}

	if err := g.Control("tcp", "127.0.0.1:80", nil); err != nil {
		t.Fatalf("connect-time check still refused with the guard disabled: %v", err)
	}
}

func TestIsPublicAddrRejectsAnInvalidAddress(t *testing.T) {
	if guard.IsPublicAddr(netip.Addr{}) {
		t.Fatal("the zero address is not public")
	}
}

func TestReasonFallsBackWhenTheErrorIsNotOurs(t *testing.T) {
	if got := guard.Reason(errors.New("boom"), preview.ReasonTimeout); got != preview.ReasonTimeout {
		t.Fatalf("reason = %q, want the fallback %q", got, preview.ReasonTimeout)
	}
}

// Every reason the pre-flight check can produce, named at the boundary that
// produces it. A refusal that reports the wrong reason is a log that sends the
// next operator to the wrong place.
func TestCheckURLNamesWhyItRefused(t *testing.T) {
	cases := map[string]struct {
		url  string
		want preview.Reason
	}{
		"a bad scheme":      {"ftp://example.test/x", preview.ReasonBlockedScheme},
		"no host":           {"http://:80", preview.ReasonInvalidURL},
		"a blocked name":    {"http://db.internal/x", preview.ReasonBlockedHost},
		"a zone index":      {"http://[fe80::1%25eth0]/x", preview.ReasonBlockedHost},
		"a private literal": {"http://10.0.0.5/x", preview.ReasonBlockedAddress},
	}

	g := guard.New(true)

	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			err := g.CheckURL(c.url)
			if err == nil {
				t.Fatalf("%q was allowed", c.url)
			}

			if got := guard.Reason(err, preview.ReasonTransportError); got != c.want {
				t.Fatalf("reason = %q, want %q", got, c.want)
			}
		})
	}
}
