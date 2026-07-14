// Package config loads node agent configuration, generating a random
// shared secret on first run (printed to the log so the operator can copy
// it into the panel's node registration form).
package config

import (
	"crypto/rand"
	"encoding/base64"
	"os"

	"gopkg.in/yaml.v3"
)

type Config struct {
	Bind    string `yaml:"bind"`
	Secret  string `yaml:"secret"`
	DataDir string `yaml:"data_dir"`
}

func defaults() *Config {
	return &Config{
		Bind:    ":8443",
		DataDir: "/var/lib/canopy/volumes",
	}
}

// Load reads agent configuration from path, creating it with defaults
// plus a freshly generated shared secret if it does not exist yet.
// Environment variables (CANOPY_AGENT_*), when set, override the file.
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
	if cfg.Secret == "" {
		secret, err := randomSecret(48)
		if err != nil {
			return nil, err
		}
		cfg.Secret = secret
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
	if v := os.Getenv("CANOPY_AGENT_BIND"); v != "" {
		cfg.Bind = v
	}
	if v := os.Getenv("CANOPY_AGENT_SECRET"); v != "" {
		cfg.Secret = v
	}
	if v := os.Getenv("CANOPY_AGENT_DATA_DIR"); v != "" {
		cfg.DataDir = v
	}
}
