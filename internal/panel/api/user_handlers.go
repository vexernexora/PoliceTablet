package api

import (
	"net/http"

	"github.com/go-chi/chi/v5"

	"github.com/nexora-host/canopy/internal/panel/auth"
	"github.com/nexora-host/canopy/internal/panel/models"
)

type userResponse struct {
	ID       uint   `json:"id"`
	Username string `json:"username"`
	Email    string `json:"email"`
	IsAdmin  bool   `json:"is_admin"`
}

func toUserResponse(u models.User) userResponse {
	return userResponse{ID: u.ID, Username: u.Username, Email: u.Email, IsAdmin: u.IsAdmin}
}

func (a *API) handleMe(w http.ResponseWriter, r *http.Request) {
	var user models.User
	if err := a.DB.First(&user, auth.UserID(r.Context())).Error; err != nil {
		writeError(w, http.StatusNotFound, "user not found")
		return
	}
	writeJSON(w, http.StatusOK, toUserResponse(user))
}

func (a *API) handleListUsers(w http.ResponseWriter, r *http.Request) {
	var users []models.User
	if err := a.DB.Order("id").Find(&users).Error; err != nil {
		writeError(w, http.StatusInternalServerError, "failed to list users")
		return
	}
	out := make([]userResponse, len(users))
	for i, u := range users {
		out[i] = toUserResponse(u)
	}
	writeJSON(w, http.StatusOK, out)
}

type createUserRequest struct {
	Username string `json:"username"`
	Email    string `json:"email"`
	Password string `json:"password"`
	IsAdmin  bool   `json:"is_admin"`
}

func (a *API) handleCreateUser(w http.ResponseWriter, r *http.Request) {
	var req createUserRequest
	if err := decodeJSON(r, &req); err != nil {
		writeError(w, http.StatusBadRequest, "invalid request body")
		return
	}
	if req.Username == "" || req.Email == "" || len(req.Password) < 8 {
		writeError(w, http.StatusBadRequest, "username, email and a password of at least 8 characters are required")
		return
	}

	hash, err := auth.HashPassword(req.Password)
	if err != nil {
		writeError(w, http.StatusInternalServerError, "failed to hash password")
		return
	}

	user := models.User{Username: req.Username, Email: req.Email, PasswordHash: hash, IsAdmin: req.IsAdmin}
	if err := a.DB.Create(&user).Error; err != nil {
		writeError(w, http.StatusConflict, "username or email already exists")
		return
	}

	writeJSON(w, http.StatusCreated, toUserResponse(user))
}

func (a *API) handleDeleteUser(w http.ResponseWriter, r *http.Request) {
	id := chi.URLParam(r, "id")
	if err := a.DB.Delete(&models.User{}, id).Error; err != nil {
		writeError(w, http.StatusInternalServerError, "failed to delete user")
		return
	}
	w.WriteHeader(http.StatusNoContent)
}
