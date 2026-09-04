# Week 5 — Containerization / K8s: Docker to Helm — Day-by-Day Plan

> **Milestone:** [05 — Containerization / K8s: Docker to Helm](../milestones/05-containerization-k8s-helm.md)
> **Month:** M2 · **Weeks:** W5–W6 (this plan covers W5, Days 1–7)
> **Pacing note:** The milestone spans W5–W6. This document covers W5. W6 is covered by [Milestone 06 — Infra as Code: Terraform](../milestones/06-infra-as-code-terraform.md).
> **Deliverable:** A running Kind cluster with a Helm chart deploying API + PostgreSQL + Redis, reachable via Ingress, autoscaling via HPA, upgraded and rolled back at least once.

> **⚠️ No `brew install`.** Per Walmart network policy, install `kind`/`kubectl`/`helm` via direct binary download, not Homebrew.

---

## Overview

| Day | Focus | Output |
|-----|-------|--------|
| 1 | Docker fundamentals — multi-stage builds, non-root users, health checks | Corrected `api/Dockerfile` |
| 2 | Docker Compose — multi-service local stack with health-gated startup | `docker-compose.yml` running end-to-end |
| 3 | Kind cluster — multi-node local Kubernetes with ingress support | `kind-config.yaml` + running cluster |
| 4 | Core K8s objects — Deployment, Service, ConfigMap, Secret, PVC | Raw manifests applied with `kubectl` |
| 5 | Helm chart scaffold — templating, subchart dependencies (PostgreSQL, Redis) | `myapp/` chart installs cleanly |
| 6 | Ingress + HPA — external routing and autoscaling, with metrics-server | Working `myapp.local` + live HPA metrics |
| 7 | Deploy, upgrade, rollback, debug | Full deploy cycle + `kubectl logs`/`exec` debugging drill |

---

## Day 1 — Docker Fundamentals: Multi-Stage Builds & Non-Root Users

**Goal:** Understand *why* multi-stage builds and non-root users matter, then build a Dockerfile that actually works (the milestone's version has a permission bug — you'll fix it).

### Why Multi-Stage Builds

A single-stage build ships your compiler, build cache, and dev dependencies into production. Multi-stage separates "build" from "runtime" — smaller image, smaller attack surface.

### Why Non-Root Users

Running as `root` inside a container means a container escape = root on the host. Kubernetes `PodSecurityPolicy`/`SecurityContext` and most client security teams will reject root-running containers outright.

### The Bug in the Milestone's Dockerfile

```dockerfile
# milestones/05 version — BROKEN
FROM python:3.11-slim AS builder
WORKDIR /build
COPY requirements.txt .
RUN pip install --no-cache-dir --user -r requirements.txt

FROM python:3.11-slim AS runtime
WORKDIR /app
COPY --from=builder /root/.local /root/.local   # ← copied into /root
COPY . .
RUN useradd -m appuser && chown -R appuser /app  # ← /app is chowned, /root is NOT
USER appuser                                     # ← appuser can't read /root (mode 0700)
EXPOSE 8000
ENV PATH=/root/.local/bin:$PATH                  # ← wrong PATH for appuser anyway
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]
```

`/root` is mode `0700`, owned by `root`. Once you `USER appuser`, that user cannot traverse into `/root/.local` — `uvicorn` won't be found and the container fails at startup with `exec: "uvicorn": executable file not found in $PATH`.

### The Fix — `api/Dockerfile`

```dockerfile
# Stage 1: Build
FROM python:3.11-slim AS builder
WORKDIR /build
COPY requirements.txt .
RUN pip install --no-cache-dir --user -r requirements.txt

# Stage 2: Runtime
FROM python:3.11-slim AS runtime
WORKDIR /app

# Create non-root user FIRST, so their home dir exists with correct ownership
RUN useradd -m appuser

# Copy installed packages directly into appuser's home, with correct ownership
COPY --from=builder --chown=appuser:appuser /root/.local /home/appuser/.local
COPY --chown=appuser:appuser . .

USER appuser
EXPOSE 8000
ENV PATH=/home/appuser/.local/bin:$PATH

# curl is NOT installed in python:3.11-slim — use Python stdlib for healthcheck instead
HEALTHCHECK --interval=10s --timeout=5s --retries=3 \
  CMD python -c "import urllib.request; urllib.request.urlopen('http://localhost:8000/health')" || exit 1

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]
```

