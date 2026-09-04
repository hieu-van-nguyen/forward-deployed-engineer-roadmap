# Milestone 07 — Enterprise Security: OAuth2 / OIDC / SAML SSO

| Field | Value |
|---|---|
| **Month** | M2 |
| **Weeks** | W7–W8 |
| **Priority** | P2 — High |
| **Domain** | Enterprise Security |
| **Objective** | Implement OAuth2, OIDC, and SAML 2.0 single sign-on flows; set up RBAC permission schemas |
| **Key Deliverable** | Working Auth service integration with Okta/Keycloak |

**📅 Day-by-day plan:** [Week 7 Schedule](../weeks/week-07-enterprise-security-oauth2-oidc-saml.md) (Days 1–7)

---

## Why This Matters for FDEs

Every enterprise client has an identity provider (Okta, Azure AD, Ping Identity, etc.). Your integration must support SSO from day one. Getting auth wrong means either a security incident or a blocked deployment. FDEs are expected to wire this up independently, not wait for the client's IT team to do it.

---

## Auth Protocol Comparison

| Protocol | Transport | Token Format | Use Case |
|----------|-----------|-------------|---------|
| OAuth 2.0 | HTTP | Opaque or JWT | Authorization (delegated access) |
| OIDC | HTTP + OAuth2 | JWT (ID token) | Authentication (who is the user) |
| SAML 2.0 | HTTP + XML | XML Assertion | Enterprise SSO (older, still dominant) |

---

## OAuth2 Authorization Code Flow (with PKCE)

```
User → App → Redirect to IdP /authorize?
            code_challenge=<S256 hash>
            &client_id=...
            &redirect_uri=...
            &scope=openid profile email

IdP → User authentication → Redirect back with ?code=<auth_code>

App → POST /token with:
      code=<auth_code>
      code_verifier=<original random value>
      client_id + client_secret

IdP → { access_token, id_token, refresh_token }
```

### Python Implementation (FastAPI)

```python
# auth/oauth.py
import secrets
import hashlib
import base64
from typing import Optional
from fastapi import APIRouter, Request, HTTPException
from fastapi.responses import RedirectResponse
import httpx

router = APIRouter()

OKTA_DOMAIN = "https://your-org.okta.com"
CLIENT_ID = "your-client-id"
CLIENT_SECRET = "your-client-secret"
REDIRECT_URI = "http://localhost:8000/auth/callback"

def generate_pkce_pair():
    """Generate code_verifier and code_challenge for PKCE."""
    verifier = base64.urlsafe_b64encode(secrets.token_bytes(32)).rstrip(b'=').decode()
    digest = hashlib.sha256(verifier.encode()).digest()
    challenge = base64.urlsafe_b64encode(digest).rstrip(b'=').decode()
    return verifier, challenge

@router.get("/login")
async def login(request: Request):
    verifier, challenge = generate_pkce_pair()
    state = secrets.token_urlsafe(16)

    # Store in session
    request.session["pkce_verifier"] = verifier
    request.session["oauth_state"] = state

    auth_url = (
        f"{OKTA_DOMAIN}/oauth2/v1/authorize"
        f"?response_type=code"
        f"&client_id={CLIENT_ID}"
        f"&redirect_uri={REDIRECT_URI}"
        f"&scope=openid profile email groups"
        f"&state={state}"
        f"&code_challenge={challenge}"
        f"&code_challenge_method=S256"
    )
    return RedirectResponse(auth_url)

@router.get("/callback")
async def callback(request: Request, code: str, state: str):
    # Validate state to prevent CSRF
    if state != request.session.get("oauth_state"):
        raise HTTPException(400, "Invalid state parameter")

    verifier = request.session.get("pkce_verifier")

    # Exchange code for tokens
    async with httpx.AsyncClient() as client:
        response = await client.post(
            f"{OKTA_DOMAIN}/oauth2/v1/token",
            data={
                "grant_type": "authorization_code",
                "code": code,
                "redirect_uri": REDIRECT_URI,
                "client_id": CLIENT_ID,
                "client_secret": CLIENT_SECRET,
                "code_verifier": verifier,
            },
        )
        tokens = response.json()

    # Validate and decode ID token
    id_token = validate_id_token(tokens["id_token"])
    request.session["user"] = {
        "sub": id_token["sub"],
        "email": id_token["email"],
        "name": id_token.get("name"),
        "groups": id_token.get("groups", []),
    }
    return RedirectResponse("/")
```

