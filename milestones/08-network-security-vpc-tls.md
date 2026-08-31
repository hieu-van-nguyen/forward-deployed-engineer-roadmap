# Milestone 08 — Network Security: Reverse Proxies, TLS & VPC Peering

| Field | Value |
|---|---|
| **Month** | M2 |
| **Weeks** | W7–W8 |
| **Priority** | P3 — Medium |
| **Domain** | Network Security |
| **Objective** | Learn enterprise network traversal: reverse proxies (Nginx), TLS termination, and VPC peering |
| **Key Deliverable** | Architecture diagram & setup guide for secure VPC access |

---

## Why This Matters for FDEs

Client environments are locked down. Traffic routes through reverse proxies, services live in private VPCs, and everything is TLS-terminated at the edge. FDEs must understand how to deploy services that work within these constraints — and be able to explain the architecture to client security teams.

---

## Nginx as Reverse Proxy

### Basic Reverse Proxy Config

```nginx
# /etc/nginx/conf.d/myapp.conf

upstream api_backend {
    # Multiple servers for load balancing
    server api-1:8000 weight=3;
    server api-2:8000 weight=3;
    server api-3:8000 weight=1 backup;

    keepalive 32;  # Persistent connections to backend
}

server {
    listen 80;
    server_name api.myapp.com;
    return 301 https://$host$request_uri;  # Force HTTPS
}

server {
    listen 443 ssl http2;
    server_name api.myapp.com;

    # TLS Configuration
    ssl_certificate     /etc/nginx/ssl/fullchain.pem;
    ssl_certificate_key /etc/nginx/ssl/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256;
    ssl_prefer_server_ciphers off;
    ssl_session_cache   shared:SSL:10m;
    ssl_session_timeout 1d;

    # Security headers
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Content-Security-Policy "default-src 'self'" always;

    # Rate limiting
    limit_req zone=api_limit burst=20 nodelay;
    limit_conn conn_limit_per_ip 10;

    location / {
        proxy_pass http://api_backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        proxy_connect_timeout 5s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;

        proxy_buffering on;
        proxy_buffer_size 16k;
        proxy_buffers 4 16k;
    }

    # Health check endpoint (bypass rate limit)
    location /health {
        access_log off;
        proxy_pass http://api_backend;
    }
}

# Define rate limit zones (in http block)
# limit_req_zone $binary_remote_addr zone=api_limit:10m rate=10r/s;
# limit_conn_zone $binary_remote_addr zone=conn_limit_per_ip:10m;
```

---

## TLS Certificate Management

### Option 1: Let's Encrypt (public-facing)
```bash
# Install certbot
apt-get install certbot python3-certbot-nginx

# Obtain certificate
certbot --nginx -d api.myapp.com -d www.myapp.com

# Auto-renewal (cron)
0 12 * * * /usr/bin/certbot renew --quiet
```

### Option 2: Internal CA (enterprise/air-gapped)
```bash
# Generate internal CA
openssl genrsa -out ca.key 4096
openssl req -new -x509 -days 3650 -key ca.key -out ca.crt \
  -subj "/C=US/O=MyOrg/CN=MyOrg Internal CA"

# Generate server cert signed by internal CA
openssl genrsa -out server.key 2048
openssl req -new -key server.key -out server.csr \
  -subj "/CN=api.internal.myapp.com"
openssl x509 -req -days 365 -in server.csr \
  -CA ca.crt -CAkey ca.key -CAcreateserial \
  -out server.crt \
  -extfile <(printf "subjectAltName=DNS:api.internal.myapp.com")
```

### Option 3: cert-manager in Kubernetes
```yaml
# ClusterIssuer with Let's Encrypt
apiVersion: cert-manager.io/v1
kind: ClusterIssuer
metadata:
  name: letsencrypt-prod
spec:
  acme:
    server: https://acme-v02.api.letsencrypt.org/directory
    email: admin@myapp.com
    privateKeySecretRef:
      name: letsencrypt-prod
    solvers:
      - http01:
          ingress:
            class: nginx

---
# Certificate resource
apiVersion: cert-manager.io/v1
kind: Certificate
metadata:
  name: api-tls
  namespace: production
spec:
  secretName: api-tls-secret
  issuerRef:
    name: letsencrypt-prod
    kind: ClusterIssuer
  dnsNames:
    - api.myapp.com
```

---

## VPC Peering (AWS)

```
VPC A (App): 10.0.0.0/16
VPC B (Data): 10.1.0.0/16

App private subnet (10.0.10.0/24) needs access to RDS in Data VPC
```

### Terraform: VPC Peering

```hcl
# Request peering connection (from App VPC)
resource "aws_vpc_peering_connection" "app_to_data" {
  vpc_id        = aws_vpc.app.id
  peer_vpc_id   = aws_vpc.data.id
  peer_region   = "us-east-1"
  auto_accept   = false  # Must be accepted by peer

  tags = { Name = "app-to-data-peering" }
}

# Accept peering (from Data VPC account/region)
resource "aws_vpc_peering_connection_accepter" "data_accepts" {
  vpc_peering_connection_id = aws_vpc_peering_connection.app_to_data.id
  auto_accept               = true
}

# Route from App private subnet → Data VPC via peering
resource "aws_route" "app_to_data" {
  route_table_id            = aws_route_table.app_private.id
  destination_cidr_block    = "10.1.0.0/16"
  vpc_peering_connection_id = aws_vpc_peering_connection.app_to_data.id
}

# Route from Data private subnet → App VPC (bidirectional)
resource "aws_route" "data_to_app" {
  route_table_id            = aws_route_table.data_private.id
  destination_cidr_block    = "10.0.0.0/16"
  vpc_peering_connection_id = aws_vpc_peering_connection.app_to_data.id
}

# Update RDS security group to allow traffic from App VPC
resource "aws_security_group_rule" "rds_from_app" {
  type              = "ingress"
  from_port         = 5432
  to_port           = 5432
  protocol          = "tcp"
  cidr_blocks       = ["10.0.0.0/16"]
  security_group_id = aws_security_group.rds.id
}
```