### Build and Verify

```bash
mkdir -p api && cd api
# minimal FastAPI app for testing
cat > main.py <<'EOF'
from fastapi import FastAPI
app = FastAPI()

@app.get("/health")
def health():
    return {"status": "ok"}

@app.get("/ready")
def ready():
    return {"status": "ready"}
EOF
cat > requirements.txt <<'EOF'
fastapi==0.110.0
uvicorn[standard]==0.27.1
EOF

docker build -t myapp-api:local .
docker run --rm -p 8000:8000 myapp-api:local &
sleep 2
curl http://localhost:8000/health   # {"status":"ok"} — proves the PATH/permission fix works
docker stop $(docker ps -q --filter ancestor=myapp-api:local)
```

If you see `exec: "uvicorn": executable file not found in $PATH`, re-check the `--chown` flags and that `useradd` ran before the `COPY --from=builder`.

### Done when
- [ ] Understand why `/root/.local` + `USER appuser` fails (permission model)
- [ ] Corrected Dockerfile builds and runs without permission errors
- [ ] `docker run` + `curl /health` returns `200 {"status":"ok"}`
- [ ] Image inspected with `docker history myapp-api:local` — confirm final stage is small

---

## Day 2 — Docker Compose: Multi-Service Local Stack

**Goal:** Wire up API + PostgreSQL + Redis + worker with health-gated startup ordering, so services never start before their dependencies are ready.

### `docker-compose.yml`

```yaml
version: '3.9'
services:
  api:
    build:
      context: ./api
      dockerfile: Dockerfile
    ports:
      - "8000:8000"
    environment:
      DATABASE_URL: postgresql://app:secret@db:5432/appdb
      REDIS_URL: redis://cache:6379
    depends_on:
      db:
        condition: service_healthy
      cache:
        condition: service_started
    healthcheck:
      test: ["CMD", "python", "-c", "import urllib.request; urllib.request.urlopen('http://localhost:8000/health')"]
      interval: 10s
      timeout: 5s
      retries: 3

  db:
    image: postgres:15-alpine
    environment:
      POSTGRES_DB: appdb
      POSTGRES_USER: app
      POSTGRES_PASSWORD: secret
    volumes:
      - pgdata:/var/lib/postgresql/data
      - ./migrations:/docker-entrypoint-initdb.d
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U app"]
      interval: 5s
      timeout: 3s
      retries: 5

  cache:
    image: redis:7-alpine
    command: redis-server --maxmemory 256mb --maxmemory-policy allkeys-lru

  worker:
    build:
      context: ./worker
    environment:
      DATABASE_URL: postgresql://app:secret@db:5432/appdb
      REDIS_URL: redis://cache:6379
    depends_on: [db, cache]

volumes:
  pgdata:
```

> **Note:** the healthcheck uses `python -c ...`, not `curl` — `python:3.11-slim` doesn't ship `curl` (fixed the same bug from Day 1).

### Minimal Worker Stub

```bash
mkdir -p worker
cat > worker/Dockerfile <<'EOF'
FROM python:3.11-slim
WORKDIR /app
RUN useradd -m worker
COPY --chown=worker:worker . .
USER worker
CMD ["python", "-u", "worker.py"]
EOF
cat > worker/worker.py <<'EOF'
import time
print("Worker started, polling...", flush=True)
while True:
    time.sleep(30)
    print("Worker heartbeat", flush=True)
EOF
```

### Bring the Stack Up

```bash
mkdir -p migrations
docker compose up -d --build
docker compose ps          # all should show "healthy" or "running"
docker compose logs -f api # tail API logs; Ctrl-C to stop tailing

curl http://localhost:8000/health
docker compose down -v     # tear down + remove volumes when done
```

### Done when
- [ ] `docker compose up` brings up all 4 services with correct start order (api waits on db healthy)
- [ ] `docker compose ps` shows `db` and `api` as healthy
- [ ] API reachable at `localhost:8000/health`
- [ ] Understand `depends_on: condition: service_healthy` vs `service_started`

---

## Day 3 — Kind: Local Multi-Node Kubernetes with Ingress Support

