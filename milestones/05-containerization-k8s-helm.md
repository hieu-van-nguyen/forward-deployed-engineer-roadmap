# Milestone 05 — Containerization / K8s: Docker to Helm

| Field | Value |
|---|---|
| **Month** | M2 |
| **Weeks** | W5–W6 |
| **Priority** | P1 — Critical |
| **Domain** | Containerization / K8s |
| **Objective** | Containerize multi-service apps with Docker Compose and migrate to local Kubernetes (Kind/Helm) |
| **Key Deliverable** | Local Helm chart deploying API, DB, and caching layer |

---

## Why This Matters for FDEs

Client environments are Kubernetes-based. FDEs must containerize new integrations, diagnose pod issues on-site, and deploy services quickly. Being able to go from `docker-compose up` to a production-ready Helm chart in a day is a core FDE competency.

---

## Phase 1: Docker Compose (Development)

```yaml
# docker-compose.yml — full-stack app
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
      test: ["CMD", "curl", "-f", "http://localhost:8000/health"]
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

### Production Dockerfile (Multi-stage)

```dockerfile
# api/Dockerfile
# Stage 1: Build
FROM python:3.11-slim AS builder
WORKDIR /build
COPY requirements.txt .
RUN pip install --no-cache-dir --user -r requirements.txt

# Stage 2: Runtime
FROM python:3.11-slim AS runtime
WORKDIR /app
# Copy installed packages from builder
COPY --from=builder /root/.local /root/.local
COPY . .
# Non-root user for security
RUN useradd -m appuser && chown -R appuser /app
USER appuser
EXPOSE 8000
ENV PATH=/root/.local/bin:$PATH
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]
```

---

## Phase 2: Local Kubernetes with Kind

```bash
# Install kind and kubectl
brew install kind kubectl helm

# Create a multi-node cluster
kind create cluster --name dev-cluster --config kind-config.yaml
```

```yaml
# kind-config.yaml
kind: Cluster
apiVersion: kind.x-k8s.io/v1alpha4
nodes:
  - role: control-plane
  - role: worker
  - role: worker
```

```bash
# Load your local Docker image into Kind
docker build -t myapp-api:local ./api
kind load docker-image myapp-api:local --name dev-cluster

# Verify cluster
kubectl cluster-info --context kind-dev-cluster
kubectl get nodes
```

---

## Phase 3: Helm Chart

```bash
# Scaffold the chart
helm create myapp
```

```
myapp/
├── Chart.yaml
├── values.yaml
├── templates/
│   ├── _helpers.tpl
│   ├── deployment-api.yaml
│   ├── deployment-worker.yaml
│   ├── service-api.yaml
│   ├── configmap.yaml
│   ├── secret.yaml
│   ├── hpa.yaml
│   └── ingress.yaml
└── charts/
    └── (dependencies: postgresql, redis subcharts)
```

### Chart.yaml

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

### values.yaml

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

### Deployment Template

```yaml
# templates/deployment-api.yaml
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
              value: "redis://{{ include "myapp.fullname" . }}-redis-master:6379"
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

---

## Deploy and Verify

```bash
# Add bitnami repo and update deps
helm repo add bitnami https://charts.bitnami.com/bitnami
helm dependency update ./myapp

# Install to Kind cluster
helm install myapp ./myapp \
  --namespace app \
  --create-namespace \
  --values ./myapp/values.yaml

# Check rollout
kubectl rollout status deployment/myapp-api -n app

# Port-forward API for local testing
kubectl port-forward svc/myapp-api 8000:8000 -n app

# View all resources
kubectl get all -n app
```

---

## Key Kubernetes Concepts to Know

| Concept | Use case |
|---------|---------|
| `Deployment` | Stateless app pods with rolling updates |
| `StatefulSet` | Databases, ordered pod identity |
| `ConfigMap` | Non-secret config (env vars, files) |
| `Secret` | Sensitive values (passwords, API keys) |
| `PersistentVolumeClaim` | Durable storage for stateful workloads |
| `HorizontalPodAutoscaler` | Scale pods based on CPU/memory/custom metrics |
| `Ingress` | Route external HTTP traffic to services |
| `ServiceAccount` | Pod-level RBAC identity |

---

## Checklist

- [ ] Multi-stage Dockerfile with non-root user and health check
- [ ] Docker Compose with `depends_on` + health checks for all services
- [ ] Kind cluster running with 2+ worker nodes
- [ ] Helm chart with PostgreSQL and Redis as subchart dependencies
- [ ] `values.yaml` parameterizing image tags, replica counts, resources
- [ ] HPA configured for the API deployment
- [ ] Ingress routing traffic to the API
- [ ] Helm upgrade tested with a new image tag
- [ ] `kubectl logs` and `kubectl exec` used for debugging

---

## 📚 Recommended Books & Online Resources

### Books

| Title | Author | Why It's Relevant |
|-------|--------|------------------|
| *Kubernetes in Action* | Marko Lukša | The best comprehensive Kubernetes book — covers pods, services, volumes, config, deployments, and operators |
| *The Kubernetes Book* | Nigel Poulton | Concise and practical; great for FDEs who need to learn fast without reading a 700-page tome |
| *Helm: The Kubernetes Package Manager* | Matt Butcher et al. | Official Helm book covering chart development, templating, and release management |
| *Docker Deep Dive* | Nigel Poulton | Fast, practical introduction to Docker images, containers, networking, and volumes |
| *Cloud Native DevOps with Kubernetes* | John Arundel & Justin Domingus | Production Kubernetes patterns including Helm, CI/CD, monitoring, and security |

### Online Resources

| Resource | URL | Description |
|---------|-----|-------------|
| Kubernetes Official Docs | [kubernetes.io/docs](https://kubernetes.io/docs/home/) | Primary reference for all K8s concepts and API objects |
| Helm Documentation | [helm.sh/docs](https://helm.sh/docs/) | Complete Helm chart development guide |
| Kind (Kubernetes in Docker) | [kind.sigs.k8s.io](https://kind.sigs.k8s.io/) | Local K8s cluster setup for development and testing |
| Artifact Hub — Helm Charts | [artifacthub.io](https://artifacthub.io) | Search for Helm charts for PostgreSQL, Redis, and other dependencies |
| Lens IDE | [k8slens.dev](https://k8slens.dev) | Visual Kubernetes dashboard — invaluable for debugging pod issues |
| Play with Kubernetes | [labs.play-with-k8s.com](https://labs.play-with-k8s.com/) | Free browser-based K8s environment for practicing without a local setup |

### Courses

| Course | Platform | Focus |
|--------|---------|-------|
| *Kubernetes for the Absolute Beginners* | Udemy (KodeKloud) | Hands-on labs with every core K8s concept |
| *Helm Chart Development* | KodeKloud (free tier) | Building and deploying production Helm charts |
| *Docker and Kubernetes: The Complete Guide* | Udemy (Stephen Grider) | Full-stack containerization from Docker basics to K8s production deployment |
