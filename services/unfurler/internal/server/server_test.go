package server

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/deskhq/the-desk/services/unfurler/internal/preview"
)

const token = "s3cret"

type fakeUnfurler struct {
	got     []string
	results []preview.Result
}

func (f *fakeUnfurler) Unfurl(_ context.Context, urls []string) []preview.Result {
	f.got = urls

	if f.results != nil {
		return f.results
	}

	results := make([]preview.Result, len(urls))
	for i, url := range urls {
		results[i] = preview.OK(url, preview.Preview{Title: "T"})
	}

	return results
}

func post(t *testing.T, srv *Server, body, bearer string) *httptest.ResponseRecorder {
	t.Helper()

	request := httptest.NewRequest(http.MethodPost, "/unfurl", strings.NewReader(body))

	if bearer != "" {
		request.Header.Set("Authorization", "Bearer "+bearer)
	}

	recorder := httptest.NewRecorder()
	srv.Handler().ServeHTTP(recorder, request)

	return recorder
}

func TestUnfurlReturnsAResultPerUrl(t *testing.T) {
	unfurler := &fakeUnfurler{}
	srv := New(unfurler, token, nil)

	recorder := post(t, srv, `{"urls":["https://a.test","https://b.test"]}`, token)

	if recorder.Code != http.StatusOK {
		t.Fatalf("status = %d, body %s", recorder.Code, recorder.Body)
	}

	var body batchResponse
	if err := json.Unmarshal(recorder.Body.Bytes(), &body); err != nil {
		t.Fatalf("decoding: %v", err)
	}

	if len(body.Results) != 2 {
		t.Fatalf("got %d results", len(body.Results))
	}

	if unfurler.got[0] != "https://a.test" || unfurler.got[1] != "https://b.test" {
		t.Fatalf("the batch reached the unfurler as %v", unfurler.got)
	}
}

// The wire shape is the contract with PHP, so it is asserted as JSON text rather
// than by round-tripping through the same structs that produced it.
func TestTheResponseShapeIsTheContractWithPhp(t *testing.T) {
	srv := New(&fakeUnfurler{results: []preview.Result{
		preview.OK("https://a.test", preview.Preview{
			Title:       "Hello",
			Description: strptr("A page"),
			Image:       strptr("https://a.test/i.png"),
			SiteName:    strptr("Example"),
		}),
		preview.Failed("https://b.test", preview.ReasonBlockedAddress),
	}}, token, nil)

	got := post(t, srv, `{"urls":["https://a.test","https://b.test"]}`, token).Body.String()

	for _, fragment := range []string{
		`"url":"https://a.test"`,
		`"status":"ok"`,
		`"title":"Hello"`,
		`"description":"A page"`,
		`"image":"https://a.test/i.png"`,
		`"siteName":"Example"`,
		`"status":"failed"`,
		`"reason":"blocked_address"`,
	} {
		if !strings.Contains(got, fragment) {
			t.Fatalf("response is missing %s\ngot: %s", fragment, got)
		}
	}

	// A failed result carries no preview key at all, so PHP reads one shape.
	if strings.Contains(got, `"preview":null`) {
		t.Fatalf("a failed result should omit the preview key entirely: %s", got)
	}
}

// A preview whose optional fields are absent must send explicit nulls, not
// missing keys: the PHP side writes all four columns every time.
func TestAnAbsentFieldIsAnExplicitNull(t *testing.T) {
	srv := New(&fakeUnfurler{results: []preview.Result{
		preview.OK("https://a.test", preview.Preview{Title: "Only a title"}),
	}}, token, nil)

	got := post(t, srv, `{"urls":["https://a.test"]}`, token).Body.String()

	for _, fragment := range []string{`"description":null`, `"image":null`, `"siteName":null`} {
		if !strings.Contains(got, fragment) {
			t.Fatalf("missing %s in %s", fragment, got)
		}
	}
}