**Goal:** Stand up a local Kubernetes cluster that can actually route Ingress traffic — the milestone's `kind-config.yaml` is missing the port mappings and node labels needed for this, so you'll build the corrected version.

### Install Tools (no Homebrew — direct binary download)

```bash
# kind
[ "$(uname -m)" = "x86_64" ] && KIND_ARCH=amd64 || KIND_ARCH=arm64
curl -Lo ./kind "https://kind.sigs.k8s.io/dl/v0.23.0/kind-$(uname | tr '[:upper:]' '[:lower:]')-${KIND_ARCH}"
chmod +x ./kind && sudo mv ./kind /usr/local/bin/kind

# kubectl
curl -LO "https://dl.k8s.io/release/$(curl -L -s https://dl.k8s.io/release/stable.txt)/bin/$(uname | tr '[:upper:]' '[:lower:]')/${KIND_ARCH}/kubectl"
chmod +x ./kubectl && sudo mv ./kubectl /usr/local/bin/kubectl

# helm
curl -fsSL -o get_helm.sh https://raw.githubusercontent.com/helm/helm/main/scripts/get-helm-3
chmod +x get_helm.sh && ./get_helm.sh

kind version && kubectl version --client && helm version
```

### Why the Milestone's `kind-config.yaml` Can't Serve Ingress

```yaml
# milestones/05 version — INSUFFICIENT for ingress
kind: Cluster
apiVersion: kind.x-k8s.io/v1alpha4
nodes:
  - role: control-plane
  - role: worker
  - role: worker
```

This has no `extraPortMappings` binding host ports 80/443 into the cluster, and no `ingress-ready=true` node label — which is what the ingress-nginx Kind manifest checks via `nodeSelector` before scheduling. Without both, Ingress objects will have no controller to serve them.

### The Fix — `kind-config.yaml`

```yaml
kind: Cluster
apiVersion: kind.x-k8s.io/v1alpha4
nodes:
  - role: control-plane
    kubeadmConfigPatches:
      - |
        kind: InitConfiguration
        nodeRegistration:
          kubeletExtraArgs:
            node-labels: "ingress-ready=true"
    extraPortMappings:
      - containerPort: 80
        hostPort: 80
        protocol: TCP
      - containerPort: 443
        hostPort: 443
        protocol: TCP
  - role: worker
  - role: worker
```

### Create the Cluster + Install ingress-nginx

```bash
kind create cluster --name dev-cluster --config kind-config.yaml
kubectl cluster-info --context kind-dev-cluster
kubectl get nodes -o wide

# Install ingress-nginx (Kind-specific manifest, matches the ingress-ready label)
kubectl apply -f https://raw.githubusercontent.com/kubernetes/ingress-nginx/main/deploy/static/provider/kind/deploy.yaml

# Wait for the controller to be ready
kubectl wait --namespace ingress-nginx \
  --for=condition=ready pod \
  --selector=app.kubernetes.io/component=controller \
  --timeout=120s
```

### Load Your Local Image Into Kind

```bash
docker build -t myapp-api:local ./api
kind load docker-image myapp-api:local --name dev-cluster

# Verify it landed
docker exec -it dev-cluster-control-plane crictl images | grep myapp-api
```

### Done when
- [ ] `kubectl get nodes` shows 1 control-plane + 2 workers, all `Ready`
- [ ] `ingress-nginx` controller pod is `Running` and `1/1 Ready`
- [ ] Local image loaded and visible via `crictl images` inside the node
- [ ] Understand why `extraPortMappings` + `ingress-ready=true` are both required (port binding + scheduling)

---

## Day 4 — Core Kubernetes Objects (Raw Manifests)

**Goal:** Before templating with Helm, understand each object type by writing raw YAML and applying it directly. This is what Helm generates under the hood.

### Concepts Table

| Object | Purpose |
|--------|---------|
| `Deployment` | Stateless app pods with rolling updates |
| `StatefulSet` | Databases, ordered pod identity, stable network ID |
| `ConfigMap` | Non-secret config (env vars, files) |
| `Secret` | Sensitive values (passwords, API keys) — base64-encoded, not encrypted by default |
| `PersistentVolumeClaim` | Durable storage for stateful workloads |
| `HorizontalPodAutoscaler` | Scale pods based on CPU/memory/custom metrics |
| `Ingress` | Route external HTTP traffic to services by host/path |
| `ServiceAccount` | Pod-level RBAC identity |

