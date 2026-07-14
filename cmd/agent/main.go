// Command canopy-agent runs on each game server node. It exposes an HTTP
// API (secured by a shared secret configured on the matching panel Node
// record) that creates, controls and monitors Docker containers -- it is
// the only Canopy component that needs Docker access.
package main

import (
	"context"
	"fmt"
	"log"
	"net/http"
	"os"

	"github.com/nexora-host/canopy/internal/agent/api"
	"github.com/nexora-host/canopy/internal/agent/config"
	"github.com/nexora-host/canopy/internal/agent/docker"
)

const version = "0.1.0"

func main() {
	if len(os.Args) > 1 && os.Args[1] == "version" {
		fmt.Println("canopy-agent " + version)
		return
	}

	configPath := os.Getenv("CANOPY_AGENT_CONFIG")
	if configPath == "" {
		configPath = "agent.yml"
	}

	cfg, err := config.Load(configPath)
	if err != nil {
		log.Fatalf("load config: %v", err)
	}

	if err := os.MkdirAll(cfg.DataDir, 0755); err != nil {
		log.Fatalf("create data directory %q: %v", cfg.DataDir, err)
	}

	dockerManager, err := docker.NewManager(cfg.DataDir)
	if err != nil {
		log.Fatalf("create docker client: %v", err)
	}
	if err := dockerManager.Ping(context.Background()); err != nil {
		log.Fatalf("docker is not reachable (is the daemon running, and can this process read /var/run/docker.sock?): %v", err)
	}

	a := api.New(dockerManager, cfg.Secret, version)

	log.Printf("canopy-agent %s listening on %s", version, cfg.Bind)
	log.Printf("node secret (enter this when registering the node in the panel): %s", cfg.Secret)
	if err := http.ListenAndServe(cfg.Bind, a.Router()); err != nil {
		log.Fatalf("server error: %v", err)
	}
}
