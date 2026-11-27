# MCP OAuth in TMS 0.6.1

TMS provides a built-in authorization server for remote Streamable HTTP MCP. Existing manually issued Bearer credentials keep their permissions, expiration and request receipts. OAuth is an additional option, not a migration requirement. Every user authorizes their own TMS account.

## Deployment

Set APP_URL to the exact public HTTPS origin (no path, query or fragment), and SESSION_SECURE=true. OAuth is enabled by default on HTTPS and on literal loopback HTTP for local tests. Set MCP_OAUTH_ENABLED=false to disable OAuth endpoints and OAuth access-token acceptance; legacy tokens still work. Re-enabling does not itself revoke outstanding grants. To revoke them, use the profile page. The reverse proxy must forward Authorization and route the .well-known and /oauth paths to TMS; do not log token request bodies or query strings containing authorization codes. Apply per-source reverse-proxy rate limits to registration and authorization requests, including password attempts, without blocking normal redirects. Registration has a global application-level daily limit of 1000 (not a strict distributed quota); client records persist. No external URL is fetched during registration.

## Client setup

Use APP_URL/mcp, Streamable HTTP, OAuth, dynamic client registration (DCR). Discover endpoints from:
- GET /.well-known/oauth-protected-resource/mcp (also root /.well-known/oauth-protected-resource)
- GET /.well-known/oauth-authorization-server

An unauthorized MCP request returns HTTP 401 with WWW-Authenticate and resource_metadata. Metadata advertises authorization, token, registration and revocation URLs. Register using JSON and exact redirect URIs (HTTPS or literal loopback HTTP), authorization_code and refresh_token grants, response type code, and token_endpoint_auth_method none, client_secret_basic or client_secret_post. DCR client names are self-declared, not verified identities. Do not disclose or lose a confidential client secret. CIMD and private_key_jwt are not implemented.

Start authorization with response_type=code, client_id, redirect_uri, scope, state, code_challenge and code_challenge_method=S256. Use resource=APP_URL/mcp; omission is accepted only as that single resource. TMS validates the exact registered redirect, shows client name, callback address and requested privileges, then requires the user's TMS username/password and explicit consent (or denial). The password is not sent to the client. The consent transaction expires in ten minutes. The callback returns code, state (when supplied) and issuer. Validate state and issuer on the client. The code expires in five minutes and is bound to the client, redirect URI, resource and PKCE challenge.

POST application/x-www-form-urlencoded to /oauth/token with grant_type=authorization_code, code, client_id, redirect_uri, code_verifier, resource and applicable client authentication. Use the returned access token as Authorization: Bearer. Access tokens expire in 3600 seconds. Refresh via grant_type=refresh_token, refresh_token, client_id, resource and applicable client authentication. The refresh family expires 30 days after consent; each refresh rotates both tokens, invalidating old access credentials. Serialize refresh requests: reuse of a rotated refresh token revokes the family. A correctly bound authorization-code replay within the code lifetime also revokes its grant. A refresh cannot expand scopes. Request-key receipts remain associated with the same grant across refreshes.

## Scopes

| Requested scope | Effective permissions |
|---|---|
| tms:read | 25 read tools |
| tms:write | read + write, 61 tools |
| tms:delete | read + write + delete, 75 tools |

Scope omission defaults to read. Deletion requires explicit consent to the deletion scope, and each destructive operation still requires its normal confirmation and object permissions. A client cannot act as an administrator via MCP. Insufficient OAuth scope for a known tool returns HTTP 403 with a WWW-Authenticate scope challenge. Legacy tool schemas and errors remain compatible.

Profile → AI integrations lists OAuth grants alongside manual credentials; the expiry column for OAuth shows the refresh-family deadline. Revoking a grant invalidates both current access and refresh tokens. A client can also POST /oauth/revoke with token, client_id and applicable client authentication. No tokens or passwords are stored in plaintext. Password changes do not automatically revoke existing grants; revoke them explicitly.

## Compatibility status

The implementation targets MCP 2025-11-25 authorization discovery and OAuth authorization code + PKCE with DCR. This is not an independent OAuth conformance or security certification. CIMD, OIDC, client credentials, device flow and other grant types are not implemented. Direct connection to ChatGPT web, its plugin configuration and any tool-level authentication UI are separate integration work. Use a DCR-capable client; existing Bearer clients do not need changes.

Smoke test: tests/smoke/mcp_oauth.py on an isolated installation with APP_URL, MCP_TEST_USER, MCP_TEST_PASSWORD, MCP_FOREIGN_USER, MCP_FOREIGN_PASSWORD and MCP_WRITE_TOKEN set. This uses disposable accounts and must never be pointed at production.

References:
- https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization
- https://developers.openai.com/plugins/build/auth