### Raw Manifests — `k8s-raw/`

```yaml
# k8s-raw/namespace.yaml
apiVersion: v1
kind: Namespace
metadata:
  name: app
```

```yaml
# k8s-raw/secret.yaml
apiVersion: v1
kind: Secret
metadata:
  name: myapp-secret
  namespace: app
type: Opaque
stringData:
  database-url: "postgresql://app:secret@myapp-postgresql:5432/appdb"
```

```yaml
# k8s-raw/configmap.yaml
apiVersion: v1
kind: ConfigMap
metadata:
  name: myapp-config
  namespace: app
data:
  LOG_LEVEL: "info"
```

```yaml
# k8s-raw/deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: myapp-api
  namespace: app
spec:
  replicas: 2
  selector:
    matchLabels:
      app: myapp-api
  template:
    metadata:
      labels:
        app: myapp-api
    spec:
      containers:
        - name: api
          image: myapp-api:local
          imagePullPolicy: IfNotPresent
          ports:
            - name: http
              containerPort: 8000
          envFrom:
            - configMapRef:
                name: myapp-config
          env:
            - name: DATABASE_URL
              valueFrom:
                secretKeyRef:
                  name: myapp-secret
                  key: database-url
          livenessProbe:
            httpGet: { path: /health, port: http }
            initialDelaySeconds: 15
            periodSeconds: 10
          readinessProbe:
            httpGet: { path: /ready, port: http }
            initialDelaySeconds: 5
            periodSeconds: 5
```

```yaml
# k8s-raw/service.yaml
apiVersion: v1
kind: Service
metadata:
  name: myapp-api
  namespace: app
spec:
  selector:
    app: myapp-api
  ports:
    - port: 8000
      targetPort: http
  type: ClusterIP
```

### Apply and Inspect

```bash
kubectl apply -f k8s-raw/namespace.yaml
kubectl apply -f k8s-raw/ -n app

kubectl get all -n app
kubectl describe deployment myapp-api -n app
kubectl get events -n app --sort-by='.lastTimestamp'

# Port-forward and test
kubectl port-forward svc/myapp-api 8000:8000 -n app &
curl http://localhost:8000/health
```

> **Note:** this Deployment will `CrashLoopBackOff` or stay `Pending` on readiness until PostgreSQL/Redis exist — that's expected. You're validating manifest syntax and object relationships today; Day 5 wires in real dependencies via Helm subcharts.

### Done when
- [ ] Can explain what each of the 8 object types does without looking it up
- [ ] Raw manifests applied successfully to the `app` namespace
- [ ] `kubectl describe` and `kubectl get events` used to diagnose the expected Pending/CrashLoop state
- [ ] Understand `envFrom.configMapRef` vs `valueFrom.secretKeyRef`

---

## Day 5 — Helm Chart: Scaffold + Subchart Dependencies

**Goal:** Convert the raw manifests into a templated Helm chart with PostgreSQL and Redis as Bitnami subchart dependencies.

### Scaffold

```bash
helm create myapp
rm -rf myapp/templates/tests myapp/templates/NOTES.txt
```

### `Chart.yaml`

```yaml
apiVersion: v2
name: myapp
description: Full-stack application with API, PostgreSQL, and Redis
type: application
version: 0.1.0
appVersion: "1.0.0"
dependencies:
  - name: postgresql
    version: "13.2.0"
    repository: "https://charts.bitnami.com/bitnami"
  - name: redis
    version: "18.4.0"
    repository: "https://charts.bitnami.com/bitnami"
```

### Resolve Dependencies FIRST — Verify Before You Build On Top

```bash
helm repo add bitnami https://charts.bitnami.com/bitnami
helm repo update
helm dependency update ./myapp
```

