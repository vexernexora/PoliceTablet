package api

import (
	"context"
	"net/http"

	"github.com/go-chi/chi/v5"
	"github.com/google/uuid"
	"gorm.io/gorm"

	"github.com/nexora-host/canopy/internal/panel/agentclient"
	"github.com/nexora-host/canopy/internal/panel/auth"
	"github.com/nexora-host/canopy/internal/panel/models"
	"github.com/nexora-host/canopy/internal/panel/ws"
	"github.com/nexora-host/canopy/internal/shared/protocol"
)

type serverContextKey struct{}

// serverContext loads the server named by the {uuid} URL param, enforcing
// that non-admins may only reach servers they own, and stores it on the
// request context for downstream handlers.
func (a *API) serverContext(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		uuidParam := chi.URLParam(r, "uuid")

		var server models.Server
		q := a.DB.Preload("Node").Preload("Template").Preload("Allocation").Preload("Owner")
		if err := q.Where("uuid = ?", uuidParam).First(&server).Error; err != nil {
			writeError(w, http.StatusNotFound, "server not found")
			return
		}

		if !auth.IsAdmin(r.Context()) && server.OwnerID != auth.UserID(r.Context()) {
			writeError(w, http.StatusNotFound, "server not found")
			return
		}

		ctx := context.WithValue(r.Context(), serverContextKey{}, &server)
		next.ServeHTTP(w, r.WithContext(ctx))
	})
}

func serverFromContext(ctx context.Context) *models.Server {
	s, _ := ctx.Value(serverContextKey{}).(*models.Server)
	return s
}

func (a *API) handleListServers(w http.ResponseWriter, r *http.Request) {
	q := a.DB.Preload("Node").Preload("Template").Preload("Allocation")
	if !auth.IsAdmin(r.Context()) {
		q = q.Where("owner_id = ?", auth.UserID(r.Context()))
	}

	var servers []models.Server
	if err := q.Order("id").Find(&servers).Error; err != nil {
		writeError(w, http.StatusInternalServerError, "failed to list servers")
		return
	}
	writeJSON(w, http.StatusOK, servers)
}

func (a *API) handleGetServer(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, serverFromContext(r.Context()))
}

type createServerRequest struct {
	Name         string            `json:"name"`
	OwnerID      uint              `json:"owner_id"`
	NodeID       uint              `json:"node_id"`
	TemplateID   uint              `json:"template_id"`
	AllocationID uint              `json:"allocation_id"`
	MemoryMB     int64             `json:"memory_mb"`
	SwapMB       int64             `json:"swap_mb"`
	DiskMB       int64             `json:"disk_mb"`
	CPUPercent   int64             `json:"cpu_percent"`
	Environment  map[string]string `json:"environment"`
}

func (a *API) handleCreateServer(w http.ResponseWriter, r *http.Request) {
	var req createServerRequest
	if err := decodeJSON(r, &req); err != nil {
		writeError(w, http.StatusBadRequest, "invalid request body")
		return
	}
	if req.Name == "" || req.OwnerID == 0 || req.NodeID == 0 || req.TemplateID == 0 || req.AllocationID == 0 {
		writeError(w, http.StatusBadRequest, "name, owner_id, node_id, template_id and allocation_id are required")
		return
	}

	var node models.Node
	if err := a.DB.First(&node, req.NodeID).Error; err != nil {
		writeError(w, http.StatusBadRequest, "node not found")
		return
	}

	var template models.Template
	if err := a.DB.First(&template, req.TemplateID).Error; err != nil {
		writeError(w, http.StatusBadRequest, "template not found")
		return
	}

	var allocation models.Allocation
	if err := a.DB.First(&allocation, req.AllocationID).Error; err != nil {
		writeError(w, http.StatusBadRequest, "allocation not found")
		return
	}
	if allocation.NodeID != node.ID {
		writeError(w, http.StatusBadRequest, "allocation does not belong to the selected node")
		return
	}
	if allocation.ServerID != nil {
		writeError(w, http.StatusConflict, "allocation is already assigned to a server")
		return
	}

	var owner models.User
	if err := a.DB.First(&owner, req.OwnerID).Error; err != nil {
		writeError(w, http.StatusBadRequest, "owner not found")
		return
	}

	server := models.Server{
		UUID:         uuid.NewString(),
		Name:         req.Name,
		OwnerID:      owner.ID,
		NodeID:       node.ID,
		TemplateID:   template.ID,
		AllocationID: allocation.ID,
		MemoryMB:     req.MemoryMB,
		SwapMB:       req.SwapMB,
		DiskMB:       req.DiskMB,
		CPUPercent:   req.CPUPercent,
		Status:       models.ServerStatusInstalling,
	}
	if err := server.SetEnvironment(req.Environment); err != nil {
		writeError(w, http.StatusInternalServerError, "failed to encode environment")
		return
	}

	if err := a.DB.Create(&server).Error; err != nil {
		writeError(w, http.StatusInternalServerError, "failed to create server")
		return
	}

	allocation.ServerID = &server.ID
	if err := a.DB.Save(&allocation).Error; err != nil {
		writeError(w, http.StatusInternalServerError, "failed to reserve allocation")
		return
	}

	vars, err := template.GetVariables()
	if err != nil {
		writeError(w, http.StatusInternalServerError, "failed to read template variables")
		return
	}
	env := req.Environment
	if env == nil {
		env = map[string]string{}
	}
	for _, v := range vars {
		if _, ok := env[v.EnvKey]; !ok && v.Default != "" {
			env[v.EnvKey] = v.Default
		}
	}

	client := agentclient.New(node.BaseURL(), node.AgentSecret)
	if err := client.CreateServer(r.Context(), protocol.CreateServerRequest{
		UUID:        server.UUID,
		Image:       template.DockerImage,
		Startup:     template.StartupCommand,
		StopSignal:  template.StopSignal,
		Environment: env,
		Limits: protocol.ResourceLimits{
			MemoryMB:   server.MemoryMB,
			SwapMB:     server.SwapMB,
			DiskMB:     server.DiskMB,
			CPUPercent: server.CPUPercent,
		},
		Allocation: protocol.PortBinding{IP: allocation.IP, Port: allocation.Port},
	}); err != nil {
		server.Status = models.ServerStatusInstallFailed
		a.DB.Save(&server)
		writeError(w, http.StatusBadGateway, "server record created, but the node agent failed to provision it: "+err.Error())
		return
	}

	writeJSON(w, http.StatusCreated, server)
}

