// Package config reads the service's settings from its environment.
package config

import (
	"errors"
	"os"
	"strconv"
	"strings"
)

// Config is everything the service needs to start.
type Config struct {
	Listen         string
	Token          string
	LogLevel       string
	MaxConcurrency int
	GuardEnabled   bool
}

// ErrNoToken is returned when no shared secret is configured.
//
// The service refuses to start rather than serving unauthenticated: it is
// reachable from every container on the compose network, and an unauthenticated
// fetcher there is a service the operator did not choose to run. Failing to boot
// is loud — the container restart-loops and `docker compose ps` says so — where
// serving open would be silent.
var ErrNoToken = errors.New("UNFURLER_TOKEN is required (it is derived from APP_KEY by the compose stack)")

// Load reads the configuration from the environment.
func Load() (Config, error) {
	cfg := Config{
		Listen:   env("UNFURLER_LISTEN", ":8080"),
		Token:    os.Getenv("UNFURLER_TOKEN"),
		LogLevel: env("UNFURLER_LOG_LEVEL", "info"),
		// The SSRF guard keeps its webhook-era name across the whole
		// application, so an operator who deliberately turned it off has it off
		// here too. A second flag name would silently re-arm it for exactly the
		// instances that opted out.
		GuardEnabled:   boolEnv("WEBHOOKS_BLOCK_PRIVATE_URLS", true),
		MaxConcurrency: intEnv("UNFURLER_MAX_CONCURRENCY", 16),
	}

	if strings.TrimSpace(cfg.Token) == "" {
		return Config{}, ErrNoToken
	}

	return cfg, nil
}

func env(key, fallback string) string {
	if value := strings.TrimSpace(os.Getenv(key)); value != "" {
		return value
	}

	return fallback
}

func boolEnv(key string, fallback bool) bool {
	value := strings.TrimSpace(os.Getenv(key))
	if value == "" {
		return fallback
	}

	// Laravel writes these as `true`/`false`; ParseBool also takes 1/0/yes-ish
	// spellings people reach for in a .env by hand.
	parsed, err := strconv.ParseBool(value)
	if err != nil {
		return fallback
	}

	return parsed
}

func intEnv(key string, fallback int) int {
	parsed, err := strconv.Atoi(strings.TrimSpace(os.Getenv(key)))
	if err != nil || parsed < 1 {
		return fallback
	}

	return parsed
}
