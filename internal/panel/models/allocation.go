package models

// Allocation is a single IP:port on a node that can be assigned to a
// server as its primary or an additional network endpoint.
type Allocation struct {
	ID       uint   `gorm:"primaryKey" json:"id"`
	NodeID   uint   `gorm:"not null;uniqueIndex:idx_node_port" json:"node_id"`
	IP       string `gorm:"not null;default:'0.0.0.0'" json:"ip"`
	Port     int    `gorm:"not null;uniqueIndex:idx_node_port" json:"port"`
	ServerID *uint  `gorm:"index" json:"server_id"`
}
