// Package ws implements the node agent's console websocket endpoint,
// bridging a container's hijacked stdio stream to JSON-framed messages.
package ws

import (
	"log"
	"net/http"

	"github.com/gorilla/websocket"

	"github.com/nexora-host/canopy/internal/agent/docker"
	"github.com/nexora-host/canopy/internal/shared/protocol"
)

var upgrader = websocket.Upgrader{
	ReadBufferSize:  4096,
	WriteBufferSize: 4096,
	// The panel is the only intended caller of this endpoint (over a
	// private node network) and it has already authenticated with the
	// shared node secret before we get here, so origin checking would
	// only add friction, not protection.
	CheckOrigin: func(r *http.Request) bool { return true },
}

// ConsoleHandler upgrades the connection, attaches to the server's
// container stdio, and pumps bytes in both directions until either side
// disconnects.
func ConsoleHandler(manager *docker.Manager, uuid string, w http.ResponseWriter, r *http.Request) {
	conn, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		log.Printf("console: upgrade failed: %v", err)
		return
	}
	defer conn.Close()

	hijacked, err := manager.AttachConsole(r.Context(), uuid)
	if err != nil {
		_ = conn.WriteJSON(protocol.WSMessage{Event: protocol.WSEventError, Payload: "failed to attach to container: " + err.Error()})
		return
	}

	_ = conn.WriteJSON(protocol.WSMessage{Event: protocol.WSEventAuthSuccess})

	done := make(chan struct{})
	go func() {
		defer close(done)
		buf := make([]byte, 4096)
		for {
			n, readErr := hijacked.Reader.Read(buf)
			if n > 0 {
				if writeErr := conn.WriteJSON(protocol.WSMessage{
					Event:   protocol.WSEventConsoleOutput,
					Payload: string(buf[:n]),
				}); writeErr != nil {
					return
				}
			}
			if readErr != nil {
				return
			}
		}
	}()

readLoop:
	for {
		var msg protocol.WSMessage
		if err := conn.ReadJSON(&msg); err != nil {
			break readLoop
		}
		if msg.Event != protocol.WSEventSendCommand {
			continue
		}
		input, ok := msg.Payload.(string)
		if !ok {
			continue
		}
		// Forward raw keystrokes as-is: the container's TTY (allocated in
		// CreateServer) owns line editing and echo, so we must not add or
		// buffer anything here -- that would either double characters or
		// swallow control sequences like Ctrl+C.
		if _, err := hijacked.Conn.Write([]byte(input)); err != nil {
			break readLoop
		}
	}

	// Closing the hijacked stream unblocks the reader goroutine above,
	// whichever side disconnected first.
	hijacked.Close()
	<-done
}
