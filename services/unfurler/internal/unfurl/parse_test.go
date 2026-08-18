package unfurl

import (
	"bytes"
	"strings"
	"testing"

	"golang.org/x/text/encoding/charmap"
	"golang.org/x/text/encoding/japanese"
)

func ptr(s string) *string { return &s }

func deref(s *string) string {
	if s == nil {
		return "<nil>"
	}

	return *s
}

// The Open Graph cases, ported one for one from the PHP suite this replaces
// (tests/Feature/Channels/LinkPreviewFetchTest.php).
func TestParseReadsOpenGraphMetadata(t *testing.T) {
	document := `<html><head>` +
		`<meta property="og:title" content="Hello">` +
		`<meta property="og:description" content="A page">` +
		`<meta property="og:image" content="https://example.com/img.png">` +
		`<meta property="og:site_name" content="Example">` +
		`</head></html>`

	got, ok := parse(strings.NewReader(document), "text/html", "https://example.com")

	if !ok {
		t.Fatal("expected a preview")
	}

	if got.Title != "Hello" ||
		deref(got.Description) != "A page" ||
		deref(got.Image) != "https://example.com/img.png" ||
		deref(got.SiteName) != "Example" {
		t.Fatalf("got %+v", got)
	}
}

func TestParseFallsBackToTheTitleTagAndHost(t *testing.T) {
	got, ok := parse(
		strings.NewReader(`<html><head><title>Just a title</title></head></html>`),
		"text/html",
		"https://example.com",
	)

	if !ok {
		t.Fatal("expected a preview")
	}

	if got.Title != "Just a title" {
		t.Fatalf("title = %q", got.Title)
	}

	if got.Description != nil || got.Image != nil {
		t.Fatalf("expected no description or image, got %+v", got)
	}

	if deref(got.SiteName) != "example.com" {
		t.Fatalf("siteName = %q, want the host", deref(got.SiteName))
	}
}

// og:title wins over <title> when both are present, which is the whole reason
// the fallback is a fallback.
func TestParsePrefersOpenGraphOverTheTitleTag(t *testing.T) {
	got, _ := parse(
		strings.NewReader(`<html><head><title>Tag</title><meta property="og:title" content="Graph"></head></html>`),
		"text/html",
		"https://example.com",
	)

	if got.Title != "Graph" {
		t.Fatalf("title = %q, want the og:title", got.Title)
	}
}

func TestParseIgnoresWhitespaceOnlyMetaContent(t *testing.T) {
	got, _ := parse(
		strings.NewReader(`<html><head><title>T</title><meta property="og:description" content="   "></head></html>`),
		"text/html",
		"https://example.com",
	)

	if got.Description != nil {
		t.Fatalf("description = %q, want nil", deref(got.Description))
	}
}

// A meta tag written with `name` rather than `property` is read too. Open Graph
// specifies `property`, and enough of the web writes `name` that reading only
// one of them silently loses previews on real sites.
func TestParseReadsAMetaTagWrittenWithName(t *testing.T) {
	got, _ := parse(
		strings.NewReader(`<html><head><meta name="og:title" content="Named"></head></html>`),
		"text/html",
		"https://example.com",
	)

	if got.Title != "Named" {
		t.Fatalf("title = %q", got.Title)
	}
}

// No title at all is the one condition that means "nothing worth showing".
func TestParseReturnsNothingWithoutATitle(t *testing.T) {
	for name, document := range map[string]string{
		"no title element": `<html><head><meta property="og:description" content="A page"></head></html>`,
		"an empty title":   `<html><head><title>   </title></head></html>`,
		"an empty body":    ``,
	} {
		t.Run(name, func(t *testing.T) {
			if _, ok := parse(strings.NewReader(document), "text/html", "https://example.com"); ok {
				t.Fatal("expected no preview")
			}
		})
	}
}

