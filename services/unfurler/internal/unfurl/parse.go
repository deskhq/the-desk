package unfurl

import (
	"io"
	"net/url"
	"strings"

	"golang.org/x/net/html"
	"golang.org/x/net/html/charset"

	"github.com/deskhq/the-desk/services/unfurler/internal/preview"
)

// parse extracts the preview fields from a document, preferring Open Graph tags
// and falling back to <title> and the host.
//
// A port of FetchLinkPreview::parse(). Two things differ, and both are why the
// port was worth doing:
//
//   - It reads from a stream and stops at </head>. Everything an unfurl reads
//     lives there, so a document truncated at the byte cap is usually scanned in
//     a few kilobytes rather than parsed into a DOM. A tokenizer also gives a
//     deliberately hostile 2 MiB of nested tags nothing to allocate against.
//   - It honours the document's encoding. Symfony's DomCrawler sniffs the
//     Content-Type charset and <meta charset>; x/net/html assumes UTF-8, so a
//     Shift-JIS or Windows-1252 page would come back as mojibake titles without
//     charset.NewReader. That regression would be invisible to every test that
//     did not think to write a non-UTF-8 fixture.
//
// It returns ok=false when there is nothing worth showing, which is exactly one
// condition: no title at all.
func parse(body io.Reader, contentType, baseURL string) (preview.Preview, bool) {
	decoded, err := charset.NewReader(body, contentType)
	if err != nil {
		// An encoding we cannot name is not a reason to give up on the bytes;
		// read them as they came, which is what the PHP did unconditionally.
		decoded = body
	}

	meta, title := readHead(decoded)

	name := firstNonEmpty(meta["og:title"], title)

	if name == "" {
		return preview.Preview{}, false
	}

	result := preview.Preview{Title: name}

	if description := meta["og:description"]; description != "" {
		result.Description = &description
	}

	if image := meta["og:image"]; image != "" {
		absolute := absoluteURL(baseURL, image)
		result.Image = &absolute
	}

	if site := firstNonEmpty(meta["og:site_name"], hostOf(baseURL)); site != "" {
		result.SiteName = &site
	}

	return result, true
}

// readHead walks the document once, collecting the meta tags an unfurl reads and
// the <title>, and stops as soon as the head is behind it.
//
// A meta tag is matched on `property` *or* `name`, mirroring the PHP selector:
// Open Graph specifies `property`, and enough of the web writes `name` that
// reading only one of them loses previews on real sites.
func readHead(document io.Reader) (map[string]string, string) {
	meta := map[string]string{}
	title := ""

	tokenizer := html.NewTokenizer(document)

	for {
		switch tokenizer.Next() {
		case html.ErrorToken:
			// io.EOF or a truncated document. Either way there is no more head
			// to read, and whatever was collected before the cut still stands.
			return meta, title

		case html.StartTagToken, html.SelfClosingTagToken:
			token := tokenizer.Token()

			switch token.Data {
			case "meta":
				key, content := "", ""

				for _, attr := range token.Attr {
					switch attr.Key {
					case "property", "name":
						if key == "" {
							key = attr.Val
						}
					case "content":
						content = attr.Val
					}
				}

				// First tag wins, matching DomCrawler's ->first(), and an empty
				// or whitespace-only value is treated as absent rather than as
				// an empty string.
				if key != "" && strings.TrimSpace(content) != "" {
					if _, seen := meta[key]; !seen {
						meta[key] = strings.TrimSpace(content)
					}
				}
			case "title":
				if title == "" {
					if tokenizer.Next() == html.TextToken {
						title = strings.TrimSpace(string(tokenizer.Text()))
					}
				}
			case "body":
				// Nothing an unfurl reads lives below here.
				return meta, title
			}

		case html.EndTagToken:
			if token := tokenizer.Token(); token.Data == "head" {
				return meta, title
			}
		}
	}
}

func firstNonEmpty(values ...string) string {
	for _, value := range values {
		if value != "" {
			return value
		}
	}

	return ""
}

func hostOf(raw string) string {
	parsed, err := url.Parse(raw)
	if err != nil {
		return ""
	}

	return parsed.Hostname()
}
