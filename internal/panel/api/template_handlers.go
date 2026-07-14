package api

import (
	"net/http"

	"github.com/go-chi/chi/v5"

	"github.com/nexora-host/canopy/internal/panel/models"
)

type templateRequest struct {
	Name           string                    `json:"name"`
	Description    string                    `json:"description"`
	DockerImage    string                    `json:"docker_image"`
	StartupCommand string                    `json:"startup_command"`
	StopSignal     string                    `json:"stop_signal"`
	Variables      []models.TemplateVariable `json:"variables"`
}

func (a *API) handleListTemplates(w http.ResponseWriter, r *http.Request) {
	var templates []models.Template
	if err := a.DB.Order("id").Find(&templates).Error; err != nil {
		writeError(w, http.StatusInternalServerError, "failed to list templates")
		return
	}
	writeJSON(w, http.StatusOK, templates)
}

func (a *API) handleCreateTemplate(w http.ResponseWriter, r *http.Request) {
	var req templateRequest
	if err := decodeJSON(r, &req); err != nil {
		writeError(w, http.StatusBadRequest, "invalid request body")
		return
	}
	if req.Name == "" || req.DockerImage == "" || req.StartupCommand == "" {
		writeError(w, http.StatusBadRequest, "name, docker_image and startup_command are required")
		return
	}
	if req.StopSignal == "" {
		req.StopSignal = "SIGTERM"
	}

	tmpl := models.Template{
		Name:           req.Name,
		Description:    req.Description,
		DockerImage:    req.DockerImage,
		StartupCommand: req.StartupCommand,
		StopSignal:     req.StopSignal,
	}
	if err := tmpl.SetVariables(req.Variables); err != nil {
		writeError(w, http.StatusInternalServerError, "failed to encode variables")
		return
	}
	if err := a.DB.Create(&tmpl).Error; err != nil {
		writeError(w, http.StatusConflict, "a template with that name already exists")
		return
	}
	writeJSON(w, http.StatusCreated, tmpl)
}

func (a *API) handleUpdateTemplate(w http.ResponseWriter, r *http.Request) {
	id := chi.URLParam(r, "id")
	var tmpl models.Template
	if err := a.DB.First(&tmpl, id).Error; err != nil {
		writeError(w, http.StatusNotFound, "template not found")
		return
	}

	var req templateRequest
	if err := decodeJSON(r, &req); err != nil {
		writeError(w, http.StatusBadRequest, "invalid request body")
		return
	}
	if req.Name == "" || req.DockerImage == "" || req.StartupCommand == "" {
		writeError(w, http.StatusBadRequest, "name, docker_image and startup_command are required")
		return
	}

	tmpl.Name = req.Name
	tmpl.Description = req.Description
	tmpl.DockerImage = req.DockerImage
	tmpl.StartupCommand = req.StartupCommand
	if req.StopSignal != "" {
		tmpl.StopSignal = req.StopSignal
	}
	if err := tmpl.SetVariables(req.Variables); err != nil {
		writeError(w, http.StatusInternalServerError, "failed to encode variables")
		return
	}

	if err := a.DB.Save(&tmpl).Error; err != nil {
		writeError(w, http.StatusInternalServerError, "failed to update template")
		return
	}
	writeJSON(w, http.StatusOK, tmpl)
}

func (a *API) handleDeleteTemplate(w http.ResponseWriter, r *http.Request) {
	id := chi.URLParam(r, "id")
	if err := a.DB.Delete(&models.Template{}, id).Error; err != nil {
		writeError(w, http.StatusInternalServerError, "failed to delete template")
		return
	}
	w.WriteHeader(http.StatusNoContent)
}
