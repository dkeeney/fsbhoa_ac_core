package main

import (
	"encoding/json"
	"fmt"
	"io/ioutil"
	"log"
	"net/http"
)

type NotifyPayload struct {
	EventID int `json:"event_id"`
}



func NotifyHandler(config *Config, hub *Hub) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		var payload NotifyPayload
		if err := json.NewDecoder(r.Body).Decode(&payload); err != nil {
			http.Error(w, "Invalid JSON", http.StatusBadRequest)
			return
		}

		// Fetch the event from WordPress by ID
		event, err := fetchEventFromWordPress(config, payload.EventID)
		if err != nil {
			log.Printf("Failed to fetch event from WordPress: %v", err)
			http.Error(w, "Failed to fetch event", http.StatusInternalServerError)
			return
		}
        

		// Broadcast to all connected WebSocket clients
        msg := WSMessage{
           Type:    "accessEvent",
           Payload: event,
       }
       data, err := json.Marshal(msg)
       if err != nil {
           log.Printf("Failed to marshal WebSocket message: %v", err)
           http.Error(w, "Failed to marshal event", http.StatusInternalServerError)
           return
       }
       hub.Broadcast(data)

		w.WriteHeader(http.StatusOK)
		w.Write([]byte("OK"))
	}
}

func fetchEventFromWordPress(config *Config, eventID int) (map[string]interface{}, error) {
	url := fmt.Sprintf("%s?record_id=%d", config.WordPressAPI, eventID)
	req, err := http.NewRequest("GET", url, nil)
	if err != nil {
		return nil, err
	}
	if config.WordPressToken != "" {
		req.Header.Set("Authorization", "Bearer "+config.WordPressToken)
	}
	// /monitor/event requires the Access Verification API Key
	req.Header.Set("X-API-KEY", config.APIKey)
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		b, _ := ioutil.ReadAll(resp.Body)
		return nil, fmt.Errorf("WordPress returned %d: %s", resp.StatusCode, string(b))
	}
	var event map[string]interface{}
	err = json.NewDecoder(resp.Body).Decode(&event)
	return event, err
}


// GateStatusPayload defines the structure for a single gate's status update.
type GateStatusPayload struct {
	DoorRecordID int    `json:"doorRecordId"`
	Status       string `json:"status"`
}

func UpdateGateStatusHandler(hub *Hub) http.HandlerFunc {
    return func(w http.ResponseWriter, r *http.Request) {
        log.Printf("INFO: Received request on /update-gate-status from %s", r.RemoteAddr)

        // Read the raw body first for debugging
        body, err := ioutil.ReadAll(r.Body)
        if err != nil {
            log.Printf("ERROR: Could not read request body: %v", err)
            http.Error(w, "Can't read body", http.StatusBadRequest)
            return
        }
        log.Printf("DEBUG: Raw body received on /update-gate-status: %s", string(body))

        // Now, try to decode it
        var payload GateStatusPayload
        if err := json.Unmarshal(body, &payload); err != nil {
            log.Printf("ERROR: Could not unmarshal JSON: %v", err)
            http.Error(w, "Invalid JSON", http.StatusBadRequest)
            return
        }

        // Wrap it in a WSMessage and broadcast to all clients
        msg := WSMessage{
            Type:    "gateStatus",
            Payload: payload,
        }
        data, err := json.Marshal(msg)
        if err != nil {
            log.Printf("Failed to marshal gate status message: %v", err)
            return
        }
        hub.Broadcast(data)

        w.WriteHeader(http.StatusOK)
    }
}

