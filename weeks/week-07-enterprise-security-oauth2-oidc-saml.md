# Week 7 — Enterprise Security: OAuth2 / OIDC / SAML SSO — Day-by-Day Plan

> **Milestone:** [07 — Enterprise Security: OAuth2/OIDC/SAML](../milestones/07-enterprise-security-oauth2-oidc-saml.md)
> **Month:** M2 · **Weeks:** W7–W8 (this plan covers W7, Days 1–7)
> **Pacing note:** The milestone spans W7–W8. This document covers W7. W8 is covered by [Milestone 08 — Network Security: VPC/TLS](../milestones/08-network-security-vpc-tls.md).
> **Deliverable:** A FastAPI service with working OIDC login (PKCE), RBAC driven by real IdP group claims, and a SAML SSO flow — all tested against a local Keycloak instance, not a hosted Okta tenant.

> **⚠️ Primary IdP is Keycloak, not Okta.** The milestone's code hardcodes Okta URLs, but Okta requires a hosted tenant you may not have access to. Every day below runs against a **local Keycloak container** — free, scriptable, and fully under your control. Okta/Azure AD are noted as drop-in alternatives once the Keycloak flow works, since OIDC is a standard and the same code should point at either provider via discovery.

---

## Overview

| Day | Focus | Output |
|-----|-------|--------|
| 1 | Protocol landscape + Keycloak up and running | Local Keycloak realm/client, OIDC discovery doc fetched |
| 2 | OAuth2 Authorization Code Flow + PKCE | Working `/login` → `/callback` round trip |
| 3 | JWT validation via JWKS (ID token + access token) | `auth/jwt.py` validating real Keycloak-issued tokens |
| 4 | RBAC — groups claim, role mapping, permission enforcement | `require_permission()` actually blocking/allowing requests |
| 5 | Token refresh + session hardening | Silent refresh wired into the resilient HTTP client from Week 4 |
| 6 | SAML 2.0 SP-initiated flow | `/saml/login` → `/saml/callback` validated against Keycloak's SAML IdP |
| 7 | End-to-end integration test + security review | Full auth test suite + checklist walkthrough |

---

## Day 1 — Protocol Landscape + Local Keycloak Setup

**Goal:** Understand what OAuth2/OIDC/SAML each actually solve, then get a real IdP running locally so every later day tests against a live server, not mocked responses.

### Protocol Comparison

| Protocol | Transport | Token Format | Solves |
|----------|-----------|-------------|--------|
| OAuth 2.0 | HTTP | Opaque or JWT | **Authorization** — delegated access to a resource |
| OIDC | HTTP + OAuth2 | JWT (ID token) | **Authentication** — who is the user |
| SAML 2.0 | HTTP + XML | XML Assertion | Enterprise SSO — older, still dominant in large enterprises |

### Keycloak via Docker Compose (reuses Week 5 patterns)

```yaml
# docker-compose.yml
services:
  keycloak:
    image: quay.io/keycloak/keycloak:23.0
    container_name: keycloak   # required — kcadm.sh commands below `docker exec keycloak`
    command: start-dev
    environment:
      KEYCLOAK_ADMIN: admin
      KEYCLOAK_ADMIN_PASSWORD: admin
    ports:
      - "8080:8080"
    healthcheck:
      test: ["CMD-SHELL", "curl -f http://localhost:8080/health/ready || exit 1"]
      interval: 10s
      timeout: 5s
      retries: 10
```

```bash
docker compose up -d keycloak
docker compose logs -f keycloak   # wait for "Keycloak ... started"
```

### Create a Realm, Client, and Groups via `kcadm.sh`

