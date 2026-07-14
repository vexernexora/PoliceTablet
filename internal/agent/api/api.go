// Package api implements the node agent's HTTP API: everything the panel
// needs to provision and control servers on this node. Every route
// requires the shared node secret as a bearer token.
package api

import (
	"github.com/nexora-host/canopy/internal/agent/docker"
)

type API struct {
	Docker  *docker.Manager
	Secret  string
	Version string
}

func New(dockerManager *docker.Manager, secret, version string) *API {
	return &API{Docker: dockerManager, Secret: secret, Version: version}
}
