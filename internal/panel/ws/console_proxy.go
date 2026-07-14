// Package ws bridges a browser's console websocket connection to the
// owning node agent's console websocket, so the panel never has to buffer
// or understand console traffic -- it just pipes bytes both ways.
package ws

import (
	"log"
	"net/http"
	"net/url"

	"github.com/gorilla/websocket"
)

var upgrader = websocket.Upgrader{
	ReadBufferSize:  4096,
	WriteBufferSize: 4096,
	// The panel is the only intended embedder of this endpoint and auth
	// already happened via JWT before we get here, so origin checking
	// doesn't add meaningful protection -- skip it rather than force
	// operators to configure allowed origins for a same-app websocket.
	CheckOrigin: func(r *http.Request) bool { return true },
}

// ProxyConsole upgrades the incoming browser connection and bridges it to
// the node agent's console websocket at agentURL, authenticating to the
// agent with secret via a `token` query parameter.
func ProxyConsole(w http.ResponseWriter, r *http.Request, agentURL, secret string) {
	browserConn, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		log.Printf("console proxy: upgrade browser conn: %v", err)
		return
	}
	defer browserConn.Close()

	u, err := url.Parse(agentURL)
	if err != nil {
		_ = browserConn.WriteMessage(websocket.TextMessage, []byte(`{"event":"error","payload":"invalid agent url"}`))
		return
	}
	q := u.Query()
	q.Set("token", secret)
	u.RawQuery = q.Encode()

	agentConn, _, err := websocket.DefaultDialer.Dial(u.String(), nil)
	if err != nil {
		_ = browserConn.WriteMessage(websocket.TextMessage, []byte(`{"event":"error","payload":"node agent unreachable"}`))
		return
	}
	defer agentConn.Close()

	errCh := make(chan error, 2)
	go func() { errCh <- copyMessages(agentConn, browserConn) }()
	go func() { errCh <- copyMessages(browserConn, agentConn) }()
	// Either direction closing ends the session; the deferred Close calls
	// above unblock whichever goroutine is still reading.
	<-errCh
}

func copyMessages(src, dst *websocket.Conn) error {
	for {
		msgType, data, err := src.ReadMessage()
		if err != nil {
			return err
		}
		if err := dst.WriteMessage(msgType, data); err != nil {
			return err
		}
	}
}
