package models

import (
	"fmt"
	"time"
)

// Node is a machine (VPS, dedicated server, ...) running the Canopy node
// agent, capable of hosting game server containers via Docker.
type Node struct {
	ID          uint         `gorm:"primaryKey" json:"id"`
	Name        string       `gorm:"uniqueIndex;size:64;not null" json:"name"`
	FQDN        string       `gorm:"not null" json:"fqdn"`
	Port        int          `gorm:"not null;default:8443" json:"port"`
	TLS         bool         `gorm:"not null;default:true" json:"tls"`
	AgentSecret string       `gorm:"not null" json:"-"`
	MemoryMB    int64        `gorm:"not null" json:"memory_mb"`
	DiskMB      int64        `gorm:"not null" json:"disk_mb"`
	Allocations []Allocation `gorm:"foreignKey:NodeID" json:"allocations,omitempty"`
	CreatedAt   time.Time    `json:"created_at"`
	UpdatedAt   time.Time    `json:"updated_at"`
}

// BaseURL is the address the panel uses to reach this node's agent API.
func (n *Node) BaseURL() string {
	scheme := "http"
	if n.TLS {
		scheme = "https"
	}
	return fmt.Sprintf("%s://%s:%d", scheme, n.FQDN, n.Port)
}
