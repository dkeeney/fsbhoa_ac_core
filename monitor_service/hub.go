package main

import (
	"crypto/tls"
        "encoding/json"
	"log"
	"net/http"
	"sync"
)

// Hub maintains the set of active clients and broadcasts messages to them.
type Hub struct {
	clients         map[*Client]bool
	broadcastC      chan []byte
        commandC        chan string
	register        chan *Client
	unregister      chan *Client
	mu              sync.Mutex
	config          *Config // Add config to Hub
}

// NewHub creates a new, un-run Hub.
func NewHub(config *Config) *Hub { // Accept config
	return &Hub{
		clients:    make(map[*Client]bool),
		broadcastC: make(chan []byte),
                commandC:   make(chan string),
		register:   make(chan *Client),
		unregister: make(chan *Client),
		config:     config, // Store config
	}
}

// WSMessage defines the structure of messages sent over the WebSocket.
type WSMessage struct {
	Type    string      `json:"messageType"`
	Payload interface{} `json:"payload"`
}

// Run starts the hub's event loop.
    func (h *Hub) Run() {
        for {
            select {
            case client := <-h.register:
                h.mu.Lock()
                h.clients[client] = true
                h.mu.Unlock()
    
                // Create a config payload for the newly connected client
                configPayload := map[string]interface{}{
                    "photoEventLimit": h.config.PhotoEventLimit,
                }
                msg := WSMessage{
                    Type:    "initialConfig",
                    Payload: configPayload,
                }
    
                // Marshal and send the config message
                if data, err := json.Marshal(msg); err == nil {
                    client.send <- data
                } else {
                    log.Printf("ERROR: Could not marshal initial config: %v", err)
                }
    
                // Trigger a poll on new client connection
                go h.triggerImmediatePoll()
    
            // The 'register' case ends here.
    
            case client := <-h.unregister:
                h.mu.Lock()
                if _, ok := h.clients[client]; ok {
                    delete(h.clients, client)
                    close(client.send)
                }
                h.mu.Unlock()
    
            case data := <-h.broadcastC:
                h.mu.Lock()
                for client := range h.clients {
                    select {
                    case client.send <- data:
                    default:
                        close(client.send)
                        delete(h.clients, client)
                    }
                }
                h.mu.Unlock()
            
            //  Handle commands from clients (e.g. Dashboard refresh)
            case cmd := <-h.commandC:
		if cmd == "request_status_poll" {
			log.Println("INFO: Processing status poll request.")
			// The event service polls hardware gates and kiosk stations.
			go h.triggerImmediatePoll()
		}
            }
        }
    }

// Broadcast sends a message to all connected clients.
func (h *Hub) Broadcast(data []byte) {
	h.broadcastC <- data
}

// triggerImmediatePoll sends a request to the event_service to trigger a poll.
func (h *Hub) triggerImmediatePoll() {
	if h.config.EventServiceURL == "" {
		log.Println("WARN: EventServiceURL not configured. Cannot trigger initial poll.")
		return
	}

	pollURL := h.config.EventServiceURL + "/trigger-poll"
	log.Printf("INFO: New client connected. Triggering immediate poll via: %s", pollURL)

	// Create a client that skips TLS verification for the internal call
	tr := &http.Transport{
		TLSClientConfig: &tls.Config{InsecureSkipVerify: true},
	}
	client := &http.Client{Transport: tr}

	resp, err := client.Post(pollURL, "application/json", nil)
	if err != nil {
		log.Printf("ERROR: Failed to trigger poll on event_service: %v", err)
		return
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		log.Printf("ERROR: Event_service poll trigger returned non-200 status: %s", resp.Status)
	}
}
