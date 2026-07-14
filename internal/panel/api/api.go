// Package api implements the Canopy panel's HTTP API: authentication,
// admin management of nodes/templates/users, and server lifecycle
// operations that are proxied through to the owning node's agent.
package api

import (
	"gorm.io/gorm"

	"github.com/nexora-host/canopy/internal/panel/auth"
	"github.com/nexora-host/canopy/internal/panel/config"
)

type API struct {
	DB     *gorm.DB
	Auth   *auth.Manager
	Config *config.Config
}

func New(db *gorm.DB, authManager *auth.Manager, cfg *config.Config) *API {
	return &API{DB: db, Auth: authManager, Config: cfg}
}
