// Package config loads panel configuration from a YAML file, generating
// sane defaults (including a random JWT secret) on first run so a fresh
// install never requires the operator to hand-craft a secrets file before
// the panel will even start.
package config

import (
	"crypto/rand"
	"encoding/base64"
	"os"

	"gopkg.in/yaml.v3"
)

type Config struct {
	Bind      string         `yaml:"bind"`
	AppURL    string         `yaml:"app_url"`
	JWTSecret string         `yaml:"jwt_secret"`
	Database  DatabaseConfig `yaml:"database"`
}

type DatabaseConfig struct {
	// Driver is "sqlite" (default, zero-config) or "postgres".
	Driver string `yaml:"driver"`
	// Path is the sqlite file path, used when Driver is "sqlite".
	Path string `yaml:"path"`
	// DSN is the postgres connection string, used when Driver is "postgres".
	DSN string `yaml:"dsn"`
}

func defaults() *Config {
	return &Config{
		Bind:   ":8080",
		AppURL: "http://localhost:8080",
		Database: DatabaseConfig{
			Driver: "sqlite",
			Path:   "canopy.db",
		},
	}
}

// Load reads configuration from the given YAML file path, creating it with
// defaults plus a freshly generated JWT secret if it does not exist yet.
// Environment variables (CANOPY_*), when set, override values from the
// file -- this keeps container deployments simple without requiring a
// mounted config file.
func Load(path string) (*Config, error) {
	cfg := defaults()
	fileExists := true

	data, err := os.ReadFile(path)
	if err != nil {
		if !os.IsNotExist(err) {
			return nil, err
		}
		fileExists = false
	} else if err := yaml.Unmarshal(data, cfg); err != nil {
		return nil, err
	}

	needsSave := !fileExists
	if cfg.JWTSecret == "" {
		secret, err := randomSecret(48)
		if err != nil {
			return nil, err
		}
		cfg.JWTSecret = secret
		needsSave = true
	}

	if needsSave {
		if err := save(path, cfg); err != nil {
			return nil, err
		}
	}

	applyEnvOverrides(cfg)
	return cfg, nil
}

func save(path string, cfg *Config) error {
	data, err := yaml.Marshal(cfg)
	if err != nil {
		return err
	}
	return os.WriteFile(path, data, 0600)
}

func randomSecret(n int) (string, error) {
	buf := make([]byte, n)
	if _, err := rand.Read(buf); err != nil {
		return "", err
	}
	return base64.RawURLEncoding.EncodeToString(buf), nil
}

func applyEnvOverrides(cfg *Config) {
	if v := os.Getenv("CANOPY_BIND"); v != "" {
		cfg.Bind = v
	}
	if v := os.Getenv("CANOPY_APP_URL"); v != "" {
		cfg.AppURL = v
	}
	if v := os.Getenv("CANOPY_JWT_SECRET"); v != "" {
		cfg.JWTSecret = v
	}
	if v := os.Getenv("CANOPY_DB_DRIVER"); v != "" {
		cfg.Database.Driver = v
	}
	if v := os.Getenv("CANOPY_DB_PATH"); v != "" {
		cfg.Database.Path = v
	}
	if v := os.Getenv("CANOPY_DB_DSN"); v != "" {
		cfg.Database.DSN = v
	}
}
