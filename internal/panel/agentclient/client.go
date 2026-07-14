// Package agentclient is the panel's HTTP client for talking to node
// agents. The panel always initiates these requests; agents never call
// back into the panel, which keeps the protocol one-directional and easy
// to reason about.
package agentclient

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"time"

	"github.com/nexora-host/canopy/internal/shared/protocol"
)

type Client struct {
	baseURL    string
	secret     string
	httpClient *http.Client
}

func New(baseURL, secret string) *Client {
	return &Client{
		baseURL:    baseURL,
		secret:     secret,
		httpClient: &http.Client{Timeout: 15 * time.Second},
	}
}

func (c *Client) CreateServer(ctx context.Context, req protocol.CreateServerRequest) error {
	return c.do(ctx, http.MethodPost, "/servers", req, nil)
}

func (c *Client) UpdateServer(ctx context.Context, uuid string, req protocol.UpdateServerRequest) error {
	return c.do(ctx, http.MethodPatch, "/servers/"+uuid, req, nil)
}

func (c *Client) DeleteServer(ctx context.Context, uuid string) error {
	return c.do(ctx, http.MethodDelete, "/servers/"+uuid, nil, nil)
}

func (c *Client) PowerAction(ctx context.Context, uuid string, action protocol.PowerAction) error {
	return c.do(ctx, http.MethodPost, "/servers/"+uuid+"/power", protocol.PowerRequest{Action: action}, nil)
}

func (c *Client) Stats(ctx context.Context, uuid string) (*protocol.ServerStats, error) {
	var stats protocol.ServerStats
	if err := c.do(ctx, http.MethodGet, "/servers/"+uuid+"/stats", nil, &stats); err != nil {
		return nil, err
	}
	return &stats, nil
}

func (c *Client) Health(ctx context.Context) (*protocol.NodeHealth, error) {
	var health protocol.NodeHealth
	if err := c.do(ctx, http.MethodGet, "/health", nil, &health); err != nil {
		return nil, err
	}
	return &health, nil
}

// ConsoleURL returns the websocket URL for a server's console on this
// agent, for the panel to proxy browser connections into.
func (c *Client) ConsoleURL(uuid string) (string, error) {
	u, err := url.Parse(c.baseURL)
	if err != nil {
		return "", err
	}
	if u.Scheme == "https" {
		u.Scheme = "wss"
	} else {
		u.Scheme = "ws"
	}
	u.Path = "/servers/" + uuid + "/console"
	return u.String(), nil
}

func (c *Client) do(ctx context.Context, method, path string, body, out interface{}) error {
	var reader io.Reader
	if body != nil {
		b, err := json.Marshal(body)
		if err != nil {
			return err
		}
		reader = bytes.NewReader(b)
	}

	req, err := http.NewRequestWithContext(ctx, method, c.baseURL+path, reader)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+c.secret)
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return fmt.Errorf("agent request failed: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode >= 400 {
		data, _ := io.ReadAll(resp.Body)
		return fmt.Errorf("agent returned %d: %s", resp.StatusCode, string(data))
	}

	if out != nil {
		if err := json.NewDecoder(resp.Body).Decode(out); err != nil {
			return fmt.Errorf("decode agent response: %w", err)
		}
	}
	return nil
}
