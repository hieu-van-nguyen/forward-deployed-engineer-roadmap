# Week 8 — Network Security: Reverse Proxies, TLS & VPC Peering — Day-by-Day Plan

> **Milestone:** [08 — Network Security: Reverse Proxies, TLS & VPC Peering](../milestones/08-network-security-vpc-tls.md)
> **Month:** M2 · **Weeks:** W7–W8 (this plan covers W8, Days 1–7)
> **Pacing note:** The milestone spans W7–W8. W7 is covered by [Milestone 07 — Enterprise Security: OAuth2/OIDC/SAML](../milestones/07-enterprise-security-oauth2-oidc-saml.md). This document covers W8.
> **Deliverable:** The milestone's key deliverable is **documentation, not code** — an architecture diagram and a numbered, client-facing setup guide for secure VPC access. Day 7 produces that artifact; every earlier day builds the hands-on understanding needed to write it credibly.

> **⚠️ Environment reality check before Day 1:**
> - **Nginx runs in Docker** (`nginx:alpine`), not installed on your laptop — reuses the Week 5 Docker Compose setup, extended with 2–3 `api` replicas so the `upstream` weight/backup/keepalive block is actually exercisable.
> - **Let's Encrypt is read-about-only.** It requires public DNS + inbound port 80 from the internet, and `apt-get install certbot` assumes a Debian/Ubuntu host you don't have. The **internal CA** path (Option 2 in the milestone) or `mkcert` is what you actually build and run this week.
> - **VPC peering is Terraform `validate`/`plan`-only.** Milestone 06 already covers real `apply` against AWS. This week reuses those modules to model a second VPC and a peering connection, checked with `terraform validate` and `terraform plan`, without requiring live AWS credentials or spend. If you do have an AWS sandbox account, `apply` is a stretch goal, not a requirement.
> - **No SSL Labs.** It needs a public hostname. Test TLS configs locally with `curl --cacert` and `testssl.sh` in Docker instead.

---

## Overview

| Day | Focus | Output |
|-----|-------|--------|
| 1 | Nginx reverse proxy in Docker — load balancing, headers | Working `nginx:alpine` container proxying to 3 API replicas |
| 2 | TLS termination with an internal CA | Self-signed CA + server cert, HTTPS verified with `curl --cacert` |
| 3 | Security headers, rate limiting, and the `/health` bypass bug | Hardened server block with correctly scoped rate limiting |
| 4 | VPC peering in Terraform (plan-only) | Two-VPC module composition, `terraform plan` showing peering + routes |
| 5 | mTLS for service-to-service calls | Client-cert-gated internal endpoint, tested with `curl --cert/--key` |
| 6 | cert-manager + Kubernetes TLS (ties to Week 5) | `ClusterIssuer`/`Certificate` manifests reviewed against a real Ingress |
| 7 | Architecture diagram + client-facing setup guide | The milestone's actual deliverable, written and diagrammed |

---

## Day 1 — Nginx Reverse Proxy in Docker: Load Balancing

**Goal:** Get a real reverse proxy routing to multiple backend replicas, so `upstream` weighting, `backup`, and `keepalive` are things you've watched work, not just read.

### Extend Week 5's Compose Stack

```yaml
# docker-compose.yml — add to the Week 5 stack
services:
  api-1:
    build: ./api
    environment:
      INSTANCE_ID: "api-1"
  api-2:
    build: ./api
    environment:
      INSTANCE_ID: "api-2"
  api-3:
    build: ./api
    environment:
      INSTANCE_ID: "api-3"

  nginx:
    image: nginx:alpine
    ports:
      - "8080:80"
    volumes:
      - ./nginx/conf.d:/etc/nginx/conf.d:ro
    depends_on:
      - api-1
      - api-2
      - api-3
```

### Upstream Config — `nginx/conf.d/myapp.conf`

