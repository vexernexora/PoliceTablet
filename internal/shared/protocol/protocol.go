// Package protocol defines the wire contracts shared between the Canopy
// panel and the node agent. Both binaries import this package so the two
// halves of the system can never drift out of sync silently.
package protocol

// PowerAction is a lifecycle action requested against a running server.
type PowerAction string

const (
	PowerStart   PowerAction = "start"
	PowerStop    PowerAction = "stop"
	PowerRestart PowerAction = "restart"
	PowerKill    PowerAction = "kill"
)

// ServerState is the lifecycle state of a server container as reported by
// the node agent.
type ServerState string

const (
	StateInstalling    ServerState = "installing"
	StateInstallFailed ServerState = "install_failed"
	StateOffline       ServerState = "offline"
	StateStarting      ServerState = "starting"
	StateRunning       ServerState = "running"
	StateStopping      ServerState = "stopping"
	StateStopped       ServerState = "stopped"
	StateCrashed       ServerState = "crashed"
)

// ResourceLimits caps what a server's container may consume on its node.
type ResourceLimits struct {
	MemoryMB   int64 `json:"memory_mb"`
	SwapMB     int64 `json:"swap_mb"`
	DiskMB     int64 `json:"disk_mb"`
	CPUPercent int64 `json:"cpu_percent"` // 0 = unlimited, 100 = 1 core
	IOWeight   int64 `json:"io_weight"`   // 10-1000, blkio weight, 0 = default
}

// PortBinding maps a host-facing IP:port to the container.
type PortBinding struct {
	IP   string `json:"ip"`
	Port int    `json:"port"`
}

// CreateServerRequest is sent by the panel to a node agent to provision a
// new server. It is intentionally self-contained: the agent never needs to
// call back to the panel to resolve configuration, which keeps the
// panel -> agent relationship one-directional and easy to reason about
// (unlike Wings, which pulls config from the panel on demand).
type CreateServerRequest struct {
	UUID        string            `json:"uuid"`
	Image       string            `json:"image"`
	Startup     string            `json:"startup"`
	StopSignal  string            `json:"stop_signal"`
	Environment map[string]string `json:"environment"`
	Limits      ResourceLimits    `json:"limits"`
	Allocation  PortBinding       `json:"allocation"`
	Additional  []PortBinding     `json:"additional_allocations"`
}

// UpdateServerRequest changes limits/environment/image on an existing
// server without recreating its volume data.
type UpdateServerRequest struct {
	Image       string            `json:"image"`
	Startup     string            `json:"startup"`
	StopSignal  string            `json:"stop_signal"`
	Environment map[string]string `json:"environment"`
	Limits      ResourceLimits    `json:"limits"`
}

// PowerRequest asks the agent to change a server's running state.
type PowerRequest struct {
	Action PowerAction `json:"action"`
}

// ServerStats is a point-in-time resource usage snapshot for a server.
type ServerStats struct {
	State            ServerState `json:"state"`
	CPUPercent       float64     `json:"cpu_percent"`
	MemoryBytes      uint64      `json:"memory_bytes"`
	MemoryLimitBytes uint64      `json:"memory_limit_bytes"`
	DiskBytes        uint64      `json:"disk_bytes"`
	NetworkRxBytes   uint64      `json:"network_rx_bytes"`
	NetworkTxBytes   uint64      `json:"network_tx_bytes"`
	UptimeMs         int64       `json:"uptime_ms"`
}

// AgentServerStatus is returned by GET /servers/{uuid} on the agent API.
type AgentServerStatus struct {
	UUID  string      `json:"uuid"`
	State ServerState `json:"state"`
}

// WSEventType identifies the kind of message flowing over a console
// websocket, in both directions.
type WSEventType string

const (
	// Browser (via panel proxy) -> Agent
	WSEventAuth        WSEventType = "auth"
	WSEventSendCommand WSEventType = "send_command"

	// Agent -> Browser (via panel proxy)
	WSEventAuthSuccess  WSEventType = "auth_success"
	WSEventConsoleOutput WSEventType = "console_output"
	WSEventStatus        WSEventType = "status"
	WSEventStats         WSEventType = "stats"
	WSEventError         WSEventType = "error"
)

// WSMessage is the envelope for every console-socket frame in both
// directions, kept intentionally small and JSON-generic.
type WSMessage struct {
	Event   WSEventType `json:"event"`
	Payload interface{} `json:"payload,omitempty"`
}

// NodeHealth is returned by the agent's health endpoint so the panel can
// show node status without needing a full stats poll.
type NodeHealth struct {
	Version  string `json:"version"`
	Servers  int    `json:"servers"`
	DockerOK bool   `json:"docker_ok"`
}
