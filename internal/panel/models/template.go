package models

import (
	"encoding/json"
	"time"
)

// Template describes how to run a particular kind of game server: which
// Docker image to use, the startup command, and the variables an owner can
// fill in (server.jar version, world seed, etc). This is the equivalent of
// a Pterodactyl "egg", renamed because "template" needs no glossary entry.
type Template struct {
	ID             uint      `gorm:"primaryKey" json:"id"`
	Name           string    `gorm:"uniqueIndex;size:64;not null" json:"name"`
	Description    string    `json:"description"`
	DockerImage    string    `gorm:"not null" json:"docker_image"`
	StartupCommand string    `gorm:"not null" json:"startup_command"`
	StopSignal     string    `gorm:"not null;default:SIGTERM" json:"stop_signal"`
	Variables      string    `gorm:"type:text" json:"-"`
	CreatedAt      time.Time `json:"created_at"`
	UpdatedAt      time.Time `json:"updated_at"`
}

// TemplateVariable is a single configurable value exposed to server owners,
// substituted into the startup command and passed through as an env var.
type TemplateVariable struct {
	Name         string `json:"name"`
	EnvKey       string `json:"env_key"`
	Default      string `json:"default"`
	Description  string `json:"description"`
	UserEditable bool   `json:"user_editable"`
}

func (t *Template) GetVariables() ([]TemplateVariable, error) {
	if t.Variables == "" {
		return []TemplateVariable{}, nil
	}
	var vars []TemplateVariable
	if err := json.Unmarshal([]byte(t.Variables), &vars); err != nil {
		return nil, err
	}
	return vars, nil
}

func (t *Template) SetVariables(vars []TemplateVariable) error {
	b, err := json.Marshal(vars)
	if err != nil {
		return err
	}
	t.Variables = string(b)
	return nil
}

// MarshalJSON embeds the decoded variables list in API responses while the
// database column stores it as a JSON string.
func (t Template) MarshalJSON() ([]byte, error) {
	type alias Template
	vars, err := t.GetVariables()
	if err != nil {
		vars = []TemplateVariable{}
	}
	return json.Marshal(struct {
		alias
		Variables []TemplateVariable `json:"variables"`
	}{alias: alias(t), Variables: vars})
}
