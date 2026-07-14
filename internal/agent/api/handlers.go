package api

import (
	"net/http"

	"github.com/go-chi/chi/v5"

	"github.com/nexora-host/canopy/internal/agent/ws"
	"github.com/nexora-host/canopy/internal/shared/protocol"
)

func (a *API) handleHealth(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, protocol.NodeHealth{
		Version:  a.Version,
		DockerOK: a.Docker.Ping(r.Context()) == nil,
	})
}

func (a *API) handleCreateServer(w http.ResponseWriter, r *http.Request) {
	var req protocol.CreateServerRequest
	if err := decodeJSON(r, &req); err != nil {
		writeError(w, http.StatusBadRequest, "invalid request body")
		return
	}
	if req.UUID == "" || req.Image == "" {
		writeError(w, http.StatusBadRequest, "uuid and image are required")
		return
	}

	if err := a.Docker.CreateServer(r.Context(), req); err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	w.WriteHeader(http.StatusCreated)
}

func (a *API) handleGetServer(w http.ResponseWriter, r *http.Request) {
	uuid := chi.URLParam(r, "uuid")
	stats, err := a.Docker.Stats(r.Context(), uuid)
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	writeJSON(w, http.StatusOK, protocol.AgentServerStatus{UUID: uuid, State: stats.State})
}

func (a *API) handleUpdateServer(w http.ResponseWriter, r *http.Request) {
	uuid := chi.URLParam(r, "uuid")
	var req protocol.UpdateServerRequest
	if err := decodeJSON(r, &req); err != nil {
		writeError(w, http.StatusBadRequest, "invalid request body")
		return
	}
	if err := a.Docker.UpdateServer(r.Context(), uuid, req); err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	w.WriteHeader(http.StatusOK)
}

func (a *API) handleDeleteServer(w http.ResponseWriter, r *http.Request) {
	uuid := chi.URLParam(r, "uuid")
	if err := a.Docker.DeleteServer(r.Context(), uuid); err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	w.WriteHeader(http.StatusNoContent)
}

func (a *API) handlePower(w http.ResponseWriter, r *http.Request) {
	uuid := chi.URLParam(r, "uuid")
	var req protocol.PowerRequest
	if err := decodeJSON(r, &req); err != nil {
		writeError(w, http.StatusBadRequest, "invalid request body")
		return
	}
	if err := a.Docker.Power(r.Context(), uuid, req.Action); err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	w.WriteHeader(http.StatusAccepted)
}

func (a *API) handleStats(w http.ResponseWriter, r *http.Request) {
	uuid := chi.URLParam(r, "uuid")
	stats, err := a.Docker.Stats(r.Context(), uuid)
	if err != nil {
		writeError(w, http.StatusInternalServerError, err.Error())
		return
	}
	writeJSON(w, http.StatusOK, stats)
}

func (a *API) handleConsole(w http.ResponseWriter, r *http.Request) {
	uuid := chi.URLParam(r, "uuid")
	ws.ConsoleHandler(a.Docker, uuid, w, r)
}