```bash
# Authenticate kcadm against the master realm
docker exec keycloak /opt/keycloak/bin/kcadm.sh config credentials \
  --server http://localhost:8080 --realm master --user admin --password admin

# Create your app's realm
docker exec keycloak /opt/keycloak/bin/kcadm.sh create realms \
  -s realm=myrealm -s enabled=true

# Create the confidential client
docker exec keycloak /opt/keycloak/bin/kcadm.sh create clients -r myrealm \
  -s clientId=myapp \
  -s publicClient=false \
  -s 'redirectUris=["http://localhost:8000/*"]' \
  -s secret=myclientsecret \
  -s standardFlowEnabled=true

# Create a test user
docker exec keycloak /opt/keycloak/bin/kcadm.sh create users -r myrealm \
  -s username=testuser -s enabled=true -s email=test@example.com
docker exec keycloak /opt/keycloak/bin/kcadm.sh set-password -r myrealm \
  --username testuser --new-password testpass
```

### Fetch the OIDC Discovery Document — Don't Hardcode Endpoint Paths

Keycloak 23 dropped the old `/auth` prefix; its issuer path is `/realms/{realm}`, and its endpoint layout differs from Okta's. Hardcoding paths (as the milestone's code does) breaks the moment you point at a different provider. Every OIDC-compliant IdP publishes a discovery document — read it once at startup instead:

```python
# auth/discovery.py
import httpx

ISSUER = "http://localhost:8080/realms/myrealm"

async def get_oidc_config() -> dict:
    async with httpx.AsyncClient() as client:
        resp = await client.get(f"{ISSUER}/.well-known/openid-configuration")
        resp.raise_for_status()
        return resp.json()
```

```bash
curl -s http://localhost:8080/realms/myrealm/.well-known/openid-configuration | python3 -m json.tool
# note authorization_endpoint, token_endpoint, jwks_uri, end_session_endpoint
```

### Done when
- [ ] Keycloak running locally with a healthy container (`container_name: keycloak` set — required by later `docker exec` commands)
- [ ] `myrealm` realm, `myapp` client, and a test user created via `kcadm.sh`
- [ ] Discovery document fetched and inspected — can name `authorization_endpoint`, `token_endpoint`, `jwks_uri` without re-checking docs
- [ ] Explain in one sentence each: what OAuth2 solves vs. OIDC vs. SAML

---

## Day 2 — OAuth2 Authorization Code Flow + PKCE

**Goal:** Implement the full redirect-based login flow, fixing the milestone's missing `SessionMiddleware` registration — without it, `request.session` throws on the very first request.

### The Bug: No Session Middleware Registered

```python
# milestones/07 version — request.session used, but never wired up
@router.get("/login")
async def login(request: Request):
    ...
    request.session["pkce_verifier"] = verifier   # AssertionError: SessionMiddleware must be installed
```

Starlette's `request.session` only exists if `SessionMiddleware` is added to the app. Skipping this is the single most common "why does my auth code crash immediately" mistake.

### The Fix — `main.py`

```python
# main.py
from fastapi import FastAPI
from starlette.middleware.sessions import SessionMiddleware

app = FastAPI()
app.add_middleware(SessionMiddleware, secret_key="dev-only-change-me")  # use a real secret + Secure/HttpOnly cookies in prod
```

```bash
pip install itsdangerous   # SessionMiddleware's dependency — not pulled in by fastapi/starlette alone
```

### PKCE + Authorization Code Flow — `auth/oauth.py`

