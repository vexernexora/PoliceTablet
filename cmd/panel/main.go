// Command canopy is the Canopy panel: the web UI and control-plane API
// that admins and server owners talk to. It never touches Docker directly
// -- that's the node agent's job -- so the panel itself has no special
// host privileges and can run anywhere, including in its own container.
package main

import (
	"flag"
	"fmt"
	"log"
	"net/http"
	"os"

	"github.com/nexora-host/canopy/internal/panel/api"
	"github.com/nexora-host/canopy/internal/panel/auth"
	"github.com/nexora-host/canopy/internal/panel/config"
	"github.com/nexora-host/canopy/internal/panel/database"
	"github.com/nexora-host/canopy/internal/panel/models"
)

const version = "0.1.0"

func main() {
	configPath := os.Getenv("CANOPY_CONFIG")
	if configPath == "" {
		configPath = "config.yml"
	}

	if len(os.Args) > 1 {
		switch os.Args[1] {
		case "createadmin":
			runCreateAdmin(configPath, os.Args[2:])
			return
		case "version":
			fmt.Println("canopy " + version)
			return
		case "serve":
			// fall through to server startup below
		default:
			fmt.Fprintf(os.Stderr, "unknown command %q\n\nUsage: canopy [serve|createadmin|version]\n", os.Args[1])
			os.Exit(1)
		}
	}

	runServe(configPath)
}

func runServe(configPath string) {
	cfg, err := config.Load(configPath)
	if err != nil {
		log.Fatalf("load config: %v", err)
	}

	db, err := database.Open(cfg.Database)
	if err != nil {
		log.Fatalf("open database: %v", err)
	}

	authManager := auth.NewManager(cfg.JWTSecret)
	a := api.New(db, authManager, cfg)

	log.Printf("canopy panel %s listening on %s", version, cfg.Bind)
	if err := http.ListenAndServe(cfg.Bind, a.Router()); err != nil {
		log.Fatalf("server error: %v", err)
	}
}

func runCreateAdmin(configPath string, args []string) {
	fs := flag.NewFlagSet("createadmin", flag.ExitOnError)
	username := fs.String("username", "", "admin username")
	email := fs.String("email", "", "admin email")
	password := fs.String("password", "", "admin password")
	_ = fs.Parse(args)

	if *username == "" || *email == "" || *password == "" {
		fmt.Fprintln(os.Stderr, "usage: canopy createadmin --username=<u> --email=<e> --password=<p>")
		os.Exit(1)
	}
	if len(*password) < 8 {
		fmt.Fprintln(os.Stderr, "password must be at least 8 characters")
		os.Exit(1)
	}

	cfg, err := config.Load(configPath)
	if err != nil {
		log.Fatalf("load config: %v", err)
	}

	db, err := database.Open(cfg.Database)
	if err != nil {
		log.Fatalf("open database: %v", err)
	}

	hash, err := auth.HashPassword(*password)
	if err != nil {
		log.Fatalf("hash password: %v", err)
	}

	user := models.User{Username: *username, Email: *email, PasswordHash: hash, IsAdmin: true}
	if err := db.Create(&user).Error; err != nil {
		log.Fatalf("create admin user: %v", err)
	}

	fmt.Printf("Created admin user %q (%s)\n", user.Username, user.Email)
}