---

## JWT Validation

```python
# auth/jwt.py
import jwt
from jwt import PyJWKClient

JWKS_URL = f"{OKTA_DOMAIN}/oauth2/v1/keys"
jwks_client = PyJWKClient(JWKS_URL)

def validate_id_token(token: str) -> dict:
    signing_key = jwks_client.get_signing_key_from_jwt(token)
    payload = jwt.decode(
        token,
        signing_key.key,
        algorithms=["RS256"],
        audience=CLIENT_ID,
        issuer=OKTA_DOMAIN,
        options={"verify_exp": True},
    )
    return payload

def validate_access_token(token: str) -> dict:
    """Validate Bearer token on protected routes."""
    signing_key = jwks_client.get_signing_key_from_jwt(token)
    return jwt.decode(
        token,
        signing_key.key,
        algorithms=["RS256"],
        audience="api://default",  # Okta API audience
        issuer=f"{OKTA_DOMAIN}/oauth2/default",
    )
```

---

## RBAC Permission Schema

```python
# auth/rbac.py
from enum import Enum
from typing import Set
from functools import wraps
from fastapi import HTTPException, Depends

class Permission(str, Enum):
    # Data permissions
    DATA_READ = "data:read"
    DATA_WRITE = "data:write"
    DATA_DELETE = "data:delete"
    # Admin permissions
    USER_MANAGE = "user:manage"
    CONFIG_MANAGE = "config:manage"

# Role → Permission mapping
ROLE_PERMISSIONS: dict[str, Set[Permission]] = {
    "viewer": {Permission.DATA_READ},
    "editor": {Permission.DATA_READ, Permission.DATA_WRITE},
    "admin": {
        Permission.DATA_READ, Permission.DATA_WRITE, Permission.DATA_DELETE,
        Permission.USER_MANAGE, Permission.CONFIG_MANAGE,
    },
    "superadmin": set(Permission),  # all permissions
}

def get_user_permissions(groups: list[str]) -> Set[Permission]:
    """Map Okta groups to permissions."""
    permissions: Set[Permission] = set()
    for group in groups:
        # Map Okta group names to roles
        role = {
            "AppViewers": "viewer",
            "AppEditors": "editor",
            "AppAdmins": "admin",
        }.get(group)
        if role:
            permissions |= ROLE_PERMISSIONS.get(role, set())
    return permissions

def require_permission(permission: Permission):
    """FastAPI dependency for permission checking."""
    def dependency(request):
        user = request.session.get("user", {})
        groups = user.get("groups", [])
        user_permissions = get_user_permissions(groups)
        if permission not in user_permissions:
            raise HTTPException(403, f"Requires permission: {permission}")
        return user
    return Depends(dependency)

# Usage on routes:
@router.delete("/data/{id}")
async def delete_data(id: int, user=require_permission(Permission.DATA_DELETE)):
    ...
```

---

## SAML 2.0 Flow

```
User → App → Redirect to IdP with SAMLRequest (XML, base64 encoded)
IdP → User authenticates → POST to App /saml/callback with SAMLResponse
App → Validate signature with IdP cert → Extract assertions (NameID, attributes)
App → Create local session
```

```python
# auth/saml.py — using python3-saml
from onelogin.saml2.auth import OneLogin_Saml2_Auth

SAML_SETTINGS = {
    "sp": {
        "entityId": "https://myapp.com/saml/metadata",
        "assertionConsumerService": {
            "url": "https://myapp.com/saml/callback",
            "binding": "urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"
        },
    },
    "idp": {
        "entityId": "https://your-org.okta.com",
        "singleSignOnService": {
            "url": "https://your-org.okta.com/app/saml/sso",
            "binding": "urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"
        },
        "x509cert": "<IdP certificate from Okta metadata>",
    },
    "security": {
        "wantAssertionsSigned": True,
        "wantMessagesSigned": True,
    }
}

@router.get("/saml/login")
async def saml_login(request: Request):
    auth = OneLogin_Saml2_Auth(request, SAML_SETTINGS)
    return RedirectResponse(auth.login())

@router.post("/saml/callback")
async def saml_callback(request: Request):
    auth = OneLogin_Saml2_Auth(request, SAML_SETTINGS)
    auth.process_response()
    errors = auth.get_errors()
    if errors:
        raise HTTPException(400, f"SAML errors: {errors}")
    if not auth.is_authenticated():
        raise HTTPException(401, "Not authenticated")
    user_attrs = auth.get_attributes()
    name_id = auth.get_nameid()
    # Create session...
```

