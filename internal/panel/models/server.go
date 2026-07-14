package models

import (
	"encoding/json"
	"time"
)

type ServerStatus string

const (
	ServerStatusInstalling    ServerStatus = "installing"
	ServerStatusInstallFailed ServerStatus = "install_failed"
	ServerStatusOffline       ServerStatus = "offline"
	ServerStatusStarting      ServerStatus = "starting"
	ServerStatusRunning       ServerStatus = "running"
	ServerStatusStopping      ServerStatus = "stopping"
	ServerStatusStopped       ServerStatus = "stopped"
	ServerStatusCrashed       ServerStatus = "crashed"
)

// Server is a single game server instance: an owner, a template, resource
// limits, and a home on one node + allocation.
type Server struct {
	ID           uint         `gorm:"primaryKey" json:"id"`
	UUID         string       `gorm:"uniqueIndex;size:36;not null" json:"uuid"`
	Name         string       `gorm:"not null" json:"name"`
	OwnerID      uint         `gorm:"not null;index" json:"owner_id"`
	Owner        *User        `gorm:"foreignKey:OwnerID" json:"owner,omitempty"`
	NodeID       uint         `gorm:"not null;index" json:"node_id"`
	Node         *Node        `gorm:"foreignKey:NodeID" json:"node,omitempty"`
	TemplateID   uint         `gorm:"not null;index" json:"template_id"`
	Template     *Template    `gorm:"foreignKey:TemplateID" json:"template,omitempty"`
	AllocationID uint         `gorm:"not null" json:"allocation_id"`
	Allocation   *Allocation  `gorm:"foreignKey:AllocationID" json:"allocation,omitempty"`
	MemoryMB     int64        `gorm:"not null" json:"memory_mb"`
	SwapMB       int64        `gorm:"not null;default:0" json:"swap_mb"`
	DiskMB       int64        `gorm:"not null" json:"disk_mb"`
	CPUPercent   int64        `gorm:"not null;default:0" json:"cpu_percent"`
	Environment  string       `gorm:"type:text" json:"-"`
	Status       ServerStatus `gorm:"not null;default:installing" json:"status"`
	CreatedAt    time.Time    `json:"created_at"`
	UpdatedAt    time.Time    `json:"updated_at"`
}

func (s *Server) GetEnvironment() (map[string]string, error) {
	env := map[string]string{}
	if s.Environment == "" {
		return env, nil
	}
	if err := json.Unmarshal([]byte(s.Environment), &env); err != nil {
		return nil, err
	}
	return env, nil
}

func (s *Server) SetEnvironment(env map[string]string) error {
	b, err := json.Marshal(env)
	if err != nil {
		return err
	}
	s.Environment = string(b)
	return nil
}
