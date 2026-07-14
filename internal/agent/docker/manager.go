// Package docker wraps the Docker Engine SDK with exactly the operations
// Canopy needs: create/start/stop/kill/remove a server's container, read
// its resource stats, and attach to its console.
//
// NOTE ON VERIFICATION: this package was written without a local Go
// toolchain or Docker daemon available to compile/run it (see
// CONTRIBUTING.md). The Docker Engine Go SDK's option-struct types have
// moved between the `types` and `types/container` packages across
// releases, so this is the most likely spot for a first `go build` to
// surface a signature mismatch against the pinned docker/docker version
// in go.mod. The fix is almost always a one-line import/type-name change.
package docker

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"time"

	"github.com/docker/docker/api/types"
	"github.com/docker/docker/api/types/container"
	"github.com/docker/docker/api/types/mount"
	"github.com/docker/docker/api/types/network"
	"github.com/docker/docker/client"
	"github.com/docker/go-connections/nat"

	"github.com/nexora-host/canopy/internal/shared/protocol"
)

const containerPrefix = "canopy-"

// Manager owns the Docker client and the on-disk root that server volumes
// live under.
type Manager struct {
	cli     *client.Client
	dataDir string
}

func NewManager(dataDir string) (*Manager, error) {
	cli, err := client.NewClientWithOpts(client.FromEnv, client.WithAPIVersionNegotiation())
	if err != nil {
		return nil, fmt.Errorf("create docker client: %w", err)
	}
	return &Manager{cli: cli, dataDir: dataDir}, nil
}

func (m *Manager) Ping(ctx context.Context) error {
	_, err := m.cli.Ping(ctx)
	return err
}

func containerName(uuid string) string {
	return containerPrefix + uuid
}

func (m *Manager) volumePath(uuid string) string {
	return filepath.Join(m.dataDir, uuid)
}

var startupVarPattern = regexp.MustCompile(`\{\{\s*([A-Za-z0-9_]+)\s*\}\}`)

// renderStartup substitutes {{VAR_NAME}} placeholders (the same syntax
// used by Pterodactyl eggs, so existing startup commands mostly work
// unmodified) with values from env, leaving unknown placeholders as-is.
func renderStartup(startup string, env map[string]string) string {
	return startupVarPattern.ReplaceAllStringFunc(startup, func(match string) string {
		key := startupVarPattern.FindStringSubmatch(match)[1]
		if v, ok := env[key]; ok {
			return v
		}
		return match
	})
}