```python
import secrets
import hashlib
import base64
from fastapi import APIRouter, Request, HTTPException
from fastapi.responses import RedirectResponse
import httpx
from auth.discovery import get_oidc_config

router = APIRouter()

CLIENT_ID = "myapp"
CLIENT_SECRET = "myclientsecret"
REDIRECT_URI = "http://localhost:8000/auth/callback"

def generate_pkce_pair():
    verifier = base64.urlsafe_b64encode(secrets.token_bytes(32)).rstrip(b'=').decode()
    digest = hashlib.sha256(verifier.encode()).digest()
    challenge = base64.urlsafe_b64encode(digest).rstrip(b'=').decode()
    return verifier, challenge

@router.get("/login")
async def login(request: Request):
    oidc = await get_oidc_config()
    verifier, challenge = generate_pkce_pair()
    state = secrets.token_urlsafe(16)
    nonce = secrets.token_urlsafe(16)   # binds the ID token to this specific auth request — prevents replay

    request.session["pkce_verifier"] = verifier
    request.session["oauth_state"] = state
    request.session["oauth_nonce"] = nonce

    auth_url = (
        f"{oidc['authorization_endpoint']}"
        f"?response_type=code"
        f"&client_id={CLIENT_ID}"
        f"&redirect_uri={REDIRECT_URI}"
        f"&scope=openid profile email"
        f"&state={state}"
        f"&nonce={nonce}"
        f"&code_challenge={challenge}"
        f"&code_challenge_method=S256"
    )
    return RedirectResponse(auth_url)

@router.get("/callback")
async def callback(request: Request, code: str, state: str):
    if state != request.session.get("oauth_state"):
        raise HTTPException(400, "Invalid state parameter")

    oidc = await get_oidc_config()
    verifier = request.session.get("pkce_verifier")

    async with httpx.AsyncClient() as client:
        response = await client.post(
            oidc["token_endpoint"],
            data={
                "grant_type": "authorization_code",
                "code": code,
                "redirect_uri": REDIRECT_URI,
                "client_id": CLIENT_ID,
                "client_secret": CLIENT_SECRET,
                "code_verifier": verifier,
            },
        )
        response.raise_for_status()
        tokens = response.json()

    from auth.jwt import validate_id_token
    id_token = await validate_id_token(tokens["id_token"], oidc)

    # Verify nonce matches what we sent — prevents ID token replay/injection
    if id_token.get("nonce") != request.session.get("oauth_nonce"):
        raise HTTPException(400, "Invalid nonce")

    request.session["user"] = {
        "sub": id_token["sub"],
        "email": id_token.get("email"),
        "name": id_token.get("name"),
        "groups": id_token.get("groups", []),
    }
    request.session["tokens"] = tokens   # needed for refresh on Day 5
    return RedirectResponse("/")
```

### Run the Round Trip

```bash
uvicorn main:app --reload
# Visit http://localhost:8000/login in a browser, log in as testuser/testpass
# Should redirect back to "/" with request.session["user"] populated
```

### Done when
- [ ] `SessionMiddleware` registered; `itsdangerous` installed
- [ ] `/login` redirects to Keycloak, `/callback` completes the code exchange
- [ ] `state` and `nonce` both generated, sent, and verified on return
- [ ] Full browser round trip tested against the local Keycloak realm

---

## Day 3 — JWT Validation via JWKS

**Goal:** Validate both the ID token and access token correctly against Keycloak's real signing keys — and fix the milestone's bug where both token types share one JWKS client pointed at the wrong issuer for access tokens.

### The Bug: One Shared JWKS Client, Two Different Issuers

```python
# milestones/07 version
JWKS_URL = f"{OKTA_DOMAIN}/oauth2/v1/keys"
jwks_client = PyJWKClient(JWKS_URL)

def validate_access_token(token: str) -> dict:
    return jwt.decode(token, signing_key.key, ..., issuer=f"{OKTA_DOMAIN}/oauth2/default")  # different issuer, same jwks_client
```

Okta's authorization-server model splits ID tokens (issued by the org domain) from access tokens (issued by a named authorization server, e.g. `default`) — but this code fetches keys from only one endpoint and asserts a *different* issuer for access tokens without re-fetching from the right JWKS. Keycloak is simpler (one issuer per realm), but the fix generalizes: **always derive `jwks_uri` and `issuer` from the same discovery document you validate against**, never assume they match across token types.

### The Fix — `auth/jwt.py`

