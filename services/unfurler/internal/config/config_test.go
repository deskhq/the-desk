package config

import (
	"errors"
	"testing"
)

func TestLoadRefusesToStartWithoutASharedSecret(t *testing.T) {
	for name, token := range map[string]string{"unset": "", "whitespace": "   "} {
		t.Run(name, func(t *testing.T) {
			t.Setenv("UNFURLER_TOKEN", token)

			if _, err := Load(); !errors.Is(err, ErrNoToken) {
				t.Fatalf("err = %v, want ErrNoToken — serving unauthenticated must not be reachable by omission", err)
			}
		})
	}
}

func TestLoadDefaultsEverythingButTheToken(t *testing.T) {
	t.Setenv("UNFURLER_TOKEN", "s3cret")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("err = %v", err)
	}

	if cfg.Listen != ":8080" || cfg.LogLevel != "info" || cfg.MaxConcurrency != 16 {
		t.Fatalf("cfg = %+v", cfg)
	}

	// The guard defaults on. An instance that never sets the flag is protected.
	if !cfg.GuardEnabled {
		t.Fatal("the SSRF guard must default to on")
	}
}

func TestLoadReadsTheGuardFlagUnderItsWebhookEraName(t *testing.T) {
	t.Setenv("UNFURLER_TOKEN", "s3cret")

	for value, want := range map[string]bool{
		"false": false,
		"true":  true,
		"0":     false,
		"1":     true,
		// An unparseable value falls back to the safe reading rather than to
		// the unsafe one.
		"maybe": true,
		"":      true,
	} {
		t.Run(value, func(t *testing.T) {
			t.Setenv("WEBHOOKS_BLOCK_PRIVATE_URLS", value)

			cfg, err := Load()
			if err != nil {
				t.Fatalf("err = %v", err)
			}

			if cfg.GuardEnabled != want {
				t.Fatalf("GuardEnabled = %v for %q, want %v", cfg.GuardEnabled, value, want)
			}
		})
	}
}

func TestLoadTakesOverrides(t *testing.T) {
	t.Setenv("UNFURLER_TOKEN", "s3cret")
	t.Setenv("UNFURLER_LISTEN", "127.0.0.1:9999")
	t.Setenv("UNFURLER_LOG_LEVEL", "debug")
	t.Setenv("UNFURLER_MAX_CONCURRENCY", "4")

	cfg, _ := Load()

	if cfg.Listen != "127.0.0.1:9999" || cfg.LogLevel != "debug" || cfg.MaxConcurrency != 4 {
		t.Fatalf("cfg = %+v", cfg)
	}
}

func TestLoadIgnoresANonsenseConcurrency(t *testing.T) {
	t.Setenv("UNFURLER_TOKEN", "s3cret")

	for _, value := range []string{"0", "-3", "lots"} {
		t.Run(value, func(t *testing.T) {
			t.Setenv("UNFURLER_MAX_CONCURRENCY", value)

			cfg, _ := Load()

			if cfg.MaxConcurrency != 16 {
				t.Fatalf("MaxConcurrency = %d for %q", cfg.MaxConcurrency, value)
			}
		})
	}
}