func TestUnfurlRefusesAnythingWithoutTheSharedSecret(t *testing.T) {
	unfurler := &fakeUnfurler{}
	srv := New(unfurler, token, nil)

	for name, bearer := range map[string]string{
		"no header":     "",
		"a wrong token": "wrong",
		"a prefix":      "s3cre",
		"an empty one":  " ",
	} {
		t.Run(name, func(t *testing.T) {
			recorder := post(t, srv, `{"urls":["https://a.test"]}`, bearer)

			if recorder.Code != http.StatusUnauthorized {
				t.Fatalf("status = %d, want 401", recorder.Code)
			}

			if unfurler.got != nil {
				t.Fatal("an unauthorised request reached the unfurler")
			}
		})
	}
}

func TestUnfurlRefusesAMalformedBody(t *testing.T) {
	for name, body := range map[string]string{
		"not json":      `{`,
		"wrong type":    `{"urls":"https://a.test"}`,
		"not an object": `[]`,
	} {
		t.Run(name, func(t *testing.T) {
			if code := post(t, New(&fakeUnfurler{}, token, nil), body, token).Code; code != http.StatusBadRequest {
				t.Fatalf("status = %d, want 400", code)
			}
		})
	}
}

// How many URLs one request may fan out to is this service's own resource
// question, not the caller's to answer, even though the caller already caps it.
func TestUnfurlRefusesAnOversizeBatch(t *testing.T) {
	unfurler := &fakeUnfurler{}

	recorder := post(t, New(unfurler, token, nil),
		`{"urls":["https://a.test","https://b.test","https://c.test","https://d.test"]}`, token)

	if recorder.Code != http.StatusBadRequest {
		t.Fatalf("status = %d, want 400", recorder.Code)
	}

	if !strings.Contains(recorder.Body.String(), "too_many_urls") {
		t.Fatalf("body = %s", recorder.Body)
	}

	if unfurler.got != nil {
		t.Fatal("an oversize batch reached the unfurler")
	}
}

func TestUnfurlAcceptsAnEmptyBatchWithoutWork(t *testing.T) {
	unfurler := &fakeUnfurler{}

	recorder := post(t, New(unfurler, token, nil), `{"urls":[]}`, token)

	if recorder.Code != http.StatusOK {
		t.Fatalf("status = %d", recorder.Code)
	}

	if !strings.Contains(recorder.Body.String(), `"results":[]`) {
		t.Fatalf("body = %s", recorder.Body)
	}

	if unfurler.got != nil {
		t.Fatal("an empty batch reached the unfurler")
	}
}

func TestUnfurlRefusesAnOversizeRequestBody(t *testing.T) {
	body := `{"urls":["https://a.test` + strings.Repeat("x", maxBodyBytes) + `"]}`

	if code := post(t, New(&fakeUnfurler{}, token, nil), body, token).Code; code != http.StatusBadRequest {
		t.Fatalf("status = %d, want 400", code)
	}
}

// The health probe is the only unauthenticated route, and it says nothing about
// the service beyond whether it is taking work.
func TestHealthNeedsNoTokenAndReportsDraining(t *testing.T) {
	srv := New(&fakeUnfurler{}, token, nil)

	request := httptest.NewRequest(http.MethodGet, "/healthz", nil)
	recorder := httptest.NewRecorder()
	srv.Handler().ServeHTTP(recorder, request)

	if recorder.Code != http.StatusOK || !strings.Contains(recorder.Body.String(), `"ok"`) {
		t.Fatalf("status = %d, body = %s", recorder.Code, recorder.Body)
	}

	srv.Drain()

	recorder = httptest.NewRecorder()
	srv.Handler().ServeHTTP(httptest.NewRecorder(), request)
	srv.Handler().ServeHTTP(recorder, httptest.NewRequest(http.MethodGet, "/healthz", nil))

	if recorder.Code != http.StatusServiceUnavailable {
		t.Fatalf("a draining server reported %d, want 503", recorder.Code)
	}
}

func TestTheUnfurlRouteIsPostOnly(t *testing.T) {
	srv := New(&fakeUnfurler{}, token, nil)

	recorder := httptest.NewRecorder()
	srv.Handler().ServeHTTP(recorder, httptest.NewRequest(http.MethodGet, "/unfurl", nil))

	if recorder.Code == http.StatusOK {
		t.Fatal("GET /unfurl was served")
	}
}

func strptr(s string) *string { return &s }