---

## Keycloak Local Setup (for testing)

```yaml
# docker-compose.yml addition
keycloak:
  image: quay.io/keycloak/keycloak:23.0
  command: start-dev
  environment:
    KEYCLOAK_ADMIN: admin
    KEYCLOAK_ADMIN_PASSWORD: admin
  ports:
    - "8080:8080"
```

```bash
# After starting, create realm via CLI
docker exec keycloak /opt/keycloak/bin/kcadm.sh \
  create realms -s realm=myrealm -s enabled=true \
  --server http://localhost:8080 \
  --realm master --user admin --password admin

# Create client (your app)
docker exec keycloak /opt/keycloak/bin/kcadm.sh \
  create clients -r myrealm \
  -s clientId=myapp \
  -s publicClient=false \
  -s 'redirectUris=["http://localhost:8000/*"]' \
  -s secret=myclientsecret
```

---

## Checklist

- [ ] OAuth2 Authorization Code Flow with PKCE implemented
- [ ] JWT validation using JWKS endpoint (not hardcoded secret)
- [ ] RBAC: groups from IdP mapped to local permissions
- [ ] `require_permission()` decorator applied to sensitive routes
- [ ] Token refresh logic (access token expiry handled)
- [ ] SAML 2.0 SP-initiated flow with signature validation
- [ ] Local Keycloak instance used for testing both OIDC and SAML
- [ ] HTTPS enforced (never send tokens over HTTP in demos)

---

## 📚 Recommended Books & Online Resources

### Books

| Title | Author | Why It's Relevant |
|-------|--------|------------------|
| *OAuth 2 in Action* | Justin Richer & Antonio Sanso | The comprehensive OAuth 2.0 reference — covers Authorization Code, PKCE, client credentials, token introspection, and common pitfalls |
| *The Web Application Hacker's Handbook* | Stuttard & Pinto | Security mindset book — understand how auth is attacked so you implement it correctly |
| *Identity and Data Security for Web Development* | Jonathan LeBlanc & Tim Messerschmidt | Practical implementation guide for OAuth, OIDC, and JWT across web applications |
| *Zero Trust Networks* | Evan Gilman & Doug Barth | Modern enterprise security model — how identity and access management fits into zero-trust architecture |
| *JWT Handbook* | Sebastián Peyrott (free PDF) | Complete JWT reference including signing algorithms, validation, and common vulnerabilities |

### Online Resources

| Resource | URL | Description |
|---------|-----|-------------|
| OAuth 2.0 RFC 6749 | [datatracker.ietf.org/doc/html/rfc6749](https://datatracker.ietf.org/doc/html/rfc6749) | The actual OAuth 2.0 spec — read at least sections 1, 4.1, and 6 |
| OIDC Core Specification | [openid.net/specs/openid-connect-core-1_0.html](https://openid.net/specs/openid-connect-core-1_0.html) | Official OIDC spec — essential for understanding ID tokens and claims |
| Okta Developer Docs | [developer.okta.com](https://developer.okta.com/docs/) | Practical guides for integrating Okta OIDC and SAML with real code examples |
| Auth0 Docs | [auth0.com/docs](https://auth0.com/docs) | Excellent conceptual explanations of OAuth2 flows with diagrams |
| jwt.io | [jwt.io](https://jwt.io) | Interactive JWT decoder and debugger — paste any token to inspect claims |
| SAML2 Core Specification | [docs.oasis-open.org](https://docs.oasis-open.org/security/saml/v2.0/saml-core-2.0-os.pdf) | Official SAML 2.0 spec — reference for assertion format and binding types |

### Courses

| Course | Platform | Focus |
|--------|---------|-------|
| *OAuth 2.0 and OpenID Connect* | Pluralsight | Video walkthrough of every OAuth2 flow with practical labs |
| *API Security Fundamentals* | APIsec University (free) | OAuth2, OIDC, and API authentication security best practices |
| *Certified Identity and Access Manager (CIAM)* | IDSA | Professional certification for enterprise identity and access management |