func TestParseMakesTheImageAbsolute(t *testing.T) {
	cases := map[string]struct{ image, base, want string }{
		"protocol-relative": {"//cdn.example.com/i.png", "https://example.com/a", "https://cdn.example.com/i.png"},
		"root-relative":     {"/i.png", "https://example.com/a/b", "https://example.com/i.png"},
		"path-relative":     {"i.png", "https://example.com/a/b", "https://example.com/i.png"},
		"already absolute":  {"http://other.test/i.png", "https://example.com/a", "http://other.test/i.png"},
	}

	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			got, _ := parse(
				strings.NewReader(`<html><head><title>T</title><meta property="og:image" content="`+c.image+`"></head></html>`),
				"text/html",
				c.base,
			)

			if deref(got.Image) != c.want {
				t.Fatalf("image = %q, want %q", deref(got.Image), c.want)
			}
		})
	}
}

// The head is read once and the first tag wins, matching DomCrawler's ->first().
func TestParseTakesTheFirstOfARepeatedTag(t *testing.T) {
	got, _ := parse(
		strings.NewReader(`<html><head><meta property="og:title" content="First"><meta property="og:title" content="Second"></head></html>`),
		"text/html",
		"https://example.com",
	)

	if got.Title != "First" {
		t.Fatalf("title = %q", got.Title)
	}
}

// A document cut off mid-stream by the byte cap still yields whatever the head
// held before the cut. Truncation is the correct reading for an unfurl, and this
// is what makes it safe.
func TestParseReadsATruncatedDocument(t *testing.T) {
	got, ok := parse(
		strings.NewReader(`<html><head><meta property="og:title" content="Cut off here"><meta property="og:desc`),
		"text/html",
		"https://example.com",
	)

	if !ok || got.Title != "Cut off here" {
		t.Fatalf("got %+v, ok=%v", got, ok)
	}
}

// The encoding cases. Symfony's DomCrawler sniffed these for free; x/net/html
// assumes UTF-8, so without charset.NewReader every non-UTF-8 page in the world
// would unfurl to mojibake and no ported test would have noticed.
func TestParseHonoursTheDocumentEncoding(t *testing.T) {
	t.Run("declared in the Content-Type header", func(t *testing.T) {
		body, err := charmap.Windows1252.NewEncoder().Bytes([]byte("Café naïve"))
		if err != nil {
			t.Fatalf("encoding the fixture: %v", err)
		}

		document := append([]byte(`<html><head><title>`), body...)
		document = append(document, []byte(`</title></head></html>`)...)

		got, _ := parse(bytes.NewReader(document), "text/html; charset=windows-1252", "https://example.com")

		if got.Title != "Café naïve" {
			t.Fatalf("title = %q, want the decoded text", got.Title)
		}
	})

	t.Run("declared in a meta tag", func(t *testing.T) {
		body, err := japanese.ShiftJIS.NewEncoder().Bytes([]byte("こんにちは"))
		if err != nil {
			t.Fatalf("encoding the fixture: %v", err)
		}

		document := append([]byte(`<html><head><meta charset="shift_jis"><title>`), body...)
		document = append(document, []byte(`</title></head></html>`)...)

		got, _ := parse(bytes.NewReader(document), "text/html", "https://example.com")

		if got.Title != "こんにちは" {
			t.Fatalf("title = %q, want the decoded text", got.Title)
		}
	})
}

// Nothing below <body> is read, which is what bounds the work a hostile document
// can make this service do.
func TestParseStopsAtTheBody(t *testing.T) {
	got, _ := parse(
		strings.NewReader(`<html><head><title>T</title></head><body><meta property="og:title" content="Too late"></body></html>`),
		"text/html",
		"https://example.com",
	)

	if got.Title != "T" {
		t.Fatalf("title = %q, want the head's own", got.Title)
	}
}

func TestAbsoluteURLFallsBackWhenTheBaseIsUnusable(t *testing.T) {
	if got := absoluteURL("://nonsense", "/i.png"); got != "https:///i.png" {
		t.Fatalf("got %q", got)
	}
}
