package api

import (
	"net/http"

	"github.com/go-chi/chi/v5"
	"github.com/go-chi/chi/v5/middleware"

	"github.com/nexora-host/canopy/internal/panel/auth"
)

// Router builds the full HTTP handler tree for the panel API.
//
// Deliberately no request-timeout middleware: http.TimeoutHandler-style
// wrappers don't support connection hijacking, which would break the
// websocket console proxy below /api/servers/{uuid}/console.
func (a *API) Router() http.Handler {
	r := chi.NewRouter()
	r.Use(middleware.RequestID)
	r.Use(middleware.RealIP)
	r.Use(middleware.Logger)
	r.Use(middleware.Recoverer)
	r.Use(corsMiddleware)

	r.Get("/healthz", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
	})

	r.Route("/api", func(r chi.Router) {
		r.Post("/auth/login", a.handleLogin)

		r.Group(func(r chi.Router) {
			r.Use(a.Auth.Middleware)

			r.Get("/users/me", a.handleMe)

			r.Route("/servers", func(r chi.Router) {
				r.Get("/", a.handleListServers)
				r.Route("/{uuid}", func(r chi.Router) {
					r.Use(a.serverContext)
					r.Get("/", a.handleGetServer)
					r.Get("/stats", a.handleServerStats)
					r.Post("/power", a.handleServerPower)
					r.Get("/console", a.handleServerConsole)
				})
			})

			r.Route("/admin", func(r chi.Router) {
				r.Use(auth.RequireAdmin)

				r.Route("/users", func(r chi.Router) {
					r.Get("/", a.handleListUsers)
					r.Post("/", a.handleCreateUser)
					r.Delete("/{id}", a.handleDeleteUser)
				})

				r.Route("/nodes", func(r chi.Router) {
					r.Get("/", a.handleListNodes)
					r.Post("/", a.handleCreateNode)
					r.Delete("/{id}", a.handleDeleteNode)
					r.Get("/{id}/health", a.handleNodeHealth)
					r.Post("/{id}/allocations", a.handleCreateAllocations)
				})

				r.Route("/templates", func(r chi.Router) {
					r.Get("/", a.handleListTemplates)
					r.Post("/", a.handleCreateTemplate)
					r.Put("/{id}", a.handleUpdateTemplate)
					r.Delete("/{id}", a.handleDeleteTemplate)
				})

				r.Route("/servers", func(r chi.Router) {
					r.Post("/", a.handleCreateServer)
					r.Route("/{uuid}", func(r chi.Router) {
						r.Use(a.serverContext)
						r.Patch("/", a.handleUpdateServer)
						r.Delete("/", a.handleDeleteServer)
					})
				})
			})
		})
	})

	return r
}

func corsMiddleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Access-Control-Allow-Origin", "*")
		w.Header().Set("Access-Control-Allow-Methods", "GET, POST, PUT, PATCH, DELETE, OPTIONS")
		w.Header().Set("Access-Control-Allow-Headers", "Authorization, Content-Type")
		if r.Method == http.MethodOptions {
			w.WriteHeader(http.StatusNoContent)
			return
		}
		next.ServeHTTP(w, r)
	})
}