```nginx
upstream api_backend {
    server api-1:8000 weight=3;
    server api-2:8000 weight=3;
    server api-3:8000 weight=1 backup;   # only used if api-1/api-2 are both down

    keepalive 32;   # persistent connections to backend — avoids a TCP handshake per request
}

server {
    listen 80;

    location / {
        proxy_pass http://api_backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        proxy_connect_timeout 5s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
    }
}
```

Have each API instance return its `INSTANCE_ID` in the response body so you can see load balancing happen:

```bash
docker compose up -d
for i in $(seq 1 10); do curl -s http://localhost:8080/ | grep -o 'api-[0-9]'; done
# Expect roughly a 3:3 split between api-1 and api-2, api-3 silent (it's `backup`)

docker compose stop api-1 api-2
curl -s http://localhost:8080/   # now api-3 should answer
```

### Done when
- [ ] Nginx running as `nginx:alpine` in Compose, not installed on the host OS
- [ ] Requests visibly distributed across `api-1`/`api-2` by weight
- [ ] `api-3` (marked `backup`) only answers once the primaries are stopped
- [ ] Can explain what `keepalive 32` avoids on the backend connection

---

## Day 2 — TLS Termination With an Internal CA

**Goal:** Build and trust your own CA locally, issue a server certificate, and terminate TLS at Nginx — the enterprise/air-gapped path (Option 2), since Let's Encrypt's public-DNS + port-80 requirement doesn't fit a local dev environment.

### Generate the Internal CA and Server Cert

```bash
# 1. Internal CA
openssl genrsa -out ca.key 4096
openssl req -new -x509 -days 3650 -key ca.key -out ca.crt \
  -subj "/C=US/O=MyOrg/CN=MyOrg Internal CA"

# 2. Server key + CSR
openssl genrsa -out server.key 2048
openssl req -new -key server.key -out server.csr \
  -subj "/CN=api.internal.myapp.com"
```

> **Gotcha:** the milestone's `-extfile <(printf "subjectAltName=DNS:...")` uses bash process substitution, which fails under `sh -c` inside most containers (`/bin/sh: Syntax error: "(" unexpected`). Write a real extfile instead:

```bash
cat > server.ext <<EOF
subjectAltName=DNS:api.internal.myapp.com
EOF

openssl x509 -req -days 365 -in server.csr \
  -CA ca.crt -CAkey ca.key -CAcreateserial \
  -out server.crt \
  -extfile server.ext
```

### Alternative: `mkcert` (faster for local dev)

```bash
mkcert -install
mkcert api.internal.myapp.com localhost 127.0.0.1
```

### Wire Into Nginx

```nginx
server {
    listen 443 ssl;
    http2 on;                      # separate directive — `listen 443 ssl http2;` is deprecated on nginx ≥ 1.25.1
    server_name api.internal.myapp.com;

    ssl_certificate     /etc/nginx/ssl/server.crt;
    ssl_certificate_key /etc/nginx/ssl/server.key;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256;
    ssl_prefer_server_ciphers off;
    ssl_session_cache   shared:SSL:10m;
    ssl_session_timeout 1d;

    location / {
        proxy_pass http://api_backend;
    }
}

server {
    listen 80;
    server_name api.internal.myapp.com;
    return 301 https://$host$request_uri;
}
```

### Verify — Not SSL Labs (No Public Host)

```bash
docker compose restart nginx
curl --cacert ca.crt https://api.internal.myapp.com:8443/   # trust chain verified against YOUR ca.crt, not the system store

# Deeper TLS config check, containerized (no local install needed)
docker run --rm -ti drwetter/testssl.sh https://api.internal.myapp.com:8443
```

