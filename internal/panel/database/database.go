// Package database opens the configured GORM connection and runs
// auto-migrations. Canopy defaults to a pure-Go SQLite driver (no CGO) so
// the panel builds and runs as a single static binary; Postgres is
// available for larger installs.
package database

import (
	"fmt"

	"github.com/glebarez/sqlite"
	"gorm.io/driver/postgres"
	"gorm.io/gorm"
	"gorm.io/gorm/logger"

	"github.com/nexora-host/canopy/internal/panel/config"
	"github.com/nexora-host/canopy/internal/panel/models"
)

func Open(cfg config.DatabaseConfig) (*gorm.DB, error) {
	var dialector gorm.Dialector
	switch cfg.Driver {
	case "postgres":
		if cfg.DSN == "" {
			return nil, fmt.Errorf("database.dsn is required when database.driver is postgres")
		}
		dialector = postgres.Open(cfg.DSN)
	case "sqlite", "":
		path := cfg.Path
		if path == "" {
			path = "canopy.db"
		}
		dialector = sqlite.Open(path)
	default:
		return nil, fmt.Errorf("unsupported database driver %q", cfg.Driver)
	}

	db, err := gorm.Open(dialector, &gorm.Config{
		Logger: logger.Default.LogMode(logger.Warn),
	})
	if err != nil {
		return nil, fmt.Errorf("open database: %w", err)
	}

	if err := db.AutoMigrate(
		&models.User{},
		&models.Node{},
		&models.Allocation{},
		&models.Template{},
		&models.Server{},
	); err != nil {
		return nil, fmt.Errorf("automigrate: %w", err)
	}

	return db, nil
}