type updateServerRequest struct {
	MemoryMB   int64 `json:"memory_mb"`
	SwapMB     int64 `json:"swap_mb"`
	DiskMB     int64 `json:"disk_mb"`
	CPUPercent int64 `json:"cpu_percent"`
}

// handleUpdateServer resizes a server's resource limits in place. Image
// and startup command changes are intentionally out of scope for v1 --
// they'd require recreating the container -- so this only ever touches
// CPU/memory/swap/disk.
func (a *API) handleUpdateServer(w http.ResponseWriter, r *http.Request) {
	server := serverFromContext(r.Context())

	var req updateServerRequest
	if err := decodeJSON(r, &req); err != nil {
		writeError(w, http.StatusBadRequest, "invalid request body")
		return
	}

	client := agentclient.New(server.Node.BaseURL(), server.Node.AgentSecret)
	if err := client.UpdateServer(r.Context(), server.UUID, protocol.UpdateServerRequest{
		Limits: protocol.ResourceLimits{
			MemoryMB:   req.MemoryMB,
			SwapMB:     req.SwapMB,
			DiskMB:     req.DiskMB,
			CPUPercent: req.CPUPercent,
		},
	}); err != nil {
		writeError(w, http.StatusBadGateway, "node agent request failed: "+err.Error())
		return
	}

	server.MemoryMB = req.MemoryMB
	server.SwapMB = req.SwapMB
	server.DiskMB = req.DiskMB
	server.CPUPercent = req.CPUPercent
	if err := a.DB.Save(server).Error; err != nil {
		writeError(w, http.StatusInternalServerError, "failed to save server")
		return
	}
	writeJSON(w, http.StatusOK, server)
}

func (a *API) handleDeleteServer(w http.ResponseWriter, r *http.Request) {
	server := serverFromContext(r.Context())

	var node models.Node
	if err := a.DB.First(&node, server.NodeID).Error; err == nil {
		client := agentclient.New(node.BaseURL(), node.AgentSecret)
		_ = client.DeleteServer(r.Context(), server.UUID) // best effort; node may already be offline
	}

	a.DB.Model(&models.Allocation{}).Where("server_id = ?", server.ID).Update("server_id", gorm.Expr("NULL"))
	if err := a.DB.Delete(server).Error; err != nil {
		writeError(w, http.StatusInternalServerError, "failed to delete server")
		return
	}
	w.WriteHeader(http.StatusNoContent)
}

func (a *API) handleServerPower(w http.ResponseWriter, r *http.Request) {
	server := serverFromContext(r.Context())

	var req protocol.PowerRequest
	if err := decodeJSON(r, &req); err != nil {
		writeError(w, http.StatusBadRequest, "invalid request body")
		return
	}
	switch req.Action {
	case protocol.PowerStart, protocol.PowerStop, protocol.PowerRestart, protocol.PowerKill:
	default:
		writeError(w, http.StatusBadRequest, "action must be one of start, stop, restart, kill")
		return
	}

	client := agentclient.New(server.Node.BaseURL(), server.Node.AgentSecret)
	if err := client.PowerAction(r.Context(), server.UUID, req.Action); err != nil {
		writeError(w, http.StatusBadGateway, "node agent request failed: "+err.Error())
		return
	}
	w.WriteHeader(http.StatusAccepted)
}

func (a *API) handleServerStats(w http.ResponseWriter, r *http.Request) {
	server := serverFromContext(r.Context())

	client := agentclient.New(server.Node.BaseURL(), server.Node.AgentSecret)
	stats, err := client.Stats(r.Context(), server.UUID)
	if err != nil {
		writeError(w, http.StatusBadGateway, "node agent request failed: "+err.Error())
		return
	}
	writeJSON(w, http.StatusOK, stats)
}

func (a *API) handleServerConsole(w http.ResponseWriter, r *http.Request) {
	server := serverFromContext(r.Context())

	client := agentclient.New(server.Node.BaseURL(), server.Node.AgentSecret)
	consoleURL, err := client.ConsoleURL(server.UUID)
	if err != nil {
		writeError(w, http.StatusInternalServerError, "failed to build console url")
		return
	}

	ws.ProxyConsole(w, r, consoleURL, server.Node.AgentSecret)
}