// CreateServer provisions the data directory and a new container for a
// server, then starts it. The working directory inside the container is
// /home/container (Pterodactyl's convention), which keeps the large
// existing ecosystem of "egg" images usable without modification.
func (m *Manager) CreateServer(ctx context.Context, req protocol.CreateServerRequest) error {
	if err := os.MkdirAll(m.volumePath(req.UUID), 0755); err != nil {
		return fmt.Errorf("create volume directory: %w", err)
	}

	env := make(map[string]string, len(req.Environment)+3)
	for k, v := range req.Environment {
		env[k] = v
	}
	env["SERVER_MEMORY"] = strconv.FormatInt(req.Limits.MemoryMB, 10)
	env["SERVER_IP"] = req.Allocation.IP
	env["SERVER_PORT"] = strconv.Itoa(req.Allocation.Port)

	startup := renderStartup(req.Startup, env)

	envSlice := make([]string, 0, len(env))
	for k, v := range env {
		envSlice = append(envSlice, k+"="+v)
	}

	portSet := nat.PortSet{}
	portBindings := nat.PortMap{}
	bind := func(b protocol.PortBinding) {
		for _, proto := range []string{"tcp", "udp"} {
			p := nat.Port(strconv.Itoa(b.Port) + "/" + proto)
			portSet[p] = struct{}{}
			portBindings[p] = []nat.PortBinding{{HostIP: b.IP, HostPort: strconv.Itoa(b.Port)}}
		}
	}
	bind(req.Allocation)
	for _, extra := range req.Additional {
		bind(extra)
	}

	resources := container.Resources{
		Memory:     req.Limits.MemoryMB * 1024 * 1024,
		MemorySwap: memorySwapLimit(req.Limits),
	}
	if req.Limits.CPUPercent > 0 {
		resources.NanoCPUs = req.Limits.CPUPercent * 10_000_000 // CPUPercent: 100 == 1 full core
	}

	hostConfig := &container.HostConfig{
		PortBindings: portBindings,
		Resources:    resources,
		Mounts: []mount.Mount{{
			Type:   mount.TypeBind,
			Source: m.volumePath(req.UUID),
			Target: "/home/container",
		}},
		RestartPolicy: container.RestartPolicy{Name: "no"},
	}

	stopSignal := req.StopSignal
	if stopSignal == "" {
		stopSignal = "SIGTERM"
	}

	containerConfig := &container.Config{
		Image:        req.Image,
		Cmd:          []string{"/bin/sh", "-c", startup},
		Env:          envSlice,
		ExposedPorts: portSet,
		WorkingDir:   "/home/container",
		Tty:          true,
		OpenStdin:    true,
		StopSignal:   stopSignal,
		Labels: map[string]string{
			"io.canopy.managed": "true",
			"io.canopy.uuid":    req.UUID,
		},
	}

	resp, err := m.cli.ContainerCreate(ctx, containerConfig, hostConfig, &network.NetworkingConfig{}, nil, containerName(req.UUID))
	if err != nil {
		return fmt.Errorf("create container: %w", err)
	}

	if err := m.cli.ContainerStart(ctx, resp.ID, container.StartOptions{}); err != nil {
		return fmt.Errorf("start container: %w", err)
	}

	return nil
}

func memorySwapLimit(limits protocol.ResourceLimits) int64 {
	if limits.MemoryMB <= 0 {
		return 0
	}
	return (limits.MemoryMB + limits.SwapMB) * 1024 * 1024
}

// UpdateServer applies new resource limits to a running container without
// recreating it (and therefore without touching its volume data).
func (m *Manager) UpdateServer(ctx context.Context, uuid string, req protocol.UpdateServerRequest) error {
	resources := container.Resources{
		Memory:     req.Limits.MemoryMB * 1024 * 1024,
		MemorySwap: memorySwapLimit(req.Limits),
	}
	if req.Limits.CPUPercent > 0 {
		resources.NanoCPUs = req.Limits.CPUPercent * 10_000_000
	}
	if _, err := m.cli.ContainerUpdate(ctx, containerName(uuid), container.UpdateConfig{Resources: resources}); err != nil {
		return fmt.Errorf("update container resources: %w", err)
	}
	return nil
}

// DeleteServer stops and removes the container (best effort -- it may
// already be gone) and always removes the server's data directory.
func (m *Manager) DeleteServer(ctx context.Context, uuid string) error {
	name := containerName(uuid)
	timeout := 10
	_ = m.cli.ContainerStop(ctx, name, container.StopOptions{Timeout: &timeout})
	_ = m.cli.ContainerRemove(ctx, name, container.RemoveOptions{Force: true})

	if err := os.RemoveAll(m.volumePath(uuid)); err != nil {
		return fmt.Errorf("remove volume directory: %w", err)
	}
	return nil
}

// Power starts, stops, restarts or force-kills a server's container.
func (m *Manager) Power(ctx context.Context, uuid string, action protocol.PowerAction) error {
	name := containerName(uuid)
	timeout := 30

	switch action {
	case protocol.PowerStart:
		return m.cli.ContainerStart(ctx, name, container.StartOptions{})
	case protocol.PowerStop:
		return m.cli.ContainerStop(ctx, name, container.StopOptions{Timeout: &timeout})
	case protocol.PowerRestart:
		return m.cli.ContainerRestart(ctx, name, container.StopOptions{Timeout: &timeout})
	case protocol.PowerKill:
		return m.cli.ContainerKill(ctx, name, "SIGKILL")
	default:
		return fmt.Errorf("unknown power action %q", action)
	}
}