```python
import jwt
from jwt import PyJWKClient
from functools import lru_cache

@lru_cache(maxsize=8)
def _jwks_client_for(jwks_uri: str) -> PyJWKClient:
    return PyJWKClient(jwks_uri)   # cached per-issuer, not a single global client

async def validate_id_token(token: str, oidc: dict) -> dict:
    client = _jwks_client_for(oidc["jwks_uri"])
    signing_key = client.get_signing_key_from_jwt(token)
    return jwt.decode(
        token,
        signing_key.key,
        algorithms=["RS256"],
        audience=CLIENT_ID,
        issuer=oidc["issuer"],
        options={"verify_exp": True},
    )

async def validate_access_token(token: str, oidc: dict) -> dict:
    client = _jwks_client_for(oidc["jwks_uri"])   # same discovery doc — same issuer/jwks pairing, always
    signing_key = client.get_signing_key_from_jwt(token)
    return jwt.decode(
        token,
        signing_key.key,
        algorithms=["RS256"],
        audience="account",       # Keycloak's default access token audience
        issuer=oidc["issuer"],
        options={"verify_exp": True},
    )
```

### Manual Verification with `jwt.io`

```bash
# Grab a real token from your Day 2 session and decode it
curl -s -X POST http://localhost:8080/realms/myrealm/protocol/openid-connect/token \
  -d grant_type=password -d client_id=myapp -d client_secret=myclientsecret \
  -d username=testuser -d password=testpass | python3 -m json.tool
# Paste the access_token into https://jwt.io to inspect claims: iss, aud, exp, sub
```

