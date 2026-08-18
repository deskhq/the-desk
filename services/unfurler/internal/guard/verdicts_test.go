package guard_test

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"

	"github.com/deskhq/the-desk/services/unfurler/internal/guard"
)

// verdictCase is one row of the shared specification.
//
// Scope "both" is a verdict this guard and App\Support\Http\OutboundUrlGuard
// must agree on. Scope "go" is one this guard is stricter about, which is a real
// gap on the PHP side (webhook delivery, the image proxy) recorded as data
// rather than left to be rediscovered. Either way this suite asserts the row:
// the asymmetry is PHP's to close, not this guard's to relax.
type verdictCase struct {
	Name    string `json:"name"`
	URL     string `json:"url"`
	Blocked bool   `json:"blocked"`
	Scope   string `json:"scope"`
}

// sharedVerdicts reads tests/Fixtures/egress-verdict-cases.json, the one
// specification both implementations of this rule answer to. It is read by path
// rather than embedded because it lives outside this module, which is the point:
// neither language owns it. tests/Unit/SsrfVerdictParityTest.php fails if this
// file stops naming it.
func sharedVerdicts(t *testing.T) []verdictCase {
	t.Helper()

	path := filepath.Join("..", "..", "..", "..", "tests", "Fixtures", "egress-verdict-cases.json")

	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("reading the shared case table: %v", err)
	}

	var cases []verdictCase
	if err := json.Unmarshal(raw, &cases); err != nil {
		t.Fatalf("decoding the shared case table: %v", err)
	}

	// Fail closed. Every subtest below is driven off this slice, so a table that
	// resolved to nothing would pass the whole file without testing anything.
	if len(cases) < 30 {
		t.Fatalf("the shared case table has shrunk to %d cases; it is the specification, not a sample", len(cases))
	}

	return cases
}

func TestGuardAnswersTheSharedCaseTable(t *testing.T) {
	g := guard.New(true)

	for _, c := range sharedVerdicts(t) {
		t.Run(c.Name, func(t *testing.T) {
			err := g.CheckURL(c.URL)

			if c.Blocked && err == nil {
				t.Fatalf("%s: expected %q to be refused, it was allowed", c.Scope, c.URL)
			}

			if !c.Blocked && err != nil {
				t.Fatalf("%s: expected %q to be allowed, it was refused (%v)", c.Scope, c.URL, err)
			}
		})
	}
}

// The table is a list of grievances unless something in it is allowed. A guard
// that refuses every URL passes every blocked row and is useless.
func TestTheSharedCaseTableKeepsAllowCases(t *testing.T) {
	allowed := 0

	for _, c := range sharedVerdicts(t) {
		if !c.Blocked {
			allowed++
		}
	}

	if allowed == 0 {
		t.Fatal("the shared case table has no allow cases, so a refuse-everything guard would pass it")
	}
}