// Stats returns a point-in-time resource snapshot. A container that
// doesn't exist (not yet started, or already removed) is reported as
// offline rather than an error, since "no such server" is an expected,
// routine state for this endpoint to observe.
func (m *Manager) Stats(ctx context.Context, uuid string) (*protocol.ServerStats, error) {
	name := containerName(uuid)

	inspect, err := m.cli.ContainerInspect(ctx, name)
	if err != nil {
		return &protocol.ServerStats{State: protocol.StateOffline}, nil
	}

	state := stateFromDocker(inspect)
	if !inspect.State.Running {
		return &protocol.ServerStats{State: state}, nil
	}

	statsResp, err := m.cli.ContainerStats(ctx, name, false)
	if err != nil {
		return nil, fmt.Errorf("container stats: %w", err)
	}
	defer statsResp.Body.Close()

	var raw types.StatsJSON
	if err := json.NewDecoder(statsResp.Body).Decode(&raw); err != nil {
		return nil, fmt.Errorf("decode stats: %w", err)
	}

	stats := &protocol.ServerStats{
		State:            state,
		CPUPercent:       calculateCPUPercent(&raw),
		MemoryBytes:      raw.MemoryStats.Usage,
		MemoryLimitBytes: raw.MemoryStats.Limit,
		NetworkRxBytes:   sumNetwork(raw.Networks, true),
		NetworkTxBytes:   sumNetwork(raw.Networks, false),
	}
	if t, err := time.Parse(time.RFC3339Nano, inspect.State.StartedAt); err == nil {
		stats.UptimeMs = time.Since(t).Milliseconds()
	}
	return stats, nil
}

func stateFromDocker(inspect types.ContainerJSON) protocol.ServerState {
	switch {
	case inspect.State.Running:
		return protocol.StateRunning
	case inspect.State.Restarting:
		return protocol.StateStarting
	case inspect.State.ExitCode != 0:
		return protocol.StateCrashed
	default:
		return protocol.StateStopped
	}
}

// calculateCPUPercent uses the same delta formula as `docker stats`.
func calculateCPUPercent(stats *types.StatsJSON) float64 {
	cpuDelta := float64(stats.CPUStats.CPUUsage.TotalUsage) - float64(stats.PreCPUStats.CPUUsage.TotalUsage)
	systemDelta := float64(stats.CPUStats.SystemUsage) - float64(stats.PreCPUStats.SystemUsage)
	if systemDelta <= 0 || cpuDelta <= 0 {
		return 0
	}

	cpuCount := float64(stats.CPUStats.OnlineCPUs)
	if cpuCount == 0 {
		cpuCount = float64(len(stats.CPUStats.CPUUsage.PercpuUsage))
	}
	if cpuCount == 0 {
		cpuCount = 1
	}

	return (cpuDelta / systemDelta) * cpuCount * 100.0
}

func sumNetwork(networks map[string]types.NetworkStats, rx bool) uint64 {
	var total uint64
	for _, n := range networks {
		if rx {
			total += n.RxBytes
		} else {
			total += n.TxBytes
		}
	}
	return total
}

// AttachConsole hijacks the container's stdio stream for the console
// websocket handler to bridge. Since the container is created with a TTY,
// stdout/stderr are already combined into a single stream.
func (m *Manager) AttachConsole(ctx context.Context, uuid string) (types.HijackedResponse, error) {
	return m.cli.ContainerAttach(ctx, containerName(uuid), container.AttachOptions{
		Stream: true,
		Stdin:  true,
		Stdout: true,
		Stderr: true,
	})
}
