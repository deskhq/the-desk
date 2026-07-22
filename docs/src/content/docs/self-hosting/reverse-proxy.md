---
title: Reverse proxy & TLS
description: Terminate TLS and forward WebSocket upgrades to Reverb with nginx, Caddy, or Traefik.
---

The Desk's containers speak **plain HTTP** — **TLS/HTTPS is your
responsibility**. Put a reverse proxy in front of the stack to terminate TLS and
route traffic to the two published ports:

- The **web app** on `APP_PORT` (default `8000`).
- **Reverb** (WebSockets) on `REVERB_PORT` (default `8080`).

Both publish to **loopback** by default (`APP_BIND=127.0.0.1`) since they speak
plain HTTP — a host-based proxy connects to `127.0.0.1:8000` / `127.0.0.1:8080`.
If your proxy runs **inside** the compose network instead (e.g. a Caddy container
on the same network), target the service names `app:8080` / `reverb:8080` and you
don't need any host publishing at all.

> **HTTPS URLs:** the app trusts your proxy's `X-Forwarded-*` headers out of the
> box, so it generates `https://` links from a `X-Forwarded-Proto: https` request.
> You only need your proxy to **forward those headers** — Caddy and Traefik do
> automatically; the nginx example below sets `X-Forwarded-Proto`. Without them the
> app would emit `http://` links on an `https://` page and the browser blocks them
> as mixed content (login/registration break).

The single hard requirement beyond normal HTTPS termination is that your proxy
**forwards WebSocket upgrade requests** to Reverb. Without it, the app loads but
real-time updates (new messages, typing indicators, presence) never arrive.

> **Voice messages need HTTPS.** Browsers only grant microphone access in a
> [secure context](https://developer.mozilla.org/en-US/docs/Web/Security/Secure_Contexts),
> so the composer's mic button is **hidden entirely** on a plain-HTTP origin
> (`localhost` excepted). There is no setting to turn it on — terminate TLS and
> it appears. Playing and uploading audio files works either way.

Make sure the browser-facing Reverb settings match your proxy — see
[Configuration](/self-hosting/configuration/#reverb-websockets--mind-the-browser-vs-server-split).

## Caddy

Caddy terminates TLS automatically and proxies WebSockets with no extra config:

```caddy
chat.example.com {
	reverse_proxy localhost:8000
}

# Reverb on its own subdomain (matches REVERB_HOST_PUBLIC=ws.example.com):
ws.example.com {
	reverse_proxy localhost:8080
}
```

If you instead route Reverb under a path on the same host, proxy that path to
port `8080`; Caddy handles the upgrade headers for you.

### Caddy inside the compose network (single domain)

If you'd rather run Caddy as a container on the app's Docker network, reference the
services by name and split the Reverb WebSocket path (`/app/*`) off to `reverb`.
The app then needs no host-published ports:

```caddy
chat.example.com {
	# Reverb WebSocket (browser connects to wss://…/app/{key}).
	@reverb path /app/* /apps/*
	reverse_proxy @reverb reverb:8080

	reverse_proxy app:8080
}
```

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
		proxy_pass http://127.0.0.1:8000;
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

:::tip[Running a PaaS?]
Dokploy and Coolify run their own Traefik and attach your containers to it, so
you configure domains in their UI rather than writing labels. The repository
ships `docker-compose.dokploy.yml` for that shape, and
[Deploying on Dokploy](/self-hosting/dokploy/) walks through the domain rows,
including the one that routes `/app` to Reverb on a single hostname.
:::

## Upload body size

Message attachments are capped by [`ATTACHMENT_MAX_SIZE_MB`](/reference/environment-variables/#attachments)
(default 25 MB), but that limit only applies once the request reaches the app. Your proxy — and
PHP itself — reject an oversized body first, so raise their limits to your configured cap plus a
little headroom for multipart encoding overhead:

- **nginx:** `client_max_body_size` (shown above) — set it to your cap plus headroom.
- **Caddy / Traefik:** no request-body cap by default, so nothing to change unless you added one.
- **PHP:** `upload_max_filesize` and `post_max_size` must both comfortably exceed the cap.

## HSTS

Once TLS is terminated, the app sends
`Strict-Transport-Security: max-age=31536000; includeSubDomains` on every
response that arrived over HTTPS, so a browser that has seen your instance once
refuses to speak plain HTTP to it again. Nothing to configure — it rides on the
same `X-Forwarded-Proto: https` your proxy already sets, and is withheld
entirely on plain-HTTP requests so a `http://` deployment cannot lock itself out
of its own hostname.

If your proxy already sends the header, set `HSTS_ENABLED=false` so the two do
not disagree about the `max-age`. The knobs (`HSTS_MAX_AGE`,
`HSTS_INCLUDE_SUBDOMAINS`, and the deliberately opt-in `HSTS_PRELOAD`) are in
[Feature toggles → HTTPS enforcement (HSTS)](/reference/feature-toggles/#https-enforcement-hsts).

Session cookies pick up the matching `Secure` flag automatically when `APP_URL`
is an `https://` URL — see
[`SESSION_SECURE_COOKIE`](/reference/environment-variables/#session-cookies).

## Verifying

After wiring up the proxy:

1. Load `APP_URL` over HTTPS — the app should render.
2. Open the browser dev tools **Network → WS** tab; you should see an open
   WebSocket connection to your Reverb host. If it fails to connect or falls
   back repeatedly, re-check the upgrade headers and the `REVERB_*_PUBLIC`
   values. A persistent **"Reconnecting…"** banner has its own walkthrough in
   [Troubleshooting](/self-hosting/troubleshooting/#reconnecting-after-login--websocket-wont-connect).