> **If this fails (network restrictions, pin mismatch, chart removed from Bitnami's index):** don't get stuck. Fallback to plain in-chart manifests for postgres/redis (a `templates/postgres.yaml` + `templates/redis.yaml` using the vanilla `postgres:15-alpine` / `redis:7-alpine` images, no subchart). Note in your deliverable which path you took and why — this is a realistic on-site call an FDE has to make when a client's network blocks external chart repos.

### `values.yaml`

```yaml
replicaCount: 2

api:
  image:
    repository: myapp-api
    tag: local
    pullPolicy: IfNotPresent
  resources:
    requests:
      cpu: "100m"
      memory: "128Mi"
    limits:
      cpu: "500m"
      memory: "512Mi"
  env:
    LOG_LEVEL: info

service:
  type: ClusterIP
  port: 8000

ingress:
  enabled: true
  className: nginx
  host: myapp.local

autoscaling:
  enabled: true
  minReplicas: 2
  maxReplicas: 10
  targetCPUUtilizationPercentage: 70

postgresql:
  auth:
    database: appdb
    username: app
    password: secret
  primary:
    persistence:
      size: 5Gi

redis:
  auth:
    enabled: false
  master:
    persistence:
      size: 1Gi
```

### `templates/secret.yaml` — Missing From the Milestone, Required by the Deployment Template

```yaml
apiVersion: v1
kind: Secret
metadata:
  name: {{ include "myapp.fullname" . }}-secret
  labels:
    {{- include "myapp.labels" . | nindent 4 }}
type: Opaque
stringData:
  database-url: "postgresql://{{ .Values.postgresql.auth.username }}:{{ .Values.postgresql.auth.password }}@{{ .Release.Name }}-postgresql:5432/{{ .Values.postgresql.auth.database }}"
```

### `templates/deployment-api.yaml` — Corrected Service Name References

The milestone's template uses `{{ include "myapp.fullname" . }}-redis-master`, which only resolves correctly when the Helm release name equals the chart name (`myapp`). Bitnami subcharts actually name their services off `.Release.Name`, not the parent chart's fullname helper — so a release named e.g. `myapp-staging` would silently break DNS resolution. Use `.Release.Name` explicitly to match what the subcharts actually create:

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ include "myapp.fullname" . }}-api
  labels:
    {{- include "myapp.labels" . | nindent 4 }}
    app.kubernetes.io/component: api
spec:
  replicas: {{ .Values.replicaCount }}
  selector:
    matchLabels:
      {{- include "myapp.selectorLabels" . | nindent 6 }}
      app.kubernetes.io/component: api
  template:
    metadata:
      labels:
        {{- include "myapp.selectorLabels" . | nindent 8 }}
        app.kubernetes.io/component: api
      annotations:
        # forces pod restart on helm upgrade even if the image tag is unchanged
        checksum/config: {{ include (print $.Template.BasePath "/secret.yaml") . | sha256sum }}
    spec:
      containers:
        - name: api
          image: "{{ .Values.api.image.repository }}:{{ .Values.api.image.tag }}"
          imagePullPolicy: {{ .Values.api.image.pullPolicy }}
          ports:
            - name: http
              containerPort: 8000
          env:
            - name: DATABASE_URL
              valueFrom:
                secretKeyRef:
                  name: {{ include "myapp.fullname" . }}-secret
                  key: database-url
            - name: REDIS_URL
              value: "redis://{{ .Release.Name }}-redis-master:6379"
          resources:
            {{- toYaml .Values.api.resources | nindent 12 }}
          livenessProbe:
            httpGet:
              path: /health
              port: http
            initialDelaySeconds: 15
            periodSeconds: 10
          readinessProbe:
            httpGet:
              path: /ready
              port: http
            initialDelaySeconds: 5
            periodSeconds: 5
```

> **`checksum/config` annotation:** this is the fix for Day 7's upgrade problem — when only a ConfigMap/Secret changes (not the image tag), Kubernetes won't restart pods on its own. The checksum annotation changes whenever the referenced template renders differently, forcing a rolling restart.

### Lint, Template, Install

```bash
helm lint ./myapp
helm template myapp ./myapp | less        # inspect rendered YAML before installing

helm install myapp ./myapp \
  --namespace app --create-namespace \
  --values ./myapp/values.yaml

