---
title: Reverse proxy & TLS
description: Terminate TLS and forward WebSocket upgrades to Reverb with nginx, Caddy, or Traefik.
---

The Desk's containers speak **plain HTTP** — **TLS/HTTPS is your
responsibility**. Put a reverse proxy in front of the stack to terminate TLS and
route traffic to the two published ports:

- The **web app** on `APP_PORT` (default `80`).
- **Reverb** (WebSockets) on `REVERB_PORT` (default `8080`).

The single hard requirement beyond normal HTTPS termination is that your proxy
**forwards WebSocket upgrade requests** to Reverb. Without it, the app loads but
real-time updates (new messages, typing indicators, presence) never arrive.

Make sure the browser-facing Reverb settings match your proxy — see
[Configuration](/docs/self-hosting/configuration/#reverb-websockets--mind-the-browser-vs-server-split).

## Caddy

Caddy terminates TLS automatically and proxies WebSockets with no extra config:

```caddy
chat.example.com {
	reverse_proxy localhost:80
}

# Reverb on its own subdomain (matches REVERB_HOST_PUBLIC=ws.example.com):
ws.example.com {
	reverse_proxy localhost:8080
}
```

If you instead route Reverb under a path on the same host, proxy that path to
port `8080`; Caddy handles the upgrade headers for you.

## nginx

nginx needs the `Upgrade`/`Connection` headers set explicitly for the WebSocket
location:

```nginx
# App
server {
	listen 443 ssl;
	server_name chat.example.com;
	# ssl_certificate ... ; ssl_certificate_key ... ;

	# Set to your ATTACHMENT_MAX_SIZE_MB (default 25M) plus a little headroom for
	# multipart overhead, or large uploads are rejected by nginx before the app
	# sees them.
	client_max_body_size 30m;

	location / {
		proxy_pass http://127.0.0.1:80;
		proxy_set_header Host $host;
		proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
		proxy_set_header X-Forwarded-Proto $scheme;
	}
}

# Reverb (WebSockets)
server {
	listen 443 ssl;
	server_name ws.example.com;
	# ssl_certificate ... ; ssl_certificate_key ... ;

	location / {
		proxy_pass http://127.0.0.1:8080;
		proxy_http_version 1.1;
		proxy_set_header Upgrade $http_upgrade;
		proxy_set_header Connection "upgrade";
		proxy_set_header Host $host;
		proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
		proxy_set_header X-Forwarded-Proto $scheme;
	}
}
```

## Traefik

If you front the stack with Traefik, add router/service labels (or a dynamic
config) that route `chat.example.com` to the `app` service and your WebSocket
host to the `reverb` service. Traefik forwards WebSocket upgrades automatically
once the service is reachable.

## Upload body size

Message attachments are capped by [`ATTACHMENT_MAX_SIZE_MB`](/docs/reference/environment-variables/#attachments)
(default 25 MB), but that limit only applies once the request reaches the app. Your proxy — and
PHP itself — reject an oversized body first, so raise their limits to your configured cap plus a
little headroom for multipart encoding overhead:

- **nginx:** `client_max_body_size` (shown above) — set it to your cap plus headroom.
- **Caddy / Traefik:** no request-body cap by default, so nothing to change unless you added one.
- **PHP:** `upload_max_filesize` and `post_max_size` must both comfortably exceed the cap.

## Verifying

After wiring up the proxy:

1. Load `APP_URL` over HTTPS — the app should render.
2. Open the browser dev tools **Network → WS** tab; you should see an open
   WebSocket connection to your Reverb host. If it fails to connect or falls
   back repeatedly, re-check the upgrade headers and the `REVERB_*_PUBLIC`
   values.
