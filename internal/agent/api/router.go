package api

import (
	"crypto/subtle"
	"net/http"
	"strings"

	"github.com/go-chi/chi/v5"
	"github.com/go-chi/chi/v5/middleware"
)

// Router builds the agent's HTTP handler tree. No timeout middleware is
// installed for the same reason as the panel: it would break hijacked
// connections (console websocket, and the underlying container attach).
func (a *API) Router() http.Handler {
	r := chi.NewRouter()
	r.Use(middleware.Logger)
	r.Use(middleware.Recoverer)

	r.Group(func(r chi.Router) {
		r.Use(a.authMiddleware)

		r.Get("/health", a.handleHealth)

		r.Route("/servers", func(r chi.Router) {
			r.Post("/", a.handleCreateServer)
			r.Route("/{uuid}", func(r chi.Router) {
				r.Get("/", a.handleGetServer)
				r.Patch("/", a.handleUpdateServer)
				r.Delete("/", a.handleDeleteServer)
				r.Post("/power", a.handlePower)
				r.Get("/stats", a.handleStats)
				r.Get("/console", a.handleConsole)
			})
		})
	})

	return r
}

func (a *API) authMiddleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		token := bearerToken(r)
		if token == "" || subtle.ConstantTimeCompare([]byte(token), []byte(a.Secret)) != 1 {
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}
		next.ServeHTTP(w, r)
	})
}

func bearerToken(r *http.Request) string {
	if raw := r.Header.Get("Authorization"); raw != "" {
		parts := strings.SplitN(raw, " ", 2)
		if len(parts) == 2 && strings.EqualFold(parts[0], "Bearer") {
			return parts[1]
		}
	}
	return r.URL.Query().Get("token")
}