---

## Architecture Diagram (Text)

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
                       │ HTTPS (443)
              ┌────────▼────────────────────────┐
              │          VPC A: App (10.0.0.0/16) │
              │                                    │
              │  Public: 10.0.1.0/24 (NAT GW)      │
              │                                    │
              │  Private: 10.0.10.0/24             │
              │  ┌──────────────────┐             │
              │  │  Nginx (reverse   │             │
              │  │  proxy, TLS term) │             │
              │  └────────┬─────────┘             │
              │           │ HTTP (internal)         │
              │  ┌────────▼─────────┐             │
              │  │  API servers     │             │
              │  │  (ECS/K8s pods)  │             │
              │  └────────┬─────────┘             │
              └───────────┼─────────────────────────┘
                          │ VPC Peering
              ┌───────────▼─────────────────────────┐
              │          VPC B: Data (10.1.0.0/16)   │
              │                                      │
              │  Private: 10.1.10.0/24              │
              │  ┌─────────────────┐               │
              │  │  RDS PostgreSQL  │               │
              │  │  (multi-AZ)     │               │
              │  └─────────────────┘               │
              └──────────────────────────────────────┘
```

---

## Mutual TLS (mTLS) — Service-to-Service

```nginx
# Require client certificate for internal service calls
server {
    listen 8443 ssl;
    ssl_certificate     /etc/nginx/ssl/server.crt;
    ssl_certificate_key /etc/nginx/ssl/server.key;
    ssl_client_certificate /etc/nginx/ssl/ca.crt;
    ssl_verify_client on;     # Reject requests without valid client cert

    location / {
        # Pass client cert CN to backend for authorization
        proxy_set_header X-Client-Cert-CN $ssl_client_s_dn_cn;
        proxy_pass http://internal_service;
    }
}
```

---

## Checklist

- [ ] Nginx reverse proxy forwarding to upstream with proper headers
- [ ] TLS 1.2+ enforced, TLS 1.0/1.1 disabled
- [ ] HSTS, X-Content-Type-Options, X-Frame-Options headers added
- [ ] Rate limiting configured (req/s per IP)
- [ ] TLS certificate obtained (Let's Encrypt or internal CA)
- [ ] VPC peering configured between two VPCs with routes
- [ ] Security groups restrict cross-VPC traffic to required ports only
- [ ] Architecture diagram created showing traffic flow
- [ ] Setup guide written with numbered steps (give to client's infra team)

---

## 📚 Recommended Books & Online Resources

### Books

| Title | Author | Why It's Relevant |
|-------|--------|------------------|
| *Computer Networks: A Top-Down Approach* | Kurose & Ross | Foundational networking — TCP/IP, TLS handshake, DNS, HTTP — required context for understanding VPC and reverse proxies |
| *The Practice of Network Security Monitoring* | Richard Bejtlich | Monitoring and defending enterprise networks — directly applicable to understanding what FDEs protect |
| *Nginx: A Practical Guide* | Stephen Corona | Comprehensive Nginx configuration guide covering reverse proxy, load balancing, TLS, and rate limiting |
| *AWS Networking Fundamentals* | Toni Pasanen | VPC design, subnet routing, security groups, NACLs, and peering from an AWS-native perspective |
| *Zero Trust Networks* | Evan Gilman & Doug Barth | The modern enterprise security model — shift from perimeter defense to identity-based access |

### Online Resources

| Resource | URL | Description |
|---------|-----|-------------|
| Nginx Documentation | [nginx.org/en/docs](https://nginx.org/en/docs/) | Complete Nginx reference — directives, modules, and configuration examples |
| Mozilla TLS Configuration Generator | [ssl-config.mozilla.org](https://ssl-config.mozilla.org) | Generate hardened Nginx/Apache TLS configs aligned with Mozilla's security recommendations |
| AWS VPC Documentation | [docs.aws.amazon.com/vpc](https://docs.aws.amazon.com/vpc/latest/userguide/) | Official VPC reference covering subnets, routing, peering, and security groups |
| Let's Encrypt Documentation | [letsencrypt.org/docs](https://letsencrypt.org/docs/) | Free TLS certificate issuance — ACME protocol and certbot usage |
| Qualys SSL Labs | [ssllabs.com/ssltest](https://www.ssllabs.com/ssltest/) | Test your TLS configuration and get a letter grade — essential before any client demo |
| OWASP Cheat Sheet — TLS | [cheatsheetseries.owasp.org](https://cheatsheetseries.owasp.org/cheatsheets/TLS_Cipher_String_Cheat_Sheet.html) | OWASP recommended TLS cipher suites and protocol configurations |

### Courses

| Course | Platform | Focus |
|--------|---------|-------|
| *AWS Certified Solutions Architect Associate* | A Cloud Guru / Udemy | VPC, subnets, security groups, NACLs, and network design on AWS |
| *Networking Fundamentals* | NetworkChuck (YouTube) | Free, entertaining deep-dives on TCP/IP, TLS, DNS, and routing |
| *Linux Networking and Troubleshooting* | Linux Foundation | Practical networking tools: `netstat`, `ss`, `tcpdump`, `nmap` |
