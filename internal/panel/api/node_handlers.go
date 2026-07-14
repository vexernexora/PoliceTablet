package api

import (
	"net/http"

	"github.com/go-chi/chi/v5"

	"github.com/nexora-host/canopy/internal/panel/agentclient"
	"github.com/nexora-host/canopy/internal/panel/models"
)

type createNodeRequest struct {
	Name        string `json:"name"`
	FQDN        string `json:"fqdn"`
	Port        int    `json:"port"`
	TLS         bool   `json:"tls"`
	AgentSecret string `json:"agent_secret"`
	MemoryMB    int64  `json:"memory_mb"`
	DiskMB      int64  `json:"disk_mb"`
}

func (a *API) handleListNodes(w http.ResponseWriter, r *http.Request) {
	var nodes []models.Node
	if err := a.DB.Preload("Allocations").Order("id").Find(&nodes).Error; err != nil {
		writeError(w, http.StatusInternalServerError, "failed to list nodes")
		return
	}
	writeJSON(w, http.StatusOK, nodes)
}

func (a *API) handleCreateNode(w http.ResponseWriter, r *http.Request) {
	var req createNodeRequest
	if err := decodeJSON(r, &req); err != nil {
		writeError(w, http.StatusBadRequest, "invalid request body")
		return
	}
	if req.Name == "" || req.FQDN == "" || req.AgentSecret == "" {
		writeError(w, http.StatusBadRequest, "name, fqdn and agent_secret are required")
		return
	}
	if req.Port == 0 {
		req.Port = 8443
	}

	node := models.Node{
		Name:        req.Name,
		FQDN:        req.FQDN,
		Port:        req.Port,
		TLS:         req.TLS,
		AgentSecret: req.AgentSecret,
		MemoryMB:    req.MemoryMB,
		DiskMB:      req.DiskMB,
	}
	if err := a.DB.Create(&node).Error; err != nil {
		writeError(w, http.StatusConflict, "a node with that name already exists")
		return
	}
	writeJSON(w, http.StatusCreated, node)
}

func (a *API) handleDeleteNode(w http.ResponseWriter, r *http.Request) {
	id := chi.URLParam(r, "id")
	if err := a.DB.Delete(&models.Node{}, id).Error; err != nil {
		writeError(w, http.StatusInternalServerError, "failed to delete node")
		return
	}
	w.WriteHeader(http.StatusNoContent)
}

type createAllocationRequest struct {
	IP    string `json:"ip"`
	Ports []int  `json:"ports"`
}

func (a *API) handleCreateAllocations(w http.ResponseWriter, r *http.Request) {
	nodeID := chi.URLParam(r, "id")

	var node models.Node
	if err := a.DB.First(&node, nodeID).Error; err != nil {
		writeError(w, http.StatusNotFound, "node not found")
		return
	}

	var req createAllocationRequest
	if err := decodeJSON(r, &req); err != nil {
		writeError(w, http.StatusBadRequest, "invalid request body")
		return
	}
	if req.IP == "" {
		req.IP = "0.0.0.0"
	}
	if len(req.Ports) == 0 {
		writeError(w, http.StatusBadRequest, "at least one port is required")
		return
	}

	allocations := make([]models.Allocation, 0, len(req.Ports))
	for _, port := range req.Ports {
		allocations = append(allocations, models.Allocation{NodeID: node.ID, IP: req.IP, Port: port})
	}
	if err := a.DB.Create(&allocations).Error; err != nil {
		writeError(w, http.StatusConflict, "one or more ports are already allocated on this node")
		return
	}

	writeJSON(w, http.StatusCreated, allocations)
}

func (a *API) handleNodeHealth(w http.ResponseWriter, r *http.Request) {
	id := chi.URLParam(r, "id")
	var node models.Node
	if err := a.DB.First(&node, id).Error; err != nil {
		writeError(w, http.StatusNotFound, "node not found")
		return
	}

	client := agentclient.New(node.BaseURL(), node.AgentSecret)
	health, err := client.Health(r.Context())
	if err != nil {
		writeError(w, http.StatusBadGateway, "node agent unreachable: "+err.Error())
		return
	}
	writeJSON(w, http.StatusOK, health)
}