### Done when
- [ ] `validate_id_token` and `validate_access_token` both derive `issuer`/`jwks_uri` from the same discovery document
- [ ] A real token decoded and inspected on jwt.io — can identify `iss`, `aud`, `exp` at a glance
- [ ] Tampered/expired token correctly rejected (test by hand-editing a token's payload — signature check should fail)

---

## Day 4 — RBAC: Groups Claim, Role Mapping, Permission Enforcement

**Goal:** Fix the two bugs that make RBAC silently deny everything: (1) Keycloak doesn't include a `groups` claim by default, and (2) the milestone's `require_permission` dependency is unannotated and will crash or misbehave in FastAPI.

### The Bug: No `groups` Claim Without Explicit Configuration

Neither Keycloak nor Okta puts group membership into tokens by default — you must create groups, assign the user, **and** add a protocol mapper (Keycloak) or claim configuration (Okta) that includes `groups` in the token. Skip this and `id_token.get("groups", [])` is always `[]`, so `get_user_permissions([])` returns an empty set and every `require_permission()` check fails with 403 — with no obvious error message pointing at the cause.

### Fix — Create Groups and a Groups Mapper in Keycloak

```bash
# Create groups
docker exec keycloak /opt/keycloak/bin/kcadm.sh create groups -r myrealm -s name=AppViewers
docker exec keycloak /opt/keycloak/bin/kcadm.sh create groups -r myrealm -s name=AppEditors
docker exec keycloak /opt/keycloak/bin/kcadm.sh create groups -r myrealm -s name=AppAdmins

# Add testuser to AppEditors (get group ID and user ID first)
GROUP_ID=$(docker exec keycloak /opt/keycloak/bin/kcadm.sh get groups -r myrealm -q search=AppEditors --fields id --format csv --noquotes | tail -1)
USER_ID=$(docker exec keycloak /opt/keycloak/bin/kcadm.sh get users -r myrealm -q username=testuser --fields id --format csv --noquotes | tail -1)
docker exec keycloak /opt/keycloak/bin/kcadm.sh update users/$USER_ID/groups/$GROUP_ID -r myrealm -s realm=myrealm -s userId=$USER_ID -s groupId=$GROUP_ID -n

# Add a "groups" protocol mapper to the client so group membership lands in the token
docker exec keycloak /opt/keycloak/bin/kcadm.sh create clients/$(docker exec keycloak /opt/keycloak/bin/kcadm.sh get clients -r myrealm -q clientId=myapp --fields id --format csv --noquotes | tail -1)/protocol-mappers/models \
  -r myrealm \
  -s name=groups -s protocol=openid-connect -s protocolMapper=oidc-group-membership-mapper \
  -s 'config."full.path"=false' \
  -s 'config."id.token.claim"=true' \
  -s 'config."access.token.claim"=true' \
  -s 'config."claim.name"=groups'
```

```bash
# Re-authenticate and confirm groups now appear in the token
curl -s -X POST http://localhost:8080/realms/myrealm/protocol/openid-connect/token \
  -d grant_type=password -d client_id=myapp -d client_secret=myclientsecret \
  -d username=testuser -d password=testpass | python3 -c "
import sys, json, base64
tok = json.load(sys.stdin)['access_token']
payload = tok.split('.')[1] + '=='
print(json.loads(base64.urlsafe_b64decode(payload)))
"
# should now show 'groups': ['AppEditors']
```

### The Bug: Unannotated `request` Parameter Breaks FastAPI's Dependency Injection

```python
# milestones/07 version
def require_permission(permission: Permission):
    def dependency(request):          # ← no type annotation
        user = request.session.get("user", {})
        ...
    return Depends(dependency)
```

Without a `Request` type hint, FastAPI treats the parameter as a required **query string** parameter named `request` (a plain string), not the injected `Request` object. Calling `.session` on a string raises `AttributeError`, and the endpoint additionally demands a `?request=...` query param that has nothing to do with auth.

### The Fix — `auth/rbac.py`

```python
from enum import Enum
from typing import Set
from fastapi import HTTPException, Depends, Request

class Permission(str, Enum):
    DATA_READ = "data:read"
    DATA_WRITE = "data:write"
    DATA_DELETE = "data:delete"
    USER_MANAGE = "user:manage"
    CONFIG_MANAGE = "config:manage"

ROLE_PERMISSIONS: dict[str, Set[Permission]] = {
    "viewer": {Permission.DATA_READ},
    "editor": {Permission.DATA_READ, Permission.DATA_WRITE},
    "admin": {
        Permission.DATA_READ, Permission.DATA_WRITE, Permission.DATA_DELETE,
        Permission.USER_MANAGE, Permission.CONFIG_MANAGE,
    },
    "superadmin": set(Permission),
}

GROUP_TO_ROLE = {
    "AppViewers": "viewer",
    "AppEditors": "editor",
    "AppAdmins": "admin",
}

def get_user_permissions(groups: list[str]) -> Set[Permission]:
    permissions: Set[Permission] = set()
    for group in groups:
        role = GROUP_TO_ROLE.get(group)
        if role:
            permissions |= ROLE_PERMISSIONS.get(role, set())
    return permissions

def require_permission(permission: Permission):
    def dependency(request: Request):   # correctly typed — FastAPI now injects the real Request
        user = request.session.get("user")
        if not user:
            raise HTTPException(401, "Not authenticated")
        user_permissions = get_user_permissions(user.get("groups", []))
        if permission not in user_permissions:
            raise HTTPException(403, f"Requires permission: {permission}")
        return user
    return Depends(dependency)

# Usage:
# @router.delete("/data/{id}")
# async def delete_data(id: int, user=require_permission(Permission.DATA_DELETE)):
#     ...
```

### Verify Enforcement End-to-End

```bash
# testuser is in AppEditors → has DATA_WRITE but NOT DATA_DELETE
curl -b cookies.txt http://localhost:8000/data/1 -X DELETE   # expect 403
curl -b cookies.txt http://localhost:8000/data/1 -X PUT      # expect 200 (if route exists)
```

### Done when
- [ ] Groups created in Keycloak, test user assigned, groups protocol mapper added to the client
- [ ] Confirmed `groups` claim actually appears in a decoded token (not assumed)
- [ ] `require_permission`'s inner function correctly typed as `Request`
- [ ] A 403 observed for a permission the test user's role doesn't grant, and a success for one it does

---

## Day 5 — Token Refresh + Session Hardening

**Goal:** Add the token-refresh logic the milestone's checklist requires but never implements, reusing the `TokenRefreshClient` wrapper pattern from Week 4's resilient HTTP client.

### Why This Matters

Access tokens are short-lived (often 5–15 minutes). Without refresh logic, users get logged out mid-session the moment the access token expires, even though a valid `refresh_token` is sitting in their session doing nothing.

### `auth/refresh.py`

```python
import time
import httpx
from auth.discovery import get_oidc_config

CLIENT_ID = "myapp"
CLIENT_SECRET = "myclientsecret"

async def refresh_access_token(refresh_token: str) -> dict:
    oidc = await get_oidc_config()
    async with httpx.AsyncClient() as client:
        response = await client.post(
            oidc["token_endpoint"],
            data={
                "grant_type": "refresh_token",
                "refresh_token": refresh_token,
                "client_id": CLIENT_ID,
                "client_secret": CLIENT_SECRET,
            },
        )
        response.raise_for_status()
        return response.json()

def is_token_expired(tokens: dict, issued_at: float, skew_seconds: int = 30) -> bool:
    """Check expiry with a safety margin so we refresh slightly before the real deadline."""
    return time.time() >= (issued_at + tokens["expires_in"] - skew_seconds)
```

### Wire It Into a Request-Scoped Dependency

```python
# auth/session_guard.py
import time
from fastapi import Request, HTTPException
from auth.refresh import refresh_access_token, is_token_expired

async def get_valid_session(request: Request) -> dict:
    session_tokens = request.session.get("tokens")
    issued_at = request.session.get("tokens_issued_at", 0)
    if not session_tokens:
        raise HTTPException(401, "Not authenticated")

    if is_token_expired(session_tokens, issued_at):
        try:
            new_tokens = await refresh_access_token(session_tokens["refresh_token"])
        except httpx.HTTPStatusError:
            request.session.clear()
            raise HTTPException(401, "Session expired, please log in again")
        request.session["tokens"] = new_tokens
        request.session["tokens_issued_at"] = time.time()
        session_tokens = new_tokens

    return session_tokens
```

> This mirrors Week 4's `TokenRefreshClient` pattern: refresh happens **outside** any retry loop, on a 401-adjacent trigger (here, proactive expiry check rather than reactive 401), and a failed refresh forces re-authentication rather than silently failing.

### Test the Refresh Path

```bash
# Force expiry by using a short-lived test client, or manually back-date tokens_issued_at
# in the session to simulate expiry, then hit any protected route:
curl -b cookies.txt http://localhost:8000/data/1
# Confirm via logs that refresh_access_token was called and a new access_token was issued
```

### Done when
- [ ] `refresh_access_token()` implemented against the discovery document's `token_endpoint`
- [ ] Expiry checked proactively (with clock skew margin) rather than reactively on a 401
- [ ] Failed refresh clears the session and forces re-login rather than looping or crashing
- [ ] Refresh path manually exercised and confirmed via logs

---

## Day 6 — SAML 2.0 SP-Initiated Flow

**Goal:** Implement SAML SSO against Keycloak's built-in SAML IdP — fixing the milestone's code, which passes a Starlette `Request` directly to `python3-saml`, a library that expects a plain dict.

### Environment Check Before Building On This

```bash
pip install python3-saml
python3 -c "import xmlsec"   # confirm this succeeds BEFORE writing any SAML code
```

If `import xmlsec` fails (common on macOS without `libxmlsec1` headers), don't fight the local install — run the SAML-handling service in a container instead, same pattern as Week 5:

```dockerfile
# saml-service/Dockerfile
FROM python:3.11-slim
RUN apt-get update && apt-get install -y libxmlsec1-dev pkg-config && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY . .
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]
```

### The Bug: `python3-saml` Doesn't Understand Starlette's `Request`

```python
# milestones/07 version
@router.get("/saml/login")
async def saml_login(request: Request):
    auth = OneLogin_Saml2_Auth(request, SAML_SETTINGS)   # ← python3-saml expects a dict, not a Starlette Request
    return RedirectResponse(auth.login())
```

`OneLogin_Saml2_Auth` was built for WSGI-style frameworks (Flask/Django) where request data is assembled into a specific dict shape. Passing a Starlette object directly fails immediately — the library calls `.get(...)` and other dict methods that `Request` doesn't implement the same way.

### The Fix — Build the Expected Dict Explicitly

```python
# auth/saml.py
from onelogin.saml2.auth import OneLogin_Saml2_Auth
from fastapi import APIRouter, Request, HTTPException
from fastapi.responses import RedirectResponse

router = APIRouter()

SAML_SETTINGS = {
    "sp": {
        "entityId": "http://localhost:8000/saml/metadata",
        "assertionConsumerService": {
            "url": "http://localhost:8000/saml/callback",
            "binding": "urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST",
        },
    },
    "idp": {
        "entityId": "http://localhost:8080/realms/myrealm",
        "singleSignOnService": {
            "url": "http://localhost:8080/realms/myrealm/protocol/saml",
            "binding": "urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect",
        },
        "x509cert": "<paste from Keycloak realm's SAML IdP metadata endpoint>",
    },
    "security": {
        "wantAssertionsSigned": True,
        "wantMessagesSigned": False,   # Keycloak dev-mode default; tighten for production
    },
}

async def prepare_fastapi_request(request: Request) -> dict:
    form_data = await request.form() if request.method == "POST" else {}
    return {
        "https": "off" if request.url.scheme == "http" else "on",
        "http_host": request.url.hostname,
        "server_port": request.url.port or (443 if request.url.scheme == "https" else 80),
        "script_name": request.url.path,
        "get_data": dict(request.query_params),
        "post_data": dict(form_data),
    }

@router.get("/saml/login")
async def saml_login(request: Request):
    req = await prepare_fastapi_request(request)
    auth = OneLogin_Saml2_Auth(req, SAML_SETTINGS)
    return RedirectResponse(auth.login())

@router.post("/saml/callback")
async def saml_callback(request: Request):
    req = await prepare_fastapi_request(request)
    auth = OneLogin_Saml2_Auth(req, SAML_SETTINGS)
    auth.process_response()
    errors = auth.get_errors()
    if errors:
        raise HTTPException(400, f"SAML errors: {errors} — {auth.get_last_error_reason()}")
    if not auth.is_authenticated():
        raise HTTPException(401, "Not authenticated")

    user_attrs = auth.get_attributes()
    name_id = auth.get_nameid()
    request.session["saml_user"] = {"name_id": name_id, "attributes": user_attrs}
    return RedirectResponse("/")
```

### Get the IdP Certificate From Keycloak

```bash
curl -s http://localhost:8080/realms/myrealm/protocol/saml/descriptor | grep -o '<ds:X509Certificate>[^<]*'
# paste the value (without the tag) into SAML_SETTINGS["idp"]["x509cert"]
```

### Enable SAML on the Keycloak Client (Keycloak clients default to OIDC)

```bash
docker exec keycloak /opt/keycloak/bin/kcadm.sh create clients -r myrealm \
  -s clientId=http://localhost:8000/saml/metadata \
  -s protocol=saml \
  -s 'redirectUris=["http://localhost:8000/saml/callback"]'
```

### Done when
- [ ] `xmlsec` import verified working (natively or via the Docker fallback) before writing SAML routes
- [ ] `prepare_fastapi_request()` correctly assembles the dict `python3-saml` expects, including `post_data` from `await request.form()`
- [ ] IdP certificate pulled from Keycloak's real SAML descriptor, not a placeholder
- [ ] Full `/saml/login` → Keycloak login → `/saml/callback` round trip completes with `is_authenticated() == True`

---

## Day 7 — End-to-End Integration Test + Security Review

**Goal:** Write an automated test suite covering the full auth surface, then walk the milestone's checklist line by line against what you actually built.

### `tests/test_auth.py`

```python
import pytest
import respx
import httpx
from auth.jwt import validate_id_token, validate_access_token
from auth.rbac import get_user_permissions, Permission

@pytest.mark.asyncio
async def test_groups_claim_maps_to_permissions():
    perms = get_user_permissions(["AppEditors"])
    assert Permission.DATA_READ in perms
    assert Permission.DATA_WRITE in perms
    assert Permission.DATA_DELETE not in perms

@pytest.mark.asyncio
async def test_no_groups_means_no_permissions():
    # regression test for the Day 4 bug — empty groups must NOT silently grant anything
    assert get_user_permissions([]) == set()

@pytest.mark.asyncio
async def test_require_permission_denies_unauthenticated(client):
    resp = await client.delete("/data/1")
    assert resp.status_code == 401

@respx.mock
@pytest.mark.asyncio
async def test_token_refresh_called_on_expiry(client):
    respx.post("http://localhost:8080/realms/myrealm/protocol/openid-connect/token").mock(
        return_value=httpx.Response(200, json={
            "access_token": "new-token", "refresh_token": "new-refresh", "expires_in": 300
        })
    )
    # simulate an expired session and confirm refresh_access_token was invoked
    ...
```

### Security Review — Walk the Milestone Checklist

- [ ] OAuth2 Authorization Code Flow with PKCE implemented — verify `code_challenge_method=S256`, never `plain`
- [ ] JWT validation using JWKS endpoint, not a hardcoded secret — confirm `_jwks_client_for()` is keyed off the discovery doc, not a literal URL
- [ ] RBAC: groups from IdP mapped to local permissions — confirm the Keycloak groups protocol mapper is actually present (Day 4's silent-failure bug)
- [ ] `require_permission()` correctly typed with `Request` and applied to every state-changing route
- [ ] Token refresh logic handles access token expiry proactively, with clock skew margin
- [ ] SAML 2.0 SP-initiated flow with signature validation (`wantAssertionsSigned: True`) and a real IdP cert, not a placeholder
- [ ] Local Keycloak instance used for testing both OIDC and SAML — no dependency on a live Okta tenant to run the test suite
- [ ] HTTPS enforced outside of local dev (note: this week's flows run over `http://localhost` for testability — flag this explicitly as a dev-only exception, not a production pattern)

### Self-Debrief Questions

1. A client's Okta tenant issues access tokens from a *different* authorization server than the one that issues ID tokens. Where in your code would you break if you assumed one JWKS client covers both?
2. Your RBAC checks are all returning 403 for a user you just added to the right group. What's the first thing you check, in order?
3. Why does verifying `nonce` on the ID token matter even though you already verify `state` on the authorization response?

---

## Resources Quick Reference

| Day | Key Resource |
|-----|-------------|
| 1 | [Keycloak Getting Started (Docker)](https://www.keycloak.org/getting-started/getting-started-docker) |
| 2 | [OAuth 2.0 RFC 6749 §4.1 — Authorization Code Grant](https://datatracker.ietf.org/doc/html/rfc6749#section-4.1) |
| 3 | [OIDC Core Spec — ID Token Validation](https://openid.net/specs/openid-connect-core-1_0.html#IDTokenValidation) |
| 4 | [Keycloak — Group Membership Mapper](https://www.keycloak.org/docs/latest/server_admin/#_client_role_mappings) |
| 5 | [OIDC — Refresh Token Grant](https://openid.net/specs/openid-connect-core-1_0.html) |
| 6 | [python3-saml — GitHub](https://github.com/SAML-Toolkits/python3-saml) |
| 7 | [jwt.io Debugger](https://jwt.io) |

---

*→ Next: [Milestone 08 — Network Security: VPC/TLS](../milestones/08-network-security-vpc-tls.md)*