kubectl get all -n app
kubectl get pods -n app -w      # watch until postgresql/redis/api are all Running
```

### Done when
- [ ] `helm dependency update` succeeds (or documented fallback used)
- [ ] `templates/secret.yaml` created and wired into the Deployment
- [ ] Service name references use `.Release.Name`, not the fullname helper, for subchart DNS
- [ ] `helm install` completes; all pods reach `Running`/`1/1`

---

## Day 6 — Ingress + HorizontalPodAutoscaler (with metrics-server)

**Goal:** Route external traffic through Ingress and get the HPA reading real CPU metrics — both require infrastructure the milestone doesn't mention installing.

### `templates/ingress.yaml`

```yaml
{{- if .Values.ingress.enabled }}
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: {{ include "myapp.fullname" . }}
  annotations:
    nginx.ingress.kubernetes.io/rewrite-target: /
spec:
  ingressClassName: {{ .Values.ingress.className }}
  rules:
    - host: {{ .Values.ingress.host }}
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: {{ include "myapp.fullname" . }}-api
                port:
                  number: {{ .Values.service.port }}
{{- end }}
```

### Point `myapp.local` at the Cluster

```bash
# macOS/Linux — add to /etc/hosts
echo "127.0.0.1 myapp.local" | sudo tee -a /etc/hosts

kubectl get ingress -n app
curl -H "Host: myapp.local" http://localhost/health
# or, once /etc/hosts is set:
curl http://myapp.local/health
```

> This works because Day 3's `kind-config.yaml` mapped container ports 80/443 to the host, and ingress-nginx is bound to those ports inside the cluster.

### Why the Milestone's HPA Won't Show Real Metrics

Kind ships **no metrics-server** by default. Without it, `kubectl get hpa` shows:

```
NAME        REFERENCE              TARGETS         MINPODS   MAXPODS   REPLICAS
myapp-api   Deployment/myapp-api   <unknown>/70%   2         10        2
```

`<unknown>` means the HPA controller has no CPU data to act on — it will never scale.

### Install metrics-server (Kind Needs `--kubelet-insecure-tls`)

```bash
kubectl apply -f https://github.com/kubernetes-sigs/metrics-server/releases/latest/download/components.yaml

# Kind's kubelet certs aren't signed for metrics-server's default verification —
# patch the deployment to skip TLS verification (fine for local dev, NOT for prod)
kubectl patch deployment metrics-server -n kube-system --type='json' \
  -p='[{"op":"add","path":"/spec/template/spec/containers/0/args/-","value":"--kubelet-insecure-tls"}]'

kubectl wait --for=condition=available --timeout=90s deployment/metrics-server -n kube-system
kubectl top nodes    # should show real CPU/memory numbers within ~1 minute
```

### `templates/hpa.yaml`

```yaml
{{- if .Values.autoscaling.enabled }}
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: {{ include "myapp.fullname" . }}-api
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: {{ include "myapp.fullname" . }}-api
  minReplicas: {{ .Values.autoscaling.minReplicas }}
  maxReplicas: {{ .Values.autoscaling.maxReplicas }}
  metrics:
    - type: Resource
      resource:
        name: cpu
        target:
          type: Utilization
          averageUtilization: {{ .Values.autoscaling.targetCPUUtilizationPercentage }}
{{- end }}
```

```bash
helm upgrade myapp ./myapp -n app --values ./myapp/values.yaml
kubectl get hpa -n app -w
# after metrics-server is warmed up, TARGETS should show a real percentage, e.g. 2%/70%
```

### Load-Test to Trigger Scaling (Optional Stretch)

```bash
kubectl run load-gen --image=busybox --restart=Never -n app -- \
  /bin/sh -c "while true; do wget -q -O- http://myapp-api:8000/health; done"

kubectl get hpa -n app -w   # watch replicas climb as CPU rises
kubectl delete pod load-gen -n app   # stop the load generator
```

### Done when
- [ ] `curl myapp.local/health` returns `200` through the Ingress
- [ ] `metrics-server` installed and `kubectl top nodes` returns real numbers
- [ ] `kubectl get hpa -n app` shows a real percentage, not `<unknown>`
- [ ] (Stretch) observed replica count increase under synthetic load

---

## Day 7 — Deploy, Upgrade, Rollback, Debug

**Goal:** Run the full operational lifecycle an FDE performs on-site: deploy, change something, upgrade, verify, break something on purpose, then debug it with `kubectl logs`/`exec`.

### Full Deploy From Scratch

```bash
helm uninstall myapp -n app --ignore-not-found
helm install myapp ./myapp -n app --create-namespace --values ./myapp/values.yaml
kubectl rollout status deployment/myapp-api -n app
```

### Upgrade — Why Bumping Only `values.yaml` Config Sometimes Does Nothing

If you change `api.env.LOG_LEVEL` in `values.yaml` and run `helm upgrade`, the Deployment's pod template changes (via the ConfigMap/Secret), but if you *only* change the image tag field without changing the image itself, Kubernetes has nothing to diff against — pods won't restart. The `checksum/config` annotation added on Day 5 fixes the config-only case; changing the image tag fixes the code case.

```bash
# Simulate a code change: rebuild with a new tag
docker build -t myapp-api:v2 ./api
kind load docker-image myapp-api:v2 --name dev-cluster