### Done when
- [ ] Internal CA + server cert generated using a real `.ext` file, not process substitution
- [ ] `curl --cacert ca.crt` succeeds against your own CA (and fails without `--cacert`, proving it's not a globally trusted cert)
- [ ] `listen 443 ssl;` + `http2 on;` used, not the deprecated combined form
- [ ] Can explain, in one sentence, why Let's Encrypt wasn't usable here and when you'd actually use it (public-facing host with real DNS)

---

## Day 3 — Security Headers, Rate Limiting, and the `/health` Bypass Bug

**Goal:** Harden the server block correctly — and fix two real bugs in the milestone's own config: rate-limit zones defined only in a comment (never active), and a `/health` location that claims to bypass rate limiting but doesn't.

### Bug 1: Rate Limit Zones Are Commented Out

```nginx
# milestone's version — these two lines are a COMMENT, not config:
# limit_req_zone $binary_remote_addr zone=api_limit:10m rate=10r/s;
# limit_conn_zone $binary_remote_addr zone=conn_limit_per_ip:10m;
```

`limit_req_zone`/`limit_conn_zone` must be defined in the `http` block (not `server`), and as written here they're just prose in a comment — copy this file as-is and `limit_req zone=api_limit ...` in the server block will fail to start Nginx (`unknown "api_limit" zone`) because the zone was never actually declared.

### The Fix — Actually Define the Zones in `http {}`

```nginx
# nginx.conf, in the http {} block (NOT inside server {})
http {
    limit_req_zone $binary_remote_addr zone=api_limit:10m rate=10r/s;
    limit_conn_zone $binary_remote_addr zone=conn_limit_per_ip:10m;
    include /etc/nginx/conf.d/*.conf;
}
```

### Bug 2: `/health` Doesn't Actually Bypass the Rate Limit

```nginx
# milestone's version — comment claims this bypasses rate limiting; it does not
location /health {
    access_log off;
    proxy_pass http://api_backend;
}
```

`limit_req` set at the `server` level is inherited by every location that doesn't redefine it — and **there is no `limit_req off;` directive** in Nginx (unlike `access_log off`). A location that simply omits `limit_req` still inherits the server's merged config, so `/health` is silently rate-limited exactly like every other path, contradicting the comment.

### The Fix — `map` to Give `/health` an Empty (Unlimited) Key

```nginx
# in http {} — an empty key on the rate-limit variable disables limiting for that request
map $request_uri $limit_key {
    /health   "";
    default   $binary_remote_addr;
}
limit_req_zone $limit_key zone=api_limit:10m rate=10r/s;
```

```nginx
server {
    limit_req zone=api_limit burst=20 nodelay;
    limit_conn conn_limit_per_ip 10;

    location / {
        proxy_pass http://api_backend;
        # ... proxy headers from Day 1
    }

    location /health {
        access_log off;
        proxy_pass http://api_backend;   # now genuinely unlimited via the map's empty key
    }
}
```

### Security Headers — What to Keep, Adjust, or Drop

```nginx
add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always;  # think before adding `preload` — see note below
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "DENY" always;
add_header Content-Security-Policy "default-src 'self'" always;   # will break Swagger UI / ReDoc if you use FastAPI's built-in docs
```

- **`preload` is browser-sticky and hard to undo** — submitting a domain to the HSTS preload list gets it baked into browsers for months, even after you remove the header. Don't add `preload` on anything but a fully-committed production domain.
- **`X-XSS-Protection` is deprecated** — modern browsers ignore it and it's been removed from the milestone's header set here; CSP is the actual XSS mitigation now.
- **`default-src 'self'` breaks Swagger UI / ReDoc** (they load fonts/scripts from CDNs) — if you're testing against FastAPI's `/docs`, either scope a looser CSP for that path or expect it to break and know why.

### Verify Rate Limiting Actually Works Now

```bash
docker compose exec nginx nginx -t   # config test — catches the "unknown zone" error if you skip the http{} fix
for i in $(seq 1 30); do curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/; done
# expect a mix of 200s then 503s once the 10r/s burst is exceeded

for i in $(seq 1 30); do curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/health; done
# expect all 200s — /health genuinely unlimited now
```

### Done when
- [ ] `limit_req_zone`/`limit_conn_zone` actually declared in `http {}`, not left as a comment
- [ ] `nginx -t` passes with the zones wired up
- [ ] `/health` proven unlimited via the `map` empty-key trick (not just `access_log off`, which only affects logging)
- [ ] Can explain why `preload`, `X-XSS-Protection`, and a strict CSP each need a second thought before shipping

---

## Day 4 — VPC Peering in Terraform (Plan-Only)

**Goal:** Model a second VPC and a peering connection using Week 6's modules, validated with `terraform validate`/`plan` — no live AWS credentials or spend required. Treat `apply` as a stretch goal only if you have a personal AWS sandbox account.

### Reuse Week 6's VPC Module for a Second VPC

```hcl
# environments/staging/main.tf — add a second VPC alongside the existing one
module "vpc_data" {
  source = "../../modules/vpc"

  name                 = "myapp-data"
  vpc_cidr             = "10.1.0.0/16"
  public_subnet_cidrs  = ["10.1.1.0/24", "10.1.2.0/24"]
  private_subnet_cidrs = ["10.1.10.0/24", "10.1.11.0/24"]
  availability_zones   = ["us-east-1a", "us-east-1b"]
}
```

### Peering — Same Region, So Drop `peer_region`

The milestone's diagram puts both VPCs in `us-east-1`. Setting `peer_region` on a same-region peering connection is unnecessary and, on a cross-account setup, actively wrong if it doesn't match reality — omit it for same-region, same-account peering:

```hcl
resource "aws_vpc_peering_connection" "app_to_data" {
  vpc_id      = module.vpc.vpc_id
  peer_vpc_id = module.vpc_data.vpc_id
  auto_accept = true   # same-account, same-region — can auto-accept directly

  tags = { Name = "app-to-data-peering" }
}

resource "aws_route" "app_to_data" {
  route_table_id            = module.vpc.private_route_table_id
  destination_cidr_block    = module.vpc_data.vpc_cidr
  vpc_peering_connection_id = aws_vpc_peering_connection.app_to_data.id
}

resource "aws_route" "data_to_app" {
  route_table_id            = module.vpc_data.private_route_table_id
  destination_cidr_block    = module.vpc.vpc_cidr
  vpc_peering_connection_id = aws_vpc_peering_connection.app_to_data.id
}
```

> **Cross-region/cross-account note (for when you actually hit this with a client):** if the peer VPC is in a different account or region, you need a second provider alias (`provider "aws" { alias = "peer" ... }`) and the accepter resource must reference it explicitly: `resource "aws_vpc_peering_connection_accepter" "data_accepts" { provider = aws.peer; ... }`. Omitting the alias is a common cause of "peering connection stuck in `pending-acceptance`" tickets.

### Security Group Rule Restricting Cross-VPC Traffic

```hcl
resource "aws_security_group_rule" "rds_from_app" {
  type              = "ingress"
  from_port         = 5432
  to_port           = 5432
  protocol          = "tcp"
  cidr_blocks       = [module.vpc.vpc_cidr]   # only the App VPC's CIDR, not 0.0.0.0/0
  security_group_id = module.vpc_data.rds_security_group_id
}
```

### Validate Without Touching Real AWS

```bash
terraform init
terraform validate
terraform plan   # review the plan output — confirms resource graph and route wiring without creating anything
```

### Done when
- [ ] Second VPC composed from Week 6's existing module — no duplicated VPC logic
- [ ] `peer_region` omitted for same-region peering; cross-region provider-alias requirement understood and written down even if not exercised
- [ ] `terraform validate` and `terraform plan` both succeed
- [ ] Security group rule scoped to the peer VPC's CIDR specifically, not a wildcard

---

## Day 5 — Mutual TLS (mTLS) for Service-to-Service Calls

**Goal:** Require a client certificate — issued from Day 2's internal CA — for a service-to-service endpoint, and verify it with `curl --cert/--key`.

### Issue a Client Certificate From the Same CA

```bash
openssl genrsa -out client.key 2048
openssl req -new -key client.key -out client.csr -subj "/CN=internal-service-a"

cat > client.ext <<EOF
extendedKeyUsage=clientAuth
EOF

openssl x509 -req -days 365 -in client.csr \
  -CA ca.crt -CAkey ca.key -CAcreateserial \
  -out client.crt \
  -extfile client.ext
```

### Nginx mTLS Config

```nginx
server {
    listen 8443 ssl;
    ssl_certificate        /etc/nginx/ssl/server.crt;
    ssl_certificate_key    /etc/nginx/ssl/server.key;
    ssl_client_certificate /etc/nginx/ssl/ca.crt;    # the CA that signed client.crt
    ssl_verify_client      on;                        # reject requests without a valid client cert

    location / {
        proxy_set_header X-Client-Cert-CN $ssl_client_s_dn_cn;   # pass the verified CN to the backend for authz
        proxy_pass http://internal_service;
    }
}
```

### Verify Both Paths

```bash
# Without a client cert — should be rejected (400 Bad Request: No required SSL certificate was sent)
curl --cacert ca.crt https://api.internal.myapp.com:8443/

# With the client cert issued above — should succeed
curl --cacert ca.crt --cert client.crt --key client.key https://api.internal.myapp.com:8443/
```

### Done when
- [ ] Client cert issued from the Day 2 CA with `extendedKeyUsage=clientAuth`
- [ ] Request without a client cert rejected by Nginx
- [ ] Request with `--cert client.crt --key client.key` succeeds and backend receives `X-Client-Cert-CN`
- [ ] Can explain when mTLS is worth the operational cost (service-to-service in a zero-trust mesh) vs. when it's overkill (public-facing APIs, where OAuth2 from Week 7 is the right tool)

---

## Day 6 — cert-manager + Kubernetes TLS (Ties to Week 5)

**Goal:** Read cert-manager's `ClusterIssuer`/`Certificate` resources critically against the Helm-deployed Ingress from Week 5, understanding what changes for TLS once you're in Kubernetes instead of bare Nginx.

### Install cert-manager Into the Kind Cluster From Week 5

```bash
kubectl apply -f https://github.com/cert-manager/cert-manager/releases/latest/download/cert-manager.yaml
kubectl get pods -n cert-manager   # wait for all 3 pods Running
```

### Self-Signed ClusterIssuer (Local Kind Cluster Has No Public DNS)

The milestone's `ClusterIssuer` uses Let's Encrypt's `http01` solver — which needs a publicly reachable Ingress, unavailable on a local Kind cluster. Use a self-signed issuer locally instead, and treat the Let's Encrypt version as what you'd actually configure once deployed behind a real public LB:

```yaml
apiVersion: cert-manager.io/v1
kind: ClusterIssuer
metadata:
  name: selfsigned-local
spec:
  selfSigned: {}
---
apiVersion: cert-manager.io/v1
kind: Certificate
metadata:
  name: api-tls
  namespace: app
spec:
  secretName: api-tls-secret
  issuerRef:
    name: selfsigned-local
    kind: ClusterIssuer
  dnsNames:
    - myapp.local
```

```bash
kubectl apply -f cert-manager-local.yaml
kubectl get certificate -n app        # READY should become True
kubectl describe certificate api-tls -n app   # check Events if it doesn't
```

### Wire the Secret Into Week 5's Ingress

```yaml
# templates/ingress.yaml — add tls: to the existing Ingress from Week 5
spec:
  tls:
    - hosts:
        - myapp.local
      secretName: api-tls-secret
  rules:
    - host: myapp.local
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: myapp-api
                port:
                  number: 8000
```

### Done when
- [ ] cert-manager installed and healthy in the Week 5 Kind cluster
- [ ] `Certificate` resource reaches `READY: True` using a self-signed local issuer
- [ ] Week 5's Ingress updated with a `tls:` block referencing the generated secret
- [ ] Can explain what would change (issuer type, solver) to go from this local setup to a real public Let's Encrypt-backed Ingress

---

## Day 7 — Architecture Diagram + Client-Facing Setup Guide

**Goal:** Produce the milestone's actual deliverable. This is documentation, not code — write it as if handing it to a client's infrastructure/security team who will review it before granting access.

### Architecture Diagram

Redraw (don't just copy) the milestone's text diagram based on what you actually built this week — include the pieces you fixed or changed (e.g., the `map`-based rate-limit exemption for `/health`, the mTLS boundary between services, cert-manager issuing the Ingress cert):

```
                    Internet
                       │
                       ▼
              ┌─────────────────┐
              │   AWS WAF / Shield  │
              └────────┬────────┘
                       │
              ┌────────▼────────┐
              │  Application LB  │
              │  (Public Subnet) │
              └────────┬────────┘
                       │ HTTPS (443, internal CA in dev / Let's Encrypt in prod)
              ┌────────▼────────────────────────┐
              │          VPC A: App (10.0.0.0/16) │
              │  Private: 10.0.10.0/24             │
              │  ┌──────────────────┐             │
              │  │  Nginx (reverse   │             │
              │  │  proxy, TLS term, │             │
              │  │  rate limit)      │             │
              │  └────────┬─────────┘             │
              │           │ mTLS (internal)         │
              │  ┌────────▼─────────┐             │
              │  │  API servers      │             │
              │  │  (K8s pods,       │             │
              │  │  cert-manager TLS)│             │
              │  └────────┬─────────┘             │
              └───────────┼─────────────────────────┘
                          │ VPC Peering (same region, no peer_region)
              ┌───────────▼─────────────────────────┐
              │          VPC B: Data (10.1.0.0/16)   │
              │  Private: 10.1.10.0/24 — RDS          │
              │  SG restricted to 10.0.0.0/16 only    │
              └──────────────────────────────────────┘
```

### Client-Facing Setup Guide — Structure

Write this as numbered steps a client's infra team could follow without you in the room:

1. **Prerequisites** — AWS account access, DNS control for the target domain (or note internal-CA alternative for air-gapped environments), Kubernetes cluster access if applicable.
2. **VPC and networking** — CIDR ranges to allocate, peering requests to raise, security group rules to approve (list exact ports/CIDRs, not "allow all").
3. **TLS certificate provisioning** — decision tree: public-facing → Let's Encrypt/cert-manager; internal-only/air-gapped → internal CA, with instructions for distributing the CA cert to trust stores.
4. **Reverse proxy deployment** — Nginx config checklist: rate limiting zones defined in `http{}`, headers applied, `/health` exempted correctly (reference the Day 3 fix explicitly — this is exactly the kind of subtle misconfiguration a client's security review would catch).
5. **Validation steps** — commands the client team runs to confirm each piece works (`nginx -t`, `curl --cacert`, `terraform plan`, `kubectl get certificate`).
6. **Rollback plan** — what to do if TLS termination breaks traffic (e.g., keep the old HTTP-only Ingress path available briefly during cutover).

### Done when
- [ ] Diagram reflects what was actually built this week, not a copy of the milestone's original
- [ ] Setup guide is numbered, has explicit validation commands per step, and could be followed by someone who wasn't in the room
- [ ] The `/health` rate-limit fix and the `peer_region` omission are called out explicitly as decisions, with the reasoning included
- [ ] Guide includes a rollback step — a client security team will ask about this if it's missing

---

## Resources Quick Reference

| Day | Key Resource |
|-----|-------------|
| 1 | [Nginx `upstream` module docs](https://nginx.org/en/docs/http/ngx_http_upstream_module.html) |
| 2 | [OpenSSL Cookbook — Internal CA](https://www.feistyduck.com/library/openssl-cookbook/) |
| 3 | [Nginx `limit_req` module docs](https://nginx.org/en/docs/http/ngx_http_limit_req_module.html) |
| 4 | [AWS VPC Peering docs](https://docs.aws.amazon.com/vpc/latest/peering/what-is-vpc-peering.html) |
| 5 | [Nginx mTLS / client cert verification](https://nginx.org/en/docs/http/ngx_http_ssl_module.html#ssl_verify_client) |
| 6 | [cert-manager Self-Signed Issuer docs](https://cert-manager.io/docs/configuration/selfsigned/) |
| 7 | [Mozilla TLS Configuration Generator](https://ssl-config.mozilla.org) |

---

*→ Next: [Milestone 09 — Vector Databases](../milestones/09-vector-databases.md)*
