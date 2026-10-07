package main

import (
	"encoding/json"
	"os"
)

type Config struct {
    ListenAddr      string `json:"listen_addr"`
    WordPressAPI    string `json:"wordpress_api"` // e.g., "http://127.0.0.1/wp-json/fsbhoa/v1/monitor/event"
    TlsCertPath     string `json:"tls_cert_path"` 
    TlsKeyPath      string `json:"tls_key_path"`  
    WordPressToken  string `json:"wordpress_token,omitempty"` // If you need auth
    EventServiceURL string `json:"event_service_url"`
    PhotoEventLimit int    `json:"photo_event_limit,omitempty"`
}

func LoadConfig(filename string) (*Config, error) {
	f, err := os.Open(filename)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	var cfg Config
	err = json.NewDecoder(f).Decode(&cfg)
	return &cfg, err
}