helm upgrade myapp ./myapp -n app \
  --set api.image.tag=v2 \
  --values ./myapp/values.yaml

kubectl rollout status deployment/myapp-api -n app
kubectl get pods -n app -o jsonpath='{.items[*].spec.containers[*].image}'
```

### Rollback

```bash
helm history myapp -n app
helm rollback myapp 1 -n app
kubectl rollout status deployment/myapp-api -n app
kubectl get pods -n app -o jsonpath='{.items[*].spec.containers[*].image}'  # confirm reverted to :local
```

### Debugging Drill — Break Something on Purpose

```bash
# 1. Deploy a broken image reference
helm upgrade myapp ./myapp -n app --set api.image.tag=does-not-exist

# 2. Diagnose using the standard FDE toolkit
kubectl get pods -n app                          # ImagePullBackOff
kubectl describe pod <pod-name> -n app            # see the Events section — exact pull error
kubectl logs <pod-name> -n app --previous          # logs from the last crashed container, if any
kubectl get events -n app --sort-by='.lastTimestamp' | tail -20

# 3. Exec into a healthy pod to inspect runtime state
kubectl exec -it <healthy-pod-name> -n app -- /bin/sh
env | grep DATABASE_URL     # confirm secret was mounted correctly
exit

# 4. Fix it
helm rollback myapp -n app
kubectl rollout status deployment/myapp-api -n app
```

### Deliverable Checklist Review

- [ ] Multi-stage Dockerfile with non-root user and working health check
- [ ] Docker Compose with `depends_on` + health checks for all services
- [ ] Kind cluster running with 2+ worker nodes AND ingress-nginx installed
- [ ] Helm chart with PostgreSQL and Redis as subchart dependencies (or documented fallback)
- [ ] `values.yaml` parameterizing image tags, replica counts, resources
- [ ] HPA configured for the API deployment, backed by working metrics-server
- [ ] Ingress routing traffic to the API via `myapp.local`
- [ ] Helm upgrade tested with a new image tag, and rollback tested
- [ ] `kubectl logs`, `kubectl describe`, and `kubectl exec` used for a real debugging drill

### Self-Debrief Questions

1. A client's Kind-equivalent (their internal dev cluster) has no ingress controller installed and IT won't grant you cluster-admin to install one. How do you demo your app to them today?
2. `helm upgrade` completes with `STATUS: deployed` but the pods are still running the old image. What's your first diagnostic command, and why?
3. The HPA shows `<unknown>/70%` in a client's real cluster (not Kind). What's different from your Kind fix, and what would you check first?

---

## Resources Quick Reference

| Day | Key Resource |
|-----|-------------|
| 1 | [Docker Multi-Stage Builds](https://docs.docker.com/build/building/multi-stage/) |
| 2 | [Compose `depends_on` conditions](https://docs.docker.com/compose/compose-file/05-services/#depends_on) |
| 3 | [Kind — Ingress Guide](https://kind.sigs.k8s.io/docs/user/ingress/) |
| 4 | [Kubernetes Concepts Overview](https://kubernetes.io/docs/concepts/) |
| 5 | [Helm — Chart Template Guide](https://helm.sh/docs/chart_template_guide/) |
| 6 | [Kubernetes metrics-server](https://github.com/kubernetes-sigs/metrics-server) |
| 7 | [Helm — Upgrade and Rollback](https://helm.sh/docs/helm/helm_rollback/) |

---

*→ Next: [Milestone 06 — Infra as Code: Terraform](../milestones/06-infra-as-code-terraform.md)*
